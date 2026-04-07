from fastapi import APIRouter, HTTPException, Depends, Request
from pydantic import BaseModel, Field
from typing import List
from sqlalchemy.orm import Session

from backend.app.services.cache_service import get_cached_embedding, get_cached_results, set_cached_embedding, set_cached_results, invalidate_client_results
from backend.app.services.database import get_db
from backend.app.services.domain_auth_service import DomainAuthorizer
from backend.app.services.embedder import embed_query, embed_document
from backend.app.services.license_service import validate_license_key, check_search_quota, increment_search_count, log_search, increment_ingest_count
from backend.app.services.llm_key_service import decrypt_key
from backend.app.services.product_service import build_product_text, extract_payload
from backend.app.services.qdrant_service import search_products, upsert_product, delete_product, get_client_product_count
from backend.app.services.rerank_service import extract_keywords, filter_and_rerank
import time

router = APIRouter()


class MagentoSearchRequest(BaseModel):
    license_key: str
    query: str
    limit: int = 10
    llm_api_key_encrypted: str = None


class MagentoProduct(BaseModel):
    product_id: str
    name: str
    categories: str = ""
    tags: str = ""
    description: str = ""
    short_description: str = ""
    price: float = 0
    regular_price: float = 0
    sale_price: float = 0
    currency: str = ""
    currency_symbol: str = ""
    on_sale: bool = False
    permalink: str = ""
    image_url: str = ""
    stock_status: str = "instock"
    average_rating: float = 0
    attributes: list = Field(default_factory=list)


class MagentoSyncBatchRequest(BaseModel):
    license_key: str
    products: List[MagentoProduct]
    batch_number: int = 1
    total_batches: int = 1
    llm_api_key_encrypted: str = None


class MagentoDeleteRequest(BaseModel):
    license_key: str
    product_id: str


@router.post("/magento/search")
async def magento_search(req: MagentoSearchRequest, request: Request, db: Session = Depends(get_db)):
    start_time = time.time()

    if not req.query.strip():
        raise HTTPException(status_code=400, detail="Query cannot be empty")

    try:
        license_data = validate_license_key(req.license_key, db)
    except ValueError as exc:
        raise HTTPException(status_code=403, detail=str(exc))

    client_id = license_data["client_id"]
    domain = license_data["domain"]

    authorizer = DomainAuthorizer(db)
    authorizer.validate_request(request, license_data)

    if not check_search_quota(db, client_id, license_data["search_limit"]):
        raise HTTPException(status_code=429, detail="Monthly search limit reached. Please upgrade your plan.")

    query = req.query.strip().lower()

    cached_results = get_cached_results(f"{client_id}_{domain}", query)
    if cached_results is not None:
        response_time = int((time.time() - start_time) * 1000)
        increment_search_count(db, client_id)
        log_search(db, client_id, query, len(cached_results), response_time, cached=True)
        return {"query": req.query, "count": len(cached_results), "cached": True, "results": cached_results}

    query_vector = get_cached_embedding(query)
    if query_vector is None:
        embedding_api_key = None
        if req.llm_api_key_encrypted:
            try:
                embedding_api_key = decrypt_key(req.llm_api_key_encrypted, req.license_key)
            except Exception:
                embedding_api_key = None
        query_vector = embed_query(query, embedding_api_key, client_id)
        set_cached_embedding(query, query_vector)

    fetch_limit = req.limit * 5
    results = search_products(
        client_id=client_id,
        domain=domain,
        query_vector=query_vector,
        limit=fetch_limit,
        min_price=None,
        max_price=None,
        only_in_stock=False,
    )

    keywords = extract_keywords(req.query)
    results = filter_and_rerank(results, keywords, req.limit)

    set_cached_results(f"{client_id}_{domain}", query, results)

    response_time = int((time.time() - start_time) * 1000)
    increment_search_count(db, client_id)
    log_search(db, client_id, query, len(results), response_time, cached=False)

    return {"query": req.query, "count": len(results), "cached": False, "results": results}


@router.post("/magento/sync/batch")
def magento_sync_batch(req: MagentoSyncBatchRequest, request: Request, db: Session = Depends(get_db)):
    try:
        license_data = validate_license_key(req.license_key, db)
    except ValueError as exc:
        raise HTTPException(status_code=403, detail=str(exc))

    client_id = license_data["client_id"]
    domain = license_data["domain"]

    authorizer = DomainAuthorizer(db)
    authorizer.validate_request(request, license_data)

    current_count = get_client_product_count(client_id, domain)
    incoming_count = len(req.products)
    if current_count + incoming_count > license_data["product_limit"]:
        raise HTTPException(
            status_code=400,
            detail=f"Product limit exceeded. Current: {current_count}, Incoming: {incoming_count}, Limit: {license_data['product_limit']}",
        )

    embedding_api_key = None
    if req.llm_api_key_encrypted:
        try:
            embedding_api_key = decrypt_key(req.llm_api_key_encrypted, req.license_key)
        except Exception:
            embedding_api_key = None

    success_ids = []
    failed_ids = []

    for product in req.products:
        try:
            p = product.model_dump()
            text = build_product_text(p)
            vector = embed_document(text, embedding_api_key, client_id)
            payload = extract_payload(p)
            payload["embedded_text"] = text
            upsert_product(client_id, domain, product.product_id, vector, payload)
            success_ids.append(product.product_id)
        except Exception:
            failed_ids.append(product.product_id)

    if success_ids:
        increment_ingest_count(db, client_id, count=len(success_ids))

    if req.batch_number >= req.total_batches:
        invalidate_client_results(client_id)

    return {
        "success_count": len(success_ids),
        "failed_count": len(failed_ids),
        "failed_ids": failed_ids,
        "batch_number": req.batch_number,
        "total_batches": req.total_batches,
        "is_last_batch": req.batch_number >= req.total_batches,
    }


@router.post("/magento/sync/delete")
def magento_sync_delete(req: MagentoDeleteRequest, request: Request, db: Session = Depends(get_db)):
    try:
        license_data = validate_license_key(req.license_key, db)
    except ValueError as exc:
        raise HTTPException(status_code=403, detail=str(exc))

    authorizer = DomainAuthorizer(db)
    authorizer.validate_request(request, license_data)

    delete_product(license_data["client_id"], req.product_id)
    invalidate_client_results(license_data["client_id"])

    return {"deleted": True, "product_id": req.product_id}
