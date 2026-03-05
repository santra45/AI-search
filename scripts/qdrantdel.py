from qdrant_client import QdrantClient

client = QdrantClient(host="localhost", port=6333)

collection_name = "local_shared_products"

client.delete_collection(collection_name)
print("Collection deleted.")