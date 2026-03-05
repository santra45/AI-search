import sys
import os
sys.path.append(os.path.join(os.path.dirname(__file__), '..'))

from dotenv import load_dotenv
load_dotenv()

from qdrant_client import QdrantClient
from qdrant_client.models import Filter, FieldCondition, MatchValue, SetPayload

qdrant     = QdrantClient(host="localhost", port=6333)
COLL       = "local_shared_products"
OLD_ID     = "client_local_dev"
NEW_ID     = input("Paste your new client_id (UUID from license key): ").strip()

# Get all points with old client_id
results = qdrant.scroll(
    collection_name=COLL,
    scroll_filter=Filter(
        must=[FieldCondition(
            key="client_id",
            match=MatchValue(value=OLD_ID)
        )]
    ),
    limit=500,
    with_payload=True
)

points = results[0]
print(f"Found {len(points)} points with client_id='{OLD_ID}'")

if not points:
    print("Nothing to update")
    exit()

# Update client_id in payload for all points
point_ids = [p.id for p in points]

qdrant.set_payload(
    collection_name=COLL,
    payload={"client_id": NEW_ID},
    points=point_ids
)

print(f"✅ Updated {len(point_ids)} points to client_id='{NEW_ID}'")

# Verify
sample = qdrant.retrieve(
    collection_name=COLL,
    ids=[point_ids[0]],
    with_payload=True
)
print(f"✅ Verified: {sample[0].payload.get('client_id')}")