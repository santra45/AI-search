import hmac
import hashlib
import base64
import os
import re
import json
from fastapi import APIRouter, Request, HTTPException, Header, Query, Depends
from sqlalchemy.orm import Session
from sqlalchemy import text
from backend.app.services.database import get_db
from typing import Optional
from backend.app.services.embedder import embed_document
from backend.app.services.qdrant_service import upsert_product, delete_product
from backend.app.services.cache_service import invalidate_client_results
from backend.app.services.license_service import increment_ingest_count

router    = APIRouter()
WC_SECRET = os.getenv("WC_WEBHOOK_SECRET", "mysecretkey123")


# ─── Helpers ──────────────────────────────────────────────────────────────────

def verify_signature(body: bytes, signature: str) -> bool:
    mac      = hmac.new(WC_SECRET.encode("utf-8"), body, hashlib.sha256)
    expected = base64.b64encode(mac.digest()).decode("utf-8")
    return hmac.compare_digest(expected, signature)


def strip_html(text: str) -> str:
    return re.sub(r"<[^>]+>", " ", text).strip()


def build_product_text(p: dict) -> str:
    parts = []

    name = p.get("name", "")
    if name:
        parts.append(f"Product: {name}")

    name_lower = name.lower()
    cats_list  = [c["name"] for c in p.get("categories", [])]
    cats_lower = " ".join(cats_list).lower()
    combined   = name_lower + " " + cats_lower

    signals = []
    if any(w in combined for w in ["women", "woman", "female", "ladies", "girl", "girls"]):
        signals.append("for women ladies girls female")
    if any(w in combined for w in ["men", "man", "male", "gents", "boys", "boy"]):
        if "women" not in combined and "female" not in combined:
            signals.append("for men gents boys male")
    if any(w in combined for w in ["kids", "kid", "child", "children", "toddler",
                                    "baby", "infant", "junior", "little"]):
        signals.append("for kids children toddler baby")
    if signals:
        parts.append(f"Suitable for: {' | '.join(signals)}")

    if cats_list:
        parts.append(f"Category: {', '.join(cats_list)}")

    tags = [t["name"] for t in p.get("tags", [])]
    if tags:
        parts.append(f"Tags: {', '.join(tags)}")

    SIZE_MAP = {
        "XS": "XS extra small", "S": "S small", "M": "M medium",
        "L": "L large", "XL": "XL extra large", "XXL": "XXL double extra large",
        "3XL": "3XL triple extra large",
        "2-3Y": "age 2 to 3 years toddler", "4-5Y": "age 4 to 5 years",
        "6-7Y": "age 6 to 7 years", "8-9Y": "age 8 to 9 years",
        "10-11Y": "age 10 to 11 years", "12-13Y": "age 12 to 13 years",
    }

    for attr in p.get("attributes", []):
        attr_name = attr.get("name", "")
        options   = attr.get("options", [])
        if not attr_name or not options:
            continue
        if attr_name == "Size":
            expanded = [SIZE_MAP.get(s, s) for s in options]
            parts.append(f"Available sizes: {', '.join(expanded)}")
        elif attr_name == "Color":
            generic = list({c.split()[-1] for c in options})
            parts.append(f"Colors: {', '.join(options)}")
            parts.append(f"Color family: {', '.join(generic)}")
        else:
            parts.append(f"{attr_name}: {', '.join(options)}")

    short = strip_html(p.get("short_description", ""))
    if short:
        parts.append(f"Summary: {short}")

    desc = strip_html(p.get("description", ""))[:600]
    if desc:
        parts.append(f"Description: {desc}")

    price = p.get("price", "")
    if price:
        parts.append(f"Price: ₹{price}")

    return "\n".join(parts)


def extract_payload(p: dict) -> dict:
    cats  = [c["name"] for c in p.get("categories", [])]
    tags  = [t["name"] for t in p.get("tags", [])]
    imgs  = p.get("images", [])
    price = p.get("price", "0") or "0"

    attr_map = {}
    for attr in p.get("attributes", []):
        key = attr.get("name", "").lower().replace(" ", "_")
        val = ", ".join(attr.get("options", []))
        if key and val:
            attr_map[key] = val

    return {
        "name":           p.get("name", ""),
        "permalink":      p.get("permalink", ""),
        "price":          float(price),
        "regular_price":  float(p.get("regular_price") or price or "0"),
        "sale_price":     float(p.get("sale_price") or "0"),
        "on_sale":        bool(p.get("on_sale", False)),
        "categories":     ", ".join(cats),
        "tags":           ", ".join(tags),
        "image_url":      imgs[0]["src"] if imgs else "",
        "stock_status":   p.get("stock_status", "instock"),
        "average_rating": float(p.get("average_rating") or "0"),
        **attr_map
    }


def process_upsert(product: dict, action: str, client_id: str) -> dict:
    """
    Shared logic for created + updated webhooks.
    Both do the same thing — embed and upsert.
    """
    product_id = str(product["id"])

    # Skip variations
    if product.get("type") == "variation":
        return {"status": "skipped", "reason": "variation"}

    # If unpublished/trashed → remove from Qdrant
    if product.get("status") != "publish":
        delete_product(client_id, product_id)
        invalidate_client_results(client_id)
        print(f"🗑️  Webhook [{action}]: removed product {product_id}")
        return {"status": "removed", "product_id": product_id}

    # Embed and store
    text    = build_product_text(product)
    vector  = embed_document(text)
    payload = extract_payload(product)
    payload["embedded_text"] = text

    upsert_product(client_id, product_id, vector, payload)
    invalidate_client_results(client_id) 
    increment_ingest_count(db, client_id, count=1)
    print(f"✅ Webhook [{action}]: indexed {product_id} - {product.get('name')}")

    return {"status": action, "product_id": product_id}

async def parse_webhook_body(request: Request) -> tuple:
    """
    Read body once. Return (raw_bytes, parsed_json_or_none).
    Handles WooCommerce ping (form-encoded) and real webhook (JSON).
    """
    body         = await request.body()
    content_type = request.headers.get("content-type", "")

    # WooCommerce ping — not JSON, just acknowledge it
    if "application/json" not in content_type:
        return body, None

    if not body:
        return body, None

    try:
        return body, json.loads(body)
    except json.JSONDecodeError as e:
        raise HTTPException(status_code=400, detail=f"Invalid JSON: {e}")


# ─── Endpoints ────────────────────────────────────────────────────────────────

@router.post("/webhook/product-created")
async def product_created(
    request: Request,
    client_id: str = Query(...),   # ← reads ?client_id= from URL
    db: Session = Depends(get_db),
    x_wc_webhook_signature: Optional[str] = Header(None)
):
     # Verify client exists and is active
    client = db.execute(text("""
        SELECT id FROM clients
        WHERE id = :client_id AND is_active = 1
    """), {"client_id": client_id}).fetchone()

    if not client:
        raise HTTPException(status_code=403, detail="Invalid client")
    
    body, product = await parse_webhook_body(request)

    # Ping request — just acknowledge
    if product is None:
        return {"status": "ok", "reason": "ping"}

    if x_wc_webhook_signature:
        if not verify_signature(body, x_wc_webhook_signature):
            raise HTTPException(status_code=401, detail="Invalid signature")

    try:
        return process_upsert(
            product=product,
            action="created",
            client_id=client_id
        )
    except Exception as e:
        print(f"❌ Webhook [created] error: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@router.post("/webhook/product-updated")
async def product_updated(
    request: Request,
    client_id: str = Query(...),   # ← reads ?client_id= from URL
    db: Session = Depends(get_db),
    x_wc_webhook_signature: Optional[str] = Header(None)
):
    # Verify client exists and is active
    client = db.execute(text("""
        SELECT id FROM clients
        WHERE id = :client_id AND is_active = 1
    """), {"client_id": client_id}).fetchone()

    if not client:
        raise HTTPException(status_code=403, detail="Invalid client")
    
    body, product = await parse_webhook_body(request)

    if product is None:
        return {"status": "ok", "reason": "ping"}

    if x_wc_webhook_signature:
        if not verify_signature(body, x_wc_webhook_signature):
            raise HTTPException(status_code=401, detail="Invalid signature")

    try:
        return process_upsert(
            product=product,
            action="updated",
            client_id=client_id
        )
    except Exception as e:
        print(f"❌ Webhook [updated] error: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@router.post("/webhook/product-deleted")
async def product_deleted(
    request: Request,
    client_id: str = Query(...),   # ← reads ?client_id= from URL
    db: Session = Depends(get_db),
    x_wc_webhook_signature: Optional[str] = Header(None)
):
    # Verify client exists and is active
    client = db.execute(text("""
        SELECT id FROM clients
        WHERE id = :client_id AND is_active = 1
    """), {"client_id": client_id}).fetchone()

    if not client:
        raise HTTPException(status_code=403, detail="Invalid client")
    
    body, product = await parse_webhook_body(request)

    if product is None:
        return {"status": "ok", "reason": "ping"}

    if x_wc_webhook_signature:
        if not verify_signature(body, x_wc_webhook_signature):
            raise HTTPException(status_code=401, detail="Invalid signature")

    product_id = str(product.get("id", ""))
    if not product_id:
        return {"status": "skipped", "reason": "no product id"}

    try:
        delete_product(client_id, product_id)
        invalidate_client_results(client_id)
        print(f"🗑️  Webhook [deleted]: removed product {product_id}")
        return {"status": "deleted", "product_id": product_id}
    except Exception as e:
        print(f"❌ Webhook [deleted] error: {e}")
        raise HTTPException(status_code=500, detail=str(e))
