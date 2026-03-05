from qdrant_client import QdrantClient
from qdrant_client.models import Filter, FieldCondition, MatchValue, PointStruct
from backend.app.config import QDRANT_HOST, QDRANT_PORT, QDRANT_COLL
import uuid

qdrant = QdrantClient(host=QDRANT_HOST, port=QDRANT_PORT)


def search_products(client_id: str, query_vector: list, limit: int = 10) -> list:
    result = qdrant.query_points(
        collection_name=QDRANT_COLL,
        query=query_vector,
        query_filter=Filter(
            must=[
                FieldCondition(
                    key="client_id",
                    match=MatchValue(value=client_id)
                )
            ]
        ),
        limit=limit,
        with_payload=True
    )
    hits = result.points 

    return [
        {
            "product_id":  hit.payload.get("product_id"),
            "name":        hit.payload.get("name"),
            "price":       hit.payload.get("price"),
            "permalink":   hit.payload.get("permalink"),
            "image_url":   hit.payload.get("image_url"),
            "stock_status":hit.payload.get("stock_status"),
            "categories":  hit.payload.get("categories"),
            "score":       round(hit.score, 4)
        }
        for hit in hits
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