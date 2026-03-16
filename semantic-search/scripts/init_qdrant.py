from qdrant_client import QdrantClient
from qdrant_client.models import Distance, VectorParams, PayloadSchemaType

# Connect to your local Qdrant
qdrant = QdrantClient(host="localhost", port=6333)

# Check existing collections
existing = [c.name for c in qdrant.get_collections().collections]
print(f"Existing collections: {existing}")

# Create shared_products collection if it doesn't exist
if "local_shared_products" not in existing:
    qdrant.create_collection(
        collection_name="local_shared_products",
        vectors_config=VectorParams(
            size=3072,           # Gemini embedding-001 = 3072 dimensions
            distance=Distance.COSINE
        )
    )
    print("✅ Collection 'shared_products' created")
else:
    print("⚠️  Collection 'shared_products' already exists, skipping")

# Create index on client_id for fast filtering
qdrant.create_payload_index(
    collection_name="local_shared_products",
    field_name="client_id",
    field_schema=PayloadSchemaType.KEYWORD
)
print("✅ Index on client_id created")

# Verify
collection_info = qdrant.get_collection("local_shared_products")
print(f"\nCollection info:")
print(f"  Vectors size:     {collection_info.config.params.vectors.size}")
print(f"  Distance metric:  {collection_info.config.params.vectors.distance}")
print(f"  Points count:     {collection_info.points_count}")
print("\n✅ Qdrant is ready")