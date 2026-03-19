from qdrant_client import QdrantClient
from qdrant_client.models import Distance, VectorParams, PayloadSchemaType

# Connect to Qdrant
qdrant = QdrantClient(host="localhost", port=6333)

# Check existing collections
existing = [c.name for c in qdrant.get_collections().collections]
print(f"Existing collections: {existing}")

COLLECTION_NAME = "local_shared_products_BGE"

# Create collection if it doesn't exist
if COLLECTION_NAME not in existing:
    qdrant.create_collection(
        collection_name=COLLECTION_NAME,
        vectors_config=VectorParams(
            size=768,  # bge-base-en-v1.5 embedding dimension
            distance=Distance.COSINE
        )
    )
    print(f"✅ Collection '{COLLECTION_NAME}' created")
else:
    print(f"⚠️ Collection '{COLLECTION_NAME}' already exists")

# Create payload index for client filtering
qdrant.create_payload_index(
    collection_name=COLLECTION_NAME,
    field_name="client_id",
    field_schema=PayloadSchemaType.KEYWORD
)

print("✅ Index on client_id created")

# Verify collection
collection_info = qdrant.get_collection(COLLECTION_NAME)

print("\nCollection info:")
print(f"  Vector size:      {collection_info.config.params.vectors.size}")
print(f"  Distance metric:  {collection_info.config.params.vectors.distance}")
print(f"  Points count:     {collection_info.points_count}")

print("\n✅ Qdrant ready for BGE embeddings")