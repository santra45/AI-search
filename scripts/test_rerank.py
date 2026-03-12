"""
test_rerank.py
--------------
Standalone tests for rerank_service — no FastAPI server required.
Run from the project root:

    cd c:\\xampp\\htdocs\\semantic-search
    python scripts/test_rerank.py
"""

import sys
import os

# Allow importing the backend package from project root
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from backend.app.services.rerank_service import extract_keywords, filter_and_rerank

PASS = "\033[92m✓ PASS\033[0m"
FAIL = "\033[91m✗ FAIL\033[0m"

failures = 0


def check(label: str, condition: bool):
    global failures
    status = PASS if condition else FAIL
    print(f"  {status}  {label}")
    if not condition:
        failures += 1


# ─── Mock Products ─────────────────────────────────────────────────────────────

MENS_TSHIRT = {
    "product_id": "1",
    "name": "Men's Classic Cotton T-Shirt",
    "categories": "T-Shirts, Men's Clothing",
    "tags": "men, cotton, casual",
    "gender": "Men",
    "color": "Black",
    "score": 0.85,
    "price": 499,
    "permalink": "http://example.com/mens-tshirt",
    "image_url": "",
    "stock_status": "instock",
}

WOMENS_TSHIRT = {
    "product_id": "2",
    "name": "Women's Floral T-Shirt",
    "categories": "T-Shirts, Women's Clothing",
    "tags": "women, girls, floral",
    "gender": "Women",
    "color": "Pink",
    "score": 0.82,
    "price": 449,
    "permalink": "http://example.com/womens-tshirt",
    "image_url": "",
    "stock_status": "instock",
}

UNISEX_TSHIRT = {
    "product_id": "3",
    "name": "Unisex Plain White T-Shirt",
    "categories": "T-Shirts",
    "tags": "unisex, plain",
    "gender": "Unisex",
    "color": "White",
    "score": 0.79,
    "price": 349,
    "permalink": "http://example.com/unisex-tshirt",
    "image_url": "",
    "stock_status": "instock",
}

RED_SHOES_MEN = {
    "product_id": "4",
    "name": "Men's Red Running Shoes",
    "categories": "Shoes, Men's Footwear",
    "tags": "men, running, red",
    "gender": "Men",
    "color": "Red",
    "score": 0.88,
    "price": 1999,
    "permalink": "http://example.com/red-shoes-men",
    "image_url": "",
    "stock_status": "instock",
}

BLUE_SHOES_WOMEN = {
    "product_id": "5",
    "name": "Women's Blue Sneakers",
    "categories": "Shoes, Women's Footwear",
    "tags": "women, sneakers, blue",
    "gender": "Women",
    "color": "Blue",
    "score": 0.84,
    "price": 1799,
    "permalink": "http://example.com/blue-shoes-women",
    "image_url": "",
    "stock_status": "instock",
}

COTTON_KURTA = {
    "product_id": "6",
    "name": "Men's Cotton Kurta",
    "categories": "Ethnic Wear, Men's Clothing",
    "tags": "kurta, cotton, ethnic",
    "gender": "Men",
    "material": "Cotton",
    "score": 0.76,
    "price": 799,
    "permalink": "http://example.com/cotton-kurta",
    "image_url": "",
    "stock_status": "instock",
}


# ─── Test: extract_keywords ────────────────────────────────────────────────────

print("\n── extract_keywords() ──────────────────────────────────────────────────")

kw = extract_keywords("i want tshirt i am men")
print(f"  Input : 'i want tshirt i am men'")
print(f"  Output: {kw}")
check("gender=Men detected",        kw["gender"] == "Men")
check("no false color detected",    kw["colors"] == [])
check("no false material detected", kw["materials"] == [])
check("'tshirt' in tokens",         "tshirt" in kw["tokens"])

print()
kw2 = extract_keywords("red shoes for women")
print(f"  Input : 'red shoes for women'")
print(f"  Output: {kw2}")
check("gender=Women detected",  kw2["gender"] == "Women")
check("red in colors",          "red" in kw2["colors"])

print()
kw3 = extract_keywords("i want tshirt")
print(f"  Input : 'i want tshirt'")
print(f"  Output: {kw3}")
check("no gender detected",      kw3["gender"] is None)

print()
kw4 = extract_keywords("cotton kurta")
print(f"  Input : 'cotton kurta'")
print(f"  Output: {kw4}")
check("material=cotton detected", "cotton" in kw4["materials"])
check("no gender",                kw4["gender"] is None)

print()
kw5 = extract_keywords("shirt for men and women")
print(f"  Input : 'shirt for men and women'")
print(f"  Output: {kw5}")
check("conflicting genders → no gender filter", kw5["gender"] is None)


# ─── Test: filter_and_rerank — gender blocking ────────────────────────────────

print("\n── filter_and_rerank() — gender blocking ───────────────────────────────")

products = [MENS_TSHIRT.copy(), WOMENS_TSHIRT.copy(), UNISEX_TSHIRT.copy()]
kw = extract_keywords("i want tshirt i am men")
result = filter_and_rerank(products, kw, original_limit=10)

result_ids = [r["product_id"] for r in result]
print(f"  Query   : 'i want tshirt i am men'")
print(f"  Returned: {[r['name'] for r in result]}")
check("men's tshirt included",    "1" in result_ids)
check("women's tshirt BLOCKED",   "2" not in result_ids)
check("unisex tshirt included",   "3" in result_ids)


# ─── Test: filter_and_rerank — no gender → no blocking ───────────────────────

print("\n── filter_and_rerank() — no gender keyword ─────────────────────────────")

products2 = [MENS_TSHIRT.copy(), WOMENS_TSHIRT.copy(), UNISEX_TSHIRT.copy()]
kw2 = extract_keywords("i want tshirt")
result2 = filter_and_rerank(products2, kw2, original_limit=10)

result_ids2 = [r["product_id"] for r in result2]
print(f"  Query   : 'i want tshirt'")
print(f"  Returned: {[r['name'] for r in result2]}")
check("all 3 products returned",  set(result_ids2) == {"1", "2", "3"})


# ─── Test: filter_and_rerank — soft score (color) ────────────────────────────

print("\n── filter_and_rerank() — soft color score ───────────────────────────────")

products3 = [RED_SHOES_MEN.copy(), BLUE_SHOES_WOMEN.copy()]
kw3 = extract_keywords("red shoes for men")
result3 = filter_and_rerank(products3, kw3, original_limit=10)

print(f"  Query   : 'red shoes for men'")
print(f"  Returned: {[r['name'] for r in result3]}")
check("red men's shoes returned",      any(r["product_id"] == "4" for r in result3))
check("blue women's shoes BLOCKED",    not any(r["product_id"] == "5" for r in result3))
if result3:
    check("red shoes ranked first",    result3[0]["product_id"] == "4")


# ─── Test: filter_and_rerank — graceful fallback ─────────────────────────────

print("\n── filter_and_rerank() — graceful fallback ─────────────────────────────")

# All men's products vs a women's query — everything should be blocked,
# fallback returns top-3 by vector score
products4 = [MENS_TSHIRT.copy(), COTTON_KURTA.copy()]
kw4 = extract_keywords("i am woman looking for something")
result4 = filter_and_rerank(products4, kw4, original_limit=10)

print(f"  Query   : 'i am woman looking for something'")
print(f"  Returned: {[r['name'] for r in result4]}")
check("fallback triggers — returns >0 results", len(result4) > 0)


# ─── Test: limit respected ────────────────────────────────────────────────────

print("\n── filter_and_rerank() — limit respected ────────────────────────────────")

products5 = [MENS_TSHIRT.copy(), UNISEX_TSHIRT.copy(), COTTON_KURTA.copy()]
kw5 = extract_keywords("men clothing")
result5 = filter_and_rerank(products5, kw5, original_limit=2)
print(f"  Returned count: {len(result5)} (limit=2)")
check("result count ≤ limit=2", len(result5) <= 2)


# ─── Summary ──────────────────────────────────────────────────────────────────

print(f"\n{'─'*60}")
if failures == 0:
    print("\033[92m  All tests passed! ✓\033[0m")
else:
    print(f"\033[91m  {failures} test(s) FAILED\033[0m")

sys.exit(failures)
