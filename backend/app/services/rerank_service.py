import re
from typing import Optional

# ─── Sets are faster than lists, Master. Learn it. ──────────────────────────

GENDER_QUERY_SYNONYMS: dict[str, set[str]] = {
    "Men": {"men", "man", "male", "males", "mens", "gents", "gentleman", "gentlemen", "boy", "boys", "masculine", "husband", "father", "him", "his", "he"},
    "Women": {"women", "woman", "female", "females", "womens", "ladies", "lady", "girl", "girls", "feminine", "wife", "mother", "her", "hers", "she"}
}

GENDER_PRODUCT_WORDS: dict[str, set[str]] = {
    "Men": {"men", "mens", "man", "male", "males", "gents", "boys", "masculine", "boyfriend", "husband", "son", "brother"},
    "Women": {"women", "womens", "woman", "female", "females", "ladies", "lady", "girls", "feminine", "girlfriend", "wife", "maternity", "nursing"}
}

GENDER_OPPOSITES: dict[str, set[str]] = {
    "Men": GENDER_PRODUCT_WORDS["Women"],
    "Women": GENDER_PRODUCT_WORDS["Men"]
}

COLORS: set[str] = {
    "red", "blue", "green", "yellow", "black", "white", "grey", "gray", "pink", "purple", "violet", "orange", "brown", "maroon", "beige", "cream", "navy", "cyan", "magenta", "gold", "silver", "teal", "indigo", "khaki", "olive", "coral", "turquoise"
}

MATERIALS: set[str] = {
    "cotton", "polyester", "silk", "wool", "linen", "denim", "leather", "nylon", "rayon", "spandex", "fleece", "velvet", "satin", "chiffon", "georgette", "lycra", "jersey", "crepe", "net", "lace", "tweed", "cashmere", "suede", "canvas", "synthetic", "blended"
}

# ─── Expanded Stopwords ───────────────────────────────────────────────────────
STOPWORDS: set[str] = {
    "i", "want", "need", "looking", "for", "a", "an", "the", "some", "any", "please", "can", "you", "show", "me", "find", "get", "buy", "am", "is", "are", "my", "something", "good", "nice", "best", "cheap", "expensive", "new", "old", "like", "love", "prefer", "give", "suggest", "recommend", "with", "without", "under", "over", "size", "fit", "color", "material", "made", "of", "wear", "casual", "formal", "style"
}

UNISEX_MARKERS: set[str] = {"unisex", "kids", "children", "child", "toddler", "baby", "infant"}

# Compile this once so we aren't wasting cycles 
WORD_REGEX = re.compile(r"\b\w+\b")

# ─── Keyword Extraction ────────────────────────────────────────────────────────

def extract_keywords(query: str) -> dict:
    words = WORD_REGEX.findall(query.lower())
    
    detected_gender: Optional[str] = None
    detected_colors: set[str] = set()
    detected_materials: set[str] = set()
    meaningful_tokens: set[str] = set()

    for word in words:
        # 1. Gender check
        matched_gender = next((g for g, syns in GENDER_QUERY_SYNONYMS.items() if word in syns), None)
        if matched_gender:
            if detected_gender and detected_gender != matched_gender:
                detected_gender = None  # Conflict -> wipe it
            else:
                detected_gender = matched_gender
            continue 
            
        # 2. Colors & Materials & Stopwords
        if word in COLORS:
            detected_colors.add(word)
            meaningful_tokens.add(word)
        elif word in MATERIALS:
            detected_materials.add(word)
            meaningful_tokens.add(word)
        elif word not in STOPWORDS and len(word) > 2:
            meaningful_tokens.add(word)

    return {
        "gender": detected_gender,
        "colors": list(detected_colors),
        "materials": list(detected_materials),
        "tokens": list(meaningful_tokens),
    }

# ─── Product Text Builder ─────────────────────────────────────────────────────

def _build_product_tokens(product: dict) -> set[str]:
    """
    Returns a set of words for lightning-fast intersections. ⚡️
    """
    parts = [str(product.get("name", "")), str(product.get("categories", "")), str(product.get("tags", ""))]
    
    skip_keys = {"name", "categories", "tags", "product_id", "price", "permalink", "image_url", "stock_status", "score", "currency", "regular_price", "sale_price", "on_sale", "average_rating", "sku", "brand", "client_id"}
    
    for key, val in product.items():
        if key not in skip_keys and isinstance(val, (str, int, float)):
            parts.append(str(val))
            
    full_text = " ".join(parts).lower()
    return set(WORD_REGEX.findall(full_text))

# ─── Scorers and Blockers ─────────────────────────────────────────────────────

def _soft_score(product_tokens: set[str], keywords: dict) -> float:
    soft_targets = set(keywords["colors"] + keywords["materials"])
    if not soft_targets:
        return 0.0
    hits = len(soft_targets & product_tokens) # Set intersection magic ✨
    return hits / len(soft_targets)

def _is_wrong_gender(product_tokens: set[str], required_gender: str) -> bool:
    if UNISEX_MARKERS & product_tokens:
        return False

    opposite_words = GENDER_OPPOSITES.get(required_gender, set())
    required_words = GENDER_PRODUCT_WORDS.get(required_gender, set())

    has_opposite = bool(opposite_words & product_tokens)
    has_required = bool(required_words & product_tokens)

    return has_opposite and not has_required

# ─── Main Filter + Re-rank ────────────────────────────────────────────────────

def filter_and_rerank(results: list, keywords: dict, original_limit: int) -> list:
    if not results or not any(keywords.values()):
        return results[:original_limit]

    required_gender = keywords.get("gender")
    blocked: list[dict] = []
    passed: list[dict] = []

    for product in results:
        product_tokens = _build_product_tokens(product)

        if required_gender and _is_wrong_gender(product_tokens, required_gender):
            blocked.append(product)
            continue

        soft = _soft_score(product_tokens, keywords)
        qdrant_score = product.get("score", 0.0)

        final_score = (qdrant_score * 0.7) + (soft * 0.3)
        product["score"] = round(final_score, 4)
        passed.append(product)

    if not passed:
        fallback = sorted(results, key=lambda r: r.get("score", 0), reverse=True)[:3]
        return fallback

    passed.sort(key=lambda r: r.get("score", 0), reverse=True)
    return passed[:original_limit]