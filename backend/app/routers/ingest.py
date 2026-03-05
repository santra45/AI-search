from fastapi import APIRouter, HTTPException, Depends
from pydantic import BaseModel
from typing import List
from sqlalchemy.orm import Session
from backend.app.services.embedder import embed_document
from backend.app.services.qdrant_service import upsert_product, delete_product
from backend.app.services.license_service import validate_license_key
from backend.app.services.database import get_db
from backend.app.services.license_service import increment_ingest_count
import re

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
    if any(w in combined for w in ["kids", "child", "toddler", "baby", "little"]):
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


class Product(BaseModel):
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


class IngestRequest(BaseModel):
    license_key: str       # ← license key instead of client_id
    products: List[Product]


class DeleteRequest(BaseModel):
    license_key: str       # ← license key instead of client_id
    product_id: str


@router.post("/ingest")
def ingest(req: IngestRequest, db: Session = Depends(get_db)):
    # Validate license
    try:
        license_data = validate_license_key(req.license_key, db)
    except ValueError as e:
        raise HTTPException(status_code=403, detail=str(e))

    client_id = license_data["client_id"]

    # Check product limit
    if len(req.products) > license_data["product_limit"]:
        raise HTTPException(
            status_code=400,
            detail=f"Product limit is {license_data['product_limit']}"
        )

    success = []
    failed  = []

    for product in req.products:
        try:
            text    = build_product_text(product.model_dump())
            vector  = embed_document(text)
            payload = product.model_dump()
            payload["embedded_text"] = text
            upsert_product(client_id, product.product_id, vector, payload)
            success.append(product.product_id)
        except Exception as e:
            failed.append({"product_id": product.product_id, "error": str(e)})
    
    # Count all successfully embedded products as ingestions
    if success:
        increment_ingest_count(db, client_id, count=len(success))

    return {
        "client_id":     client_id,
        "success_count": len(success),
        "failed_count":  len(failed),
        "failed":        failed
    }


@router.post("/ingest/delete")
def delete(req: DeleteRequest, db: Session = Depends(get_db)):
    try:
        license_data = validate_license_key(req.license_key, db)
    except ValueError as e:
        raise HTTPException(status_code=403, detail=str(e))

    try:
        delete_product(license_data["client_id"], req.product_id)
        return {"deleted": True, "product_id": req.product_id}
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))