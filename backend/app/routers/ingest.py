from fastapi import APIRouter, HTTPException, Depends
from pydantic import BaseModel
from typing import List
from sqlalchemy.orm import Session
from backend.app.services.embedder import embed_document
from backend.app.services.qdrant_service import upsert_product, delete_product
from backend.app.services.license_service import validate_license_key, increment_ingest_count
from backend.app.services.database import get_db
from backend.app.services.product_service import build_product_text, extract_payload  # ← import

router = APIRouter()


class Product(BaseModel):
    product_id:        str
    name:              str
    categories:        str = ""
    tags:              str = ""
    description:       str = ""
    short_description: str = ""
    price:             float = 0
    regular_price:     float = 0
    sale_price:        float = 0
    on_sale:           bool = False
    permalink:         str = ""
    image_url:         str = ""
    stock_status:      str = "instock"
    average_rating:    float = 0
    attributes:        list = []   # ← add this


class IngestRequest(BaseModel):
    license_key: str
    products:    List[Product]


class DeleteRequest(BaseModel):
    license_key: str
    product_id:  str


@router.post("/ingest")
def ingest(req: IngestRequest, db: Session = Depends(get_db)):
    try:
        license_data = validate_license_key(req.license_key, db)
    except ValueError as e:
        raise HTTPException(status_code=403, detail=str(e))

    client_id = license_data["client_id"]

    if len(req.products) > license_data["product_limit"]:
        raise HTTPException(
            status_code=400,
            detail=f"Product limit is {license_data['product_limit']}"
        )

    success = []
    failed  = []

    for product in req.products:
        try:
            p       = product.model_dump()
            text    = build_product_text(p)
            vector  = embed_document(text)
            payload = extract_payload(p)
            payload["embedded_text"] = text
            upsert_product(client_id, product.product_id, vector, payload)
            success.append(product.product_id)
        except Exception as e:
            failed.append({"product_id": product.product_id, "error": str(e)})

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