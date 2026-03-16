import chromadb
from chromadb.config import Settings
from qdrant_client import QdrantClient
from qdrant_client.models import PointStruct
import uuid
import time

# ─── Config ────────────────────────────────────────────────────────────────────
CHROMA_PATH     = "./chroma_db_local"
CHROMA_COLL     = "woo_products"
QDRANT_HOST     = "localhost"
QDRANT_PORT     = 6333
QDRANT_COLL     = "local_shared_products"
CLIENT_ID       = "client_local_dev"   # for now, one client = your local store
BATCH_SIZE      = 100                  # insert into Qdrant in batches

# ─── Connect ───────────────────────────────────────────────────────────────────
print("🔌 Connecting to ChromaDB...")
chroma = chromadb.PersistentClient(
    path=CHROMA_PATH,
    settings=Settings(anonymized_telemetry=False)
)

print("🔌 Connecting to Qdrant...")
qdrant = QdrantClient(host=QDRANT_HOST, port=QDRANT_PORT)

# ─── Load from ChromaDB ────────────────────────────────────────────────────────
print(f"\n📦 Loading collection '{CHROMA_COLL}' from ChromaDB...")
chroma_coll = chroma.get_collection(CHROMA_COLL)
total = chroma_coll.count()
print(f"   Found {total} products in ChromaDB")

if total == 0:
    print("❌ ChromaDB collection is empty. Nothing to migrate.")
    exit(1)

# Fetch everything — ids, embeddings, and metadata
print("   Fetching all vectors and metadata...")
result = chroma_coll.get(
    include=["embeddings", "metadatas", "documents"],
    limit=total  
)

chroma_ids  = result["ids"]
embeddings  = result["embeddings"]
metadatas   = result["metadatas"]
documents   = result["documents"]

print(f"✅ Loaded {len(chroma_ids)} records from ChromaDB")

# ─── Migrate to Qdrant ─────────────────────────────────────────────────────────
print(f"\n🚀 Migrating to Qdrant collection '{QDRANT_COLL}'...")

migrated  = 0
failed    = 0
points_batch = []

for i, (chroma_id, vector, meta, doc) in enumerate(zip(chroma_ids, embeddings, metadatas, documents)):

    try:
        # Build a deterministic UUID from client_id + product_id
        # Same product always gets same UUID — safe to re-run migration
        point_uuid = str(uuid.uuid5(
            uuid.NAMESPACE_DNS,
            f"{CLIENT_ID}-{chroma_id}"
        ))

        # Build payload — everything you want to store alongside the vector
        payload = {
            "client_id":      CLIENT_ID,
            "product_id":     str(chroma_id),
            "embedded_text":  doc,         # the full text that was embedded

            # Metadata fields from your build_metadata() function
            "name":           meta.get("name", ""),
            "permalink":      meta.get("permalink", ""),
            "price":          float(meta.get("price", 0)),
            "regular_price":  float(meta.get("regular_price", 0)),
            "sale_price":     float(meta.get("sale_price", 0)),
            "on_sale":        bool(meta.get("on_sale", False)),
            "categories":     meta.get("categories", ""),
            "tags":           meta.get("tags", ""),
            "image_url":      meta.get("image_url", ""),
            "stock_status":   meta.get("stock_status", "instock"),
            "average_rating": float(meta.get("average_rating", 0)),
        }

        # Add any extra attribute fields (color, size, etc.)
        known_keys = {
            "product_id", "name", "permalink", "price", "regular_price",
            "sale_price", "on_sale", "categories", "tags", "image_url",
            "image_alt", "stock_status", "average_rating"
        }
        for key, val in meta.items():
            if key not in known_keys:
                payload[key] = val  # e.g. "color", "size", custom attributes

        points_batch.append(
            PointStruct(
                id=point_uuid,
                vector=list(vector),
                payload=payload
            )
        )

        # Insert in batches of BATCH_SIZE
        if len(points_batch) >= BATCH_SIZE:
            qdrant.upsert(
                collection_name=QDRANT_COLL,
                points=points_batch
            )
            migrated += len(points_batch)
            print(f"   ✅ Inserted batch — {migrated}/{total} done")
            points_batch = []
            time.sleep(0.2)  # small pause between batches

    except Exception as e:
        print(f"   ❌ Failed on product {chroma_id}: {e}")
        failed += 1

# Insert any remaining points
if points_batch:
    qdrant.upsert(
        collection_name=QDRANT_COLL,
        points=points_batch
    )
    migrated += len(points_batch)
    print(f"   ✅ Inserted final batch — {migrated}/{total} done")

# ─── Verify ────────────────────────────────────────────────────────────────────
print(f"\n{'='*50}")
print(f"Migration complete")
print(f"  ChromaDB had:   {total} products")
print(f"  Migrated:       {migrated} products")
print(f"  Failed:         {failed} products")

qdrant_count = qdrant.get_collection(QDRANT_COLL).points_count
print(f"  Qdrant now has: {qdrant_count} points")

if qdrant_count == total:
    print(f"\n✅ Perfect match — all {total} products are in Qdrant")
else:
    print(f"\n⚠️  Count mismatch — check failed products above")

print(f"{'='*50}")