import os
import sys
from dotenv import load_dotenv
from woocommerce import API

load_dotenv()

# =========================
# Config
# =========================

WC_URL    = os.getenv("WC_LOCAL_URL")
WC_KEY    = os.getenv("WC_CONSUMER_KEY_LOCAL")
WC_SECRET = os.getenv("WC_CONSUMER_SECRET_LOCAL")
WH_SECRET = os.getenv("WC_WEBHOOK_SECRET", "mysecretkey123")

API_URL = "http://127.0.0.1:8000"

CLIENT_ID = input("Paste your client UUID: ").strip()

# =========================
# Validation
# =========================

if not WC_URL or not WC_KEY or not WC_SECRET:
    print("❌ Missing WooCommerce credentials in .env")
    sys.exit(1)

# =========================
# WooCommerce Connection
# =========================

wcapi = API(
    url=WC_URL,
    consumer_key=WC_KEY,
    consumer_secret=WC_SECRET,
    version="wc/v3",
    timeout=30
)

BASE_ENDPOINT = "webhooks"

# =========================
# Webhooks to create
# =========================

webhooks = [
    {
        "name": "Semantic Search — Product Created",
        "topic": "product.created",
        "delivery_url": f"{API_URL}/api/webhook/product-created?client_id={CLIENT_ID}",
        "secret": WH_SECRET,
        "status": "active",
    },
    {
        "name": "Semantic Search — Product Updated",
        "topic": "product.updated",
        "delivery_url": f"{API_URL}/api/webhook/product-updated?client_id={CLIENT_ID}",
        "secret": WH_SECRET,
        "status": "active",
    },
    {
        "name": "Semantic Search — Product Deleted",
        "topic": "product.deleted",
        "delivery_url": f"{API_URL}/api/webhook/product-deleted?client_id={CLIENT_ID}",
        "secret": WH_SECRET,
        "status": "active",
    },
]

print(f"\n🚀 Registering WooCommerce webhooks for client {CLIENT_ID}\n")

# =========================
# Fetch existing webhooks
# =========================

print("🔎 Fetching existing webhooks...")

response = wcapi.get(BASE_ENDPOINT)

if response.status_code != 200:
    print("❌ Failed to fetch webhooks")
    print(response.text)
    sys.exit(1)

existing = response.json()

# =========================
# Delete old Semantic Search hooks
# =========================

deleted = 0

for wh in existing:

    name = wh.get("name", "")
    wh_id = wh.get("id")

    if "Semantic Search" in name:

        r = wcapi.delete(f"{BASE_ENDPOINT}/{wh_id}", params={"force": True})

        if r.status_code in [200, 204]:
            print(f"🗑 Deleted: {name}")
            deleted += 1
        else:
            print(f"⚠️ Could not delete {name}")

print(f"\n✔ Removed {deleted} previous Semantic Search hooks\n")

# =========================
# Register new hooks
# =========================

print("📡 Registering new webhooks...\n")

created = 0

for hook in webhooks:

    r = wcapi.post(BASE_ENDPOINT, hook)

    if r.status_code in [200, 201]:

        print(f"✅ {hook['name']}")
        print(f"   → {hook['delivery_url']}\n")
        created += 1

    else:

        print(f"❌ Failed: {hook['name']}")
        print(r.text[:200])

print("=================================")
print(f"🎉 Done! {created}/{len(webhooks)} webhooks registered.")
print("=================================")