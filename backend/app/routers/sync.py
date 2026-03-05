from fastapi import APIRouter, HTTPException, Depends
from pydantic import BaseModel
from typing import List, Optional
from sqlalchemy.orm import Session
from backend.app.services.embedder import embed_document
from backend.app.services.qdrant_service import upsert_product
from backend.app.services.license_service import (
    validate_license_key,
    increment_ingest_count
)
from backend.app.services.database import get_db
from backend.app.services.cache_service import invalidate_client_results
import re
import time

router = APIRouter()


def strip_html(text: str) -> str:
    return re.sub(r"<[^>]+>", " ", text).strip()


def build_product_text(p: dict) -> str:
    parts = []

    name = p.get("name", "")
    if name:
        parts.append(f"Product: {name}")

    name_lower = name.lower()
    cats_lower = p.get("categories", "").lower()
    combined   = name_lower + " " + cats_lower

    signals = []
    if any(w in combined for w in ["women", "woman", "female", "ladies", "girl"]):
        signals.append("for women ladies girls female")
    if any(w in combined for w in ["men", "man", "male", "gents", "boys", "boy"]):
        if "women" not in combined:
            signals.append("for men gents boys male")
    if any(w in combined for w in ["kids", "kid", "child", "toddler", "baby", "little"]):
        signals.append("for kids children toddler baby")
    if signals:
        parts.append(f"Suitable for: {' | '.join(signals)}")

    if p.get("categories"):
        parts.append(f"Category: {p['categories']}")
    if p.get("tags"):
        parts.append(f"Tags: {p['tags']}")
    if p.get("short_description"):
        parts.append(f"Summary: {strip_html(p['short_description'])}")
    if p.get("description"):
        parts.append(f"Description: {strip_html(p['description'])[:600]}")
    if p.get("price"):
        parts.append(f"Price: ₹{p['price']}")

    return "\n".join(parts)


class SyncProduct(BaseModel):
    product_id: str
    name: str
    categories: str = ""
    tags: str = ""
    description: str = ""
    short_description: str = ""
    price: float = 0
    regular_price: float = 0
    sale_price: float = 0
    on_sale: bool = False
    permalink: str = ""
    image_url: str = ""
    stock_status: str = "instock"
    average_rating: float = 0


class SyncBatchRequest(BaseModel):
    license_key: str
    products: List[SyncProduct]
    batch_number: int = 1
    total_batches: int = 1


class SyncBatchResponse(BaseModel):
    success_count: int
    failed_count: int
    failed_ids: List[str]
    batch_number: int
    total_batches: int
    is_last_batch: bool


@router.post("/sync/batch", response_model=SyncBatchResponse)
def sync_batch(req: SyncBatchRequest, db: Session = Depends(get_db)):
    """
    Process one batch of products.
    Plugin calls this repeatedly until all batches are done.
    """

    # Validate license
    try:
        license_data = validate_license_key(req.license_key, db)
    except ValueError as e:
        raise HTTPException(status_code=403, detail=str(e))

    client_id = license_data["client_id"]

    success_ids = []
    failed_ids  = []

    for product in req.products:
        try:
            p    = product.model_dump()
            text = build_product_text(p)

            # Small delay between embeddings to respect Gemini rate limits
            if len(success_ids) > 0:
                time.sleep(0.5)

            vector  = embed_document(text)
            payload = p.copy()
            payload["embedded_text"] = text

            upsert_product(client_id, product.product_id, vector, payload)
            success_ids.append(product.product_id)

        except Exception as e:
            print(f"❌ Sync failed for product {product.product_id}: {e}")
            failed_ids.append(product.product_id)

    # Track ingestion count
    if success_ids:
        increment_ingest_count(db, client_id, count=len(success_ids))

    # Invalidate cache after last batch
    is_last_batch = req.batch_number >= req.total_batches
    if is_last_batch:
        invalidate_client_results(client_id)
        print(f"✅ Sync complete for client {client_id}")

    return SyncBatchResponse(
        success_count=len(success_ids),
        failed_count=len(failed_ids),
        failed_ids=failed_ids,
        batch_number=req.batch_number,
        total_batches=req.total_batches,
        is_last_batch=is_last_batch
    )


@router.get("/sync/status")
def sync_status(license_key: str, db: Session = Depends(get_db)):
    """
    Returns how many products are currently indexed for this client.
    Plugin uses this to show current index size.
    """
    try:
        license_data = validate_license_key(license_key, db)
    except ValueError as e:
        raise HTTPException(status_code=403, detail=str(e))

    client_id = license_data["client_id"]

    from app.services.qdrant_service import get_client_product_count
    count = get_client_product_count(client_id)

    return {
        "client_id":      client_id,
        "indexed_count":  count,
        "plan":           license_data["plan"],
        "product_limit":  license_data["product_limit"]
    }