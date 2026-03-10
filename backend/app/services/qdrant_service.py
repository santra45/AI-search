from qdrant_client import QdrantClient
from qdrant_client.models import Filter, FieldCondition, MatchValue, MatchAny, Range
from backend.app.config import QDRANT_HOST, QDRANT_PORT, QDRANT_COLL
import uuid

qdrant = QdrantClient(host=QDRANT_HOST, port=QDRANT_PORT)


from qdrant_client.models import Filter, FieldCondition, MatchValue, MatchAny, Range

def search_products(
    client_id: str, 
    query_vector: list, 
    limit: int = 10,
    min_price: float = None,
    max_price: float = None,
    only_in_stock: bool = False
) -> list:
    
    # 1. Build dynamic filters
    must_conditions = [
        FieldCondition(key="client_id", match=MatchValue(value=client_id))
    ]

    # Add Price Range if provided
    if min_price is not None or max_price is not None:
        must_conditions.append(
            FieldCondition(
                key="price",
                range=Range(
                    gte=min_price, # Greater than or equal
                    lte=max_price  # Less than or equal
                )
            )
        )

    # Add Stock Filter
    if only_in_stock:
        must_conditions.append(
            FieldCondition(key="stock_status", match=MatchValue(value="instock"))
        )

    # 2. Execute Query
    result = qdrant.query_points(
        collection_name=QDRANT_COLL,
        query=query_vector,
        query_filter=Filter(must=must_conditions),
        limit=limit,
        with_payload=True
    )

    # 3. Format results
    return [
        {
            "product_id":   hit.payload.get("product_id"),
            "name":         hit.payload.get("name"),
            "price":        hit.payload.get("price"),
            "permalink":    hit.payload.get("permalink"),
            "image_url":    hit.payload.get("image_url"),
            "stock_status": hit.payload.get("stock_status"),
            "categories":   hit.payload.get("categories"),
            "score":        round(hit.score, 4)
        }
        for hit in result.points
    ]


def upsert_product(client_id: str, product_id: str, vector: list, payload: dict):
    point_uuid = str(uuid.uuid5(
        uuid.NAMESPACE_DNS,
        f"{client_id}-{product_id}"
    ))

    payload["client_id"]  = client_id
    payload["product_id"] = str(product_id)

    qdrant.upsert(
        collection_name=QDRANT_COLL,
        points=[
            PointStruct(
                id=point_uuid,
                vector=vector,
                payload=payload
            )
        ]
    )


def delete_product(client_id: str, product_id: str):
    point_uuid = str(uuid.uuid5(
        uuid.NAMESPACE_DNS,
        f"{client_id}-{product_id}"
    ))

    qdrant.delete(
        collection_name=QDRANT_COLL,
        points_selector=[point_uuid]
    )

def get_client_product_count(client_id: str) -> int:
    """Count how many products are indexed for a client."""
    result = qdrant.count(
        collection_name=QDRANT_COLL,
        count_filter=Filter(
            must=[
                FieldCondition(
                    key="client_id",
                    match=MatchValue(value=client_id)
                )
            ]
        )
    )
    return result.count