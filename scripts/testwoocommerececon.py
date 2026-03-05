import os
import requests
from requests.auth import HTTPBasicAuth
from dotenv import load_dotenv

load_dotenv()

WC_URL = os.getenv("WC_LOCAL_URL")
WC_KEY = os.getenv("WC_CONSUMER_KEY_LOCAL")
WC_SECRET = os.getenv("WC_CONSUMER_SECRET_LOCAL")

if not WC_URL or not WC_KEY or not WC_SECRET:
    print("❌ Missing WooCommerce credentials in .env")
    exit()

auth = HTTPBasicAuth(WC_KEY, WC_SECRET)

print("\n🔌 Testing WooCommerce REST API connection...\n")

# -----------------------------------
# Test 1: Basic API access
# -----------------------------------

try:
    r = requests.get(
        f"{WC_URL}/wp-json/wc/v3",
        auth=auth,
        timeout=15,
        verify=False
    )

    if r.status_code == 200:
        print("✅ REST API reachable")
    else:
        print("❌ REST API not reachable")
        print(r.text)
        exit()

except requests.exceptions.RequestException as e:
    print("❌ Connection error:", e)
    exit()

# -----------------------------------
# Test 2: Fetch store products
# -----------------------------------

print("\n📦 Fetching products...")

r = requests.get(
    f"{WC_URL}/wp-json/wc/v3/products?per_page=5",
    auth=auth,
    timeout=15,
    verify=False
)

if r.status_code != 200:
    print("❌ Failed to fetch products")
    print(r.text)
    exit()

products = r.json()

print(f"✅ Found {len(products)} products\n")

for p in products:
    print(f"• {p['name']} (ID: {p['id']})")

# -----------------------------------
# Test 3: Fetch webhooks
# -----------------------------------

print("\n🔗 Checking webhook permissions...")

r = requests.get(
    f"{WC_URL}/wp-json/wc/v3/webhooks",
    auth=auth,
    timeout=15,
    verify=False
)

if r.status_code == 200:
    hooks = r.json()
    print(f"✅ Webhook access OK ({len(hooks)} existing)")
else:
    print("⚠️ Webhook endpoint access failed")
    print(r.text[:200])

print("\n🎉 WooCommerce API connection successful!")