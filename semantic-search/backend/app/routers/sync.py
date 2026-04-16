from fastapi import APIRouter, HTTPException, Depends, Request, Header, Query
from pydantic import BaseModel, Field
from typing import List, Optional
from sqlalchemy.orm import Session
from backend.app.services.embedder import embed_document
from backend.app.services.qdrant_service import upsert_product, upsert_page, upsert_post, get_client_product_count
from backend.app.services.license_service import validate_license_key, increment_ingest_count, extract_license_key_from_authorization
from backend.app.services.database import get_db
from backend.app.services.cache_service import invalidate_client_results
from backend.app.services.product_service import build_product_text, extract_payload, build_page_text, extract_page_payload, build_post_text, extract_post_payload
from backend.app.services.domain_auth_service import DomainAuthorizer
from backend.app.services.llm_key_service import decrypt_key
import time
from urllib.parse import urlparse

router = APIRouter()


class SyncProduct(BaseModel):
    product_id:        str
    name:              str
    categories:        str = ""
    tags:              str = ""
    description:       str = ""
    short_description: str = ""
    price:             float = 0
    regular_price:     float = 0
    sale_price:        float = 0
    currency:          str = ""
    currency_symbol:   str = ""
    on_sale:           bool = False
    permalink:         str = ""
    image_url:         str = ""
    stock_status:      str = "instock"
    average_rating:    float = 0
    attributes:        list = Field(default_factory=list)


class SyncPage(BaseModel):
    page_id:      str
    title:        str
    content:      str = ""
    excerpt:      str = ""
    permalink:    str = ""
    author:       str = ""
    date:         str = ""
    status:       str = "publish"


class SyncPost(BaseModel):
    post_id:      str
    title:        str
    content:      str = ""
    excerpt:      str = ""
    permalink:    str = ""
    author:       str = ""
    date:         str = ""
    categories:   str = ""
    tags:         str = ""
    status:       str = "publish"


class SyncBatchRequest(BaseModel):
    license_key:   str
    products:      List[SyncProduct] = Field(default_factory=list)
    pages:         List[SyncPage] = Field(default_factory=list)
    posts:         List[SyncPost] = Field(default_factory=list)
    batch_number:  int = 1
    total_batches: int = 1
    llm_api_key_encrypted: str = None
    content_type: str = "product"  # 'product', 'page', 'post', or 'all'


class SyncBatchResponse(BaseModel):
    success_count: int
    failed_count:  int
    failed_ids:    List[str]
    batch_number:  int
    total_batches: int
    is_last_batch: bool


@router.post("/sync/batch", response_model=SyncBatchResponse)
def sync_batch(req: SyncBatchRequest, request: Request, db: Session = Depends(get_db)):
    try:
        license_data = validate_license_key(req.license_key, db)
    except ValueError as e:
        raise HTTPException(status_code=403, detail=str(e))

    client_id   = license_data["client_id"]
    domain      = license_data["domain"]
    license_key = req.license_key
    
    # Decrypt embedding API key if provided
    if req.llm_api_key_encrypted:
        try:
            embedding_api_key = decrypt_key(req.llm_api_key_encrypted, license_key)   
        except Exception as e:
            print(f" Embedding API key decryption failed: {e}")
            embedding_api_key = None
    else:
        print(f"Embedding API key not provided, using default")
        embedding_api_key = None
    
    # CRITICAL: Enforce secure domain authorization
    authorizer = DomainAuthorizer(db)
    authorizer.validate_request(request, license_data)
    
    # CRITICAL: Check total indexed count + incoming count against plan limit
    current_count = get_client_product_count(client_id, domain)
    incoming_count = len(req.products) + len(req.pages) + len(req.posts)
    total_after_ingest = current_count + incoming_count
    
    if total_after_ingest > license_data["product_limit"]:
        raise HTTPException(
            status_code=400,
            detail=f"Content limit exceeded. Current: {current_count}, Incoming: {incoming_count}, Limit: {license_data['product_limit']}"
        )
    
    success_ids = []
    failed_ids  = []

    print(f"Syncing batch {req.batch_number}/{req.total_batches} with {len(req.products)} products, {len(req.pages)} pages, {len(req.posts)} posts")

    # Sync products
    for product in req.products:
        try:
            p = product.model_dump()
            text    = build_product_text(p)
            vector  = embed_document(text, embedding_api_key, client_id)
            payload = extract_payload(p)
            payload["embedded_text"] = text
            upsert_product(client_id, domain, product.product_id, vector, payload)
            success_ids.append(product.product_id)
        except Exception as e:
            print(f"❌ Sync failed for product {product.product_id}: {e}")
            failed_ids.append(product.product_id)

    # Sync pages
    for page in req.pages:
        try:
            p = page.model_dump()
            text    = build_page_text(p)
            vector  = embed_document(text, embedding_api_key, client_id)
            payload = extract_page_payload(p)
            payload["embedded_text"] = text
            upsert_page(client_id, domain, page.page_id, vector, payload)
            success_ids.append(f"page-{page.page_id}")
        except Exception as e:
            print(f"❌ Sync failed for page {page.page_id}: {e}")
            failed_ids.append(f"page-{page.page_id}")

    # Sync posts
    for post in req.posts:
        try:
            p = post.model_dump()
            text    = build_post_text(p)
            vector  = embed_document(text, embedding_api_key, client_id)
            payload = extract_post_payload(p)
            payload["embedded_text"] = text
            upsert_post(client_id, domain, post.post_id, vector, payload)
            success_ids.append(f"post-{post.post_id}")
        except Exception as e:
            print(f"❌ Sync failed for post {post.post_id}: {e}")
            failed_ids.append(f"post-{post.post_id}")

    if success_ids:
        increment_ingest_count(db, client_id, count=len(success_ids))

    is_last_batch = req.batch_number >= req.total_batches
    if is_last_batch:
        invalidate_client_results(client_id)

    return SyncBatchResponse(
        success_count=len(success_ids),
        failed_count=len(failed_ids),
        failed_ids=failed_ids,
        batch_number=req.batch_number,
        total_batches=req.total_batches,
        is_last_batch=is_last_batch
    )


@router.post("/sync/cancel")
def cancel_sync(
    request: Request,
    authorization: Optional[str] = Header(None),
    license_key: Optional[str] = Query(None),
    db: Session = Depends(get_db)
):
    token = extract_license_key_from_authorization(authorization) or license_key
    if not token:
        raise HTTPException(status_code=401, detail="Missing Authorization header")

    try:
        license_data = validate_license_key(token, db)
    except ValueError as e:
        raise HTTPException(status_code=403, detail=str(e))

    # CRITICAL: Enforce secure domain authorization
    authorizer = DomainAuthorizer(db)
    authorizer.validate_request(request, license_data)
    
    # In a real implementation, you might want to:
    # 1. Set a flag in database/cache to indicate cancellation
    # 2. Signal any running batch processes to stop
    # 3. Clean up any temporary state
    
    # For now, we'll just return success since the WordPress plugin
    # handles the actual cancellation by updating its local state
    
    return {
        "success": True,
        "message": "Sync cancellation request received"
    }


@router.get("/sync/status")
def sync_status(
    request: Request,
    authorization: Optional[str] = Header(None),
    license_key: Optional[str] = Query(None),
    db: Session = Depends(get_db)
):
    token = extract_license_key_from_authorization(authorization) or license_key
    if not token:
        raise HTTPException(status_code=401, detail="Missing Authorization header")

    try:
        license_data = validate_license_key(token, db)
    except ValueError as e:
        raise HTTPException(status_code=403, detail=str(e))

    # CRITICAL: Enforce secure domain authorization
    authorizer = DomainAuthorizer(db)
    authorizer.validate_request(request, license_data)
    
    count = get_client_product_count(license_data["client_id"], license_data["domain"])

    return {
        "client_id":     license_data["client_id"],
        "indexed_count": count,
        "plan":          license_data["plan"],
        "product_limit": license_data["product_limit"]
    }