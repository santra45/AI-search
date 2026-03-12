import os
import google.generativeai as genai
from qdrant_client import QdrantClient
from qdrant_client.models import Filter, FieldCondition, MatchValue
from dotenv import load_dotenv

load_dotenv()

# ─── Config ────────────────────────────────────────────────────────────────────
GEMINI_KEY  = os.getenv("GEMINI_API_KEY")
QDRANT_HOST = "localhost"
QDRANT_PORT = 6333
QDRANT_COLL = "local_shared_products"
CLIENT_ID   = "9bd2cf13-f6d9-44e5-80e8-331544a1e8cb"

# ─── Init ──────────────────────────────────────────────────────────────────────
genai.configure(api_key=GEMINI_KEY)
qdrant = QdrantClient(host=QDRANT_HOST, port=QDRANT_PORT)

# ─── Search Function ───────────────────────────────────────────────────────────
def search(query: str, top_k: int = 5):

    print(f"\n🔍 Query: '{query}'")
    print("-" * 50)

    # Step 1 — embed the query
    result = genai.embed_content(
        model="gemini-embedding-001",
        content=query,
        task_type="retrieval_query",
    )

    query_vector = result["embedding"]

    # Step 2 — search Qdrant filtered to this client
    hits = qdrant.query_points(
        collection_name=QDRANT_COLL,
        query=query_vector,
        query_filter=Filter(
            must=[
                FieldCondition(
                    key="client_id",
                    match=MatchValue(value=CLIENT_ID)
                )
            ]
        ),
        limit=top_k,
        with_payload=True
    ).points   # ⚠️ important — results are inside .points

    # Step 3 — print results
    if not hits:
        print("   No results found")
        return

    for i, hit in enumerate(hits):
        p = hit.payload
        print(f"  #{i+1} [{hit.score:.4f}] {p.get('name', 'Unknown')}")
        print(f"        Price:      ₹{p.get('price', 'N/A')}")
        print(f"        Categories: {p.get('categories', 'N/A')}")
        print(f"        Stock:      {p.get('stock_status', 'N/A')}")
        print(f"        URL:        {p.get('permalink', 'N/A')}")
        print()
        

# ─── Run Test Queries ──────────────────────────────────────────────────────────
if __name__ == "__main__":
    test_queries = [
        "warm jacket for winter",
        "something for a 5 year old boy",
        "blue dress for a party",
        "comfortable clothes for summer",
        "gift for a toddler girl",
    ]

    for query in test_queries:
        search(query)
        print("=" * 50)