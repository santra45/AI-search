import os
import google.generativeai as genai
from qdrant_client import QdrantClient
from qdrant_client.models import Filter, FieldCondition, MatchValue
from dotenv import load_dotenv

load_dotenv()

genai.configure(api_key=os.getenv("GEMINI_API_KEY"))
qdrant = QdrantClient(host="localhost", port=6333)

CLIENT_ID   = "client_local_dev"
QDRANT_COLL = "local_shared_products"

def search(query: str, limit: int = 8):
    result = genai.embed_content(
        model="gemini-embedding-001",
        content=query,
        task_type="retrieval_query",
    )
    query_vector = result["embedding"]

    result = qdrant.query_points(
        collection_name=QDRANT_COLL,
        query=query_vector,
        query_filter=Filter(
            must=[FieldCondition(
                key="client_id",
                match=MatchValue(value=CLIENT_ID)
            )]
        ),
        limit=limit,
        with_payload=True
    )
    hits = result.points

    return hits


# ─── Test Queries ──────────────────────────────────────────────────────────────
queries = [
    "hoodies for women",
    "blue dress for a party",
    "something for a 5 year old boy",
    "warm jacket for winter",
    "comfortable summer outfit",
    "gift for a toddler girl",
    "men's formal wear",
    "kids school uniform",
]

all_scores = []

for query in queries:
    hits = search(query)
    scores = [round(h.score, 4) for h in hits]
    all_scores.extend(scores)

    print(f"\n🔍 '{query}'")
    print(f"   {'Score':<8} {'Relevant?':<12} Product")
    print(f"   {'─'*55}")

    for hit in hits:
        score = round(hit.score, 4)
        name  = hit.payload.get("name", "")
        # You manually mark Y or N as you read the output
        print(f"   {score:<8} {'?':<12} {name}")

# ─── Score Distribution ────────────────────────────────────────────────────────
all_scores.sort(reverse=True)
print(f"\n\n{'='*55}")
print(f"Score Distribution Across All Queries")
print(f"{'='*55}")
print(f"  Highest score: {max(all_scores):.4f}")
print(f"  Lowest score:  {min(all_scores):.4f}")
print(f"  Average score: {sum(all_scores)/len(all_scores):.4f}")

# Show score buckets
buckets = {
    "0.72+":       [s for s in all_scores if s >= 0.72],
    "0.70–0.72":   [s for s in all_scores if 0.70 <= s < 0.72],
    "0.68–0.70":   [s for s in all_scores if 0.68 <= s < 0.70],
    "0.65–0.68":   [s for s in all_scores if 0.65 <= s < 0.68],
    "below 0.65":  [s for s in all_scores if s < 0.65],
}

print(f"\n  {'Bucket':<15} {'Count':<8} {'Scores'}")
print(f"  {'─'*55}")
for bucket, scores in buckets.items():
    print(f"  {bucket:<15} {len(scores):<8} {scores[:5]}{'...' if len(scores)>5 else ''}")