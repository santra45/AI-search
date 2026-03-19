"""
rerank_service.py
─────────────────
Post-search keyword filtering & re-ranking.

Pipeline:
  raw customer query
        │
        ▼
  extract_keywords()  ← pure Python dict/regex, NO LLM
        │  returns gender, colors, materials, price constraints,
        │  occasions, seasons, and category signals
        ▼
  filter_and_rerank() ← runs on Qdrant result list
        │  HARD BLOCKS (product removed entirely):
        │    • Wrong gender
        │    • Price above max_price or below min_price
        │    • Wrong season  (e.g. "summer" query → no winter coats)
        │    • Wrong occasion (e.g. "office" query → no nightwear/beachwear)
        │
        │  SOFT BOOSTS (adjust score up):
        │    • Color match
        │    • Material match
        │    • Season match (bonus if product text also says "summer")
        │    • Occasion match
        │    • Category token match
        ▼
  cleaned, re-scored results (sliced to original limit)

Graceful fallback: if blocking removes ALL products,
the top-3 by original Qdrant vector score are returned
so the UI never shows an empty result set.
"""

import re
from typing import Optional

# ─── Gender Dictionaries ───────────────────────────────────────────────────────

GENDER_QUERY_SYNONYMS: dict[str, list[str]] = {
    "Men": [
        "men", "man", "male", "males", "mens", "gents", "gentleman",
        "gentlemen", "masculine", "husband", "father", "him", "his", "he",
    ],
    "Women": [
        "women", "woman", "female", "females", "womens", "ladies", "lady",
        "feminine", "wife", "mother", "her", "hers", "she",
    ],
    "Kids": [
        "kid", "kids", "child", "children", "baby", "babies",
        "toddler", "toddlers", "infant", "infants",
        "boy", "boys", "girl", "girls", "youth", "junior",
    ],
}

GENDER_PRODUCT_WORDS: dict[str, list[str]] = {
    "Men": [
        "men", "mens", "man", "male", "males", "gents",
        "masculine", "boyfriend", "husband",
    ],
    "Women": [
        "women", "womens", "woman", "female", "females",
        "ladies", "lady", "feminine", "girlfriend", "wife",
        "maternity", "nursing",
    ],
    "Kids": [
        "kid", "kids", "child", "children", "baby", "babies",
        "toddler", "toddlers", "infant", "infants",
        "boy", "boys", "girl", "girls", "youth", "junior",
        "school", "playwear",
    ],
}

GENDER_OPPOSITES: dict[str, list[str]] = {
    gender: [
        word
        for other_gender, words in GENDER_PRODUCT_WORDS.items()
        if other_gender != gender
        for word in words
    ]
    for gender in GENDER_PRODUCT_WORDS
}

# ─── Color / Material ──────────────────────────────────────────────────────────

COLORS = [
    "red", "blue", "green", "yellow", "black", "white", "grey", "gray",
    "pink", "purple", "violet", "orange", "brown", "maroon", "beige",
    "cream", "navy", "cyan", "magenta", "gold", "silver", "teal",
    "indigo", "khaki", "olive", "coral", "turquoise",
]

MATERIALS = [
    "cotton", "polyester", "silk", "wool", "linen", "denim", "leather",
    "nylon", "rayon", "spandex", "fleece", "velvet", "satin", "chiffon",
    "georgette", "lycra", "jersey", "crepe", "net", "lace", "tweed",
    "cashmere", "suede", "canvas", "synthetic", "blended",
]

# ─── Price Signal Words ────────────────────────────────────────────────────────
# Maps query words → approximate max price multiplier or absolute nudge.
# We extract hard numbers from the query as the primary constraint;
# these words act as a secondary soft block when no number is present.

BUDGET_QUERY_WORDS = [
    "cheap", "budget", "affordable", "inexpensive", "economical",
    "low cost", "low-cost", "pocket friendly", "pocket-friendly", "value",
]

PREMIUM_QUERY_WORDS = [
    "premium", "luxury", "high-end", "expensive", "designer",
    "exclusive", "branded", "top quality",
]

# ─── Season Dictionaries ───────────────────────────────────────────────────────

SEASON_QUERY_SYNONYMS: dict[str, list[str]] = {
    "summer": ["summer", "hot", "humid", "beach", "tropical", "warm weather", "heat"],
    "winter": ["winter", "cold", "freezing", "snow", "snowy", "chilly", "warm", "cosy", "cozy"],
    "monsoon": ["monsoon", "rain", "rainy", "rains", "wet", "drizzle"],
    "spring": ["spring", "mild", "breezy"],
    "autumn": ["autumn", "fall", "transitional"],
}

# Words in product text that signal a specific season
SEASON_PRODUCT_WORDS: dict[str, list[str]] = {
    "summer": [
        "summer", "lightweight", "light weight", "breathable", "linen", "cotton",
        "sleeveless", "short sleeve", "shorts", "tank", "floral", "tropical",
        "breezy", "airy", "cool", "mesh", "georgette", "chiffon",
    ],
    "winter": [
        "winter", "warm", "woollen", "wool", "thermal", "fleece", "jacket",
        "coat", "sweater", "sweatshirt", "hoodie", "knit", "padded",
        "quilted", "insulated", "fur", "thick", "heavy",
    ],
    "monsoon": [
        "monsoon", "rain", "waterproof", "water resistant", "quick dry",
        "quick-dry", "poncho", "windproof",
    ],
    "spring": ["spring", "light", "pastel", "floral", "breathable"],
    "autumn": ["autumn", "fall", "layering", "layered", "transitional"],
}

# Products whose text strongly suggests a DIFFERENT (anti) season
# e.g. if user asks for "summer" clothes, block products with these winter words
SEASON_ANTI_WORDS: dict[str, list[str]] = {
    "summer": [
        "winter", "woollen", "wool", "thermal", "fleece", "padded",
        "quilted", "insulated", "fur", "heavy coat", "parka", "puffer",
    ],
    "winter": [
        "sleeveless", "tank top", "beachwear", "swimwear", "shorts",
        "tropical", "mesh", "breathable",
    ],
    "monsoon": [],   # monsoon is broadly tolerant
    "spring": [],
    "autumn": [],
}

# ─── Occasion Dictionaries ─────────────────────────────────────────────────────

OCCASION_QUERY_SYNONYMS: dict[str, list[str]] = {
    "office": [
        "office", "work", "formal", "professional", "corporate",
        "business", "meeting", "interview", "workplace",
    ],
    "casual": [
        "casual", "everyday", "daily", "regular", "comfortable",
        "relaxed", "lounge", "chill", "weekend",
    ],
    "party": [
        "party", "night out", "club", "disco", "festive", "celebration",
        "birthday", "cocktail", "event",
    ],
    "ethnic": [
        "ethnic", "traditional", "festival", "puja", "wedding", "diwali",
        "eid", "haldi", "mehendi", "sangeet", "kurta", "saree", "lehenga",
        "salwar", "sherwani",
    ],
    "sport": [
        "gym", "workout", "sport", "sports", "athletic", "running",
        "yoga", "training", "fitness", "exercise", "active",
    ],
    "sleep": [
        "sleep", "night", "nightwear", "pyjama", "pajama",
        "lounge wear", "loungewear", "robe",
    ],
    "beach": [
        "beach", "pool", "swim", "swimwear", "resort", "vacation",
        "holiday", "surf",
    ],
}

# Words in product text that signal a specific occasion
OCCASION_PRODUCT_WORDS: dict[str, list[str]] = {
    "office": [
        "office", "formal", "professional", "corporate", "blazer",
        "trousers", "shirt", "business", "pencil skirt", "ponte",
    ],
    "casual": [
        "casual", "everyday", "relaxed", "comfortable", "tee", "t-shirt",
        "jeans", "jogger", "hoodie", "sweatshirt", "basic",
    ],
    "party": [
        "party", "sequin", "glitter", "gown", "cocktail", "mini dress",
        "bodycon", "glam", "festive", "evening",
    ],
    "ethnic": [
        "ethnic", "kurta", "kurti", "saree", "lehenga", "salwar",
        "palazzo", "sherwani", "dupatta", "embroidered", "silk",
        "georgette", "festive", "traditional",
    ],
    "sport": [
        "gym", "workout", "athletic", "running", "yoga", "training",
        "jersey", "dry fit", "dri-fit", "performance",
    ],
    "sleep": [
        "sleep", "night", "pyjama", "pajama", "nighty", "robe",
        "lounge", "nightwear",
    ],
    "beach": [
        "beach", "swim", "swimwear", "bikini", "board shorts", "resort",
        "cover up",
    ],
}

# Occasions that hard-block each other
# e.g. if query is "office", block products clearly tagged for sleep/beach/party
OCCASION_ANTI_MAP: dict[str, list[str]] = {
    "office": ["sleep", "beach", "party", "sport"],
    "casual": [],            # casual is broad — don't hard-block much
    "party": ["sleep", "office", "sport"],
    "ethnic": [],            # ethnic can also be occasion-agnostic
    "sport": ["sleep", "office", "party"],
    "sleep": ["office", "party", "sport", "beach"],
    "beach": ["office", "sleep"],
}

# ─── Stopwords ─────────────────────────────────────────────────────────────────

STOPWORDS = {
    "i", "want", "need", "looking", "for", "a", "an", "the", "some",
    "any", "please", "can", "you", "show", "me", "find", "get", "buy",
    "am", "is", "are", "my", "something", "good", "nice", "best",
    "new", "old", "like", "love", "prefer", "give", "suggest",
    "recommend", "wear", "wearing", "to", "and", "or", "in", "on",
    "at", "of", "with",
}

# ─── Price Extraction Helper ───────────────────────────────────────────────────

def _extract_price_constraints(query: str) -> tuple[Optional[float], Optional[float]]:
    """
    Parses the raw query for price constraints.

    Handles patterns like:
      "under 500", "below 1000", "less than 2000",
      "above 300", "over 500", "more than 1000",
      "between 500 and 1500", "500 to 1500",
      "budget", "cheap"  → sets a soft cap (None returned, handled by caller)

    Returns (min_price, max_price) as floats, or None if not found.
    """
    text = query.lower()

    # Normalize number formatting: remove commas (1,000 → 1000)
    text = re.sub(r"(\d)[,](\d{3})", r"\1\2", text)

    # Universal currency pattern (symbols + ISO codes)
    currency_pattern = r"(?:[$€£¥₹]|usd|eur|gbp|inr|jpy|aud|cad|sgd|aed)?"

    # Pattern: "between X and Y" or "X to Y"
    between = re.search(
        rf"between\s+{currency_pattern}\s*(\d+(?:\.\d+)?)\s+(?:and|to)\s+{currency_pattern}\s*(\d+(?:\.\d+)?)",
        text,
    )
    if between:
        return float(between.group(1)), float(between.group(2))

    range_pat = re.search(
        rf"{currency_pattern}\s*(\d+(?:\.\d+)?)\s*to\s*{currency_pattern}\s*(\d+(?:\.\d+)?)",
        text,
    )
    if range_pat:
        return float(range_pat.group(1)), float(range_pat.group(2))

    # Pattern: "under / below / less than X"
    upper = re.search(
        rf"(?:under|below|less\s+than|max|maximum|upto|up\s+to|within)\s+{currency_pattern}\s*(\d+(?:\.\d+)?)",
        text,
    )
    if upper:
        return None, float(upper.group(1))

    # Pattern: "above / over / more than / at least X"
    lower = re.search(
        rf"(?:above|over|more\s+than|at\s+least|min|minimum)\s+{currency_pattern}\s*(\d+(?:\.\d+)?)",
        text,
    )
    if lower:
        return float(lower.group(1)), None

    # Pattern: standalone currency value
    currency = re.search(
        rf"{currency_pattern}\s*(\d+(?:\.\d+)?)",
        text
    )
    if currency:
        return None, float(currency.group(1))

    return None, None

# ─── Keyword Extraction ────────────────────────────────────────────────────────

def extract_keywords(query: str) -> dict:
    """
    Extract structured signals from a raw customer query.
    Completely pure Python — zero external calls.

    Returns:
        {
            "gender":    "Men" | "Women" | "Kids" | None,
            "colors":    ["red", ...],
            "materials": ["cotton", ...],
            "min_price": float | None,
            "max_price": float | None,
            "budget_tier": "budget" | "premium" | None,
            "seasons":   ["summer", ...],
            "occasions": ["office", "casual", ...],
            "tokens":    ["tshirt", "casual", ...]
        }
    """
    # Normalise
    text = re.sub(r"[^\w\s]", " ", query.lower())
    words = text.split()

    # Price extraction (works on original query to preserve punctuation like ₹)
    min_price, max_price = _extract_price_constraints(query)

    detected_gender: Optional[str] = None
    detected_colors: list[str] = []
    detected_materials: list[str] = []
    detected_seasons: list[str] = []
    detected_occasions: list[str] = []
    budget_tier: Optional[str] = None
    meaningful_tokens: list[str] = []

    # Multi-word phrase checks (before word-by-word loop)
    for phrase in BUDGET_QUERY_WORDS:
        if phrase in text:
            budget_tier = "budget"
            break
    for phrase in PREMIUM_QUERY_WORDS:
        if phrase in text:
            budget_tier = "premium"
            break

    # Season phrase check
    for season, synonyms in SEASON_QUERY_SYNONYMS.items():
        for syn in synonyms:
            if f" {syn} " in f" {text} ":
                if season not in detected_seasons:
                    detected_seasons.append(season)
                break

    # Occasion phrase check
    for occasion, synonyms in OCCASION_QUERY_SYNONYMS.items():
        for syn in synonyms:
            if f" {syn} " in f" {text} ":
                if occasion not in detected_occasions:
                    detected_occasions.append(occasion)
                break

    for word in words:
        # Gender
        matched_gender = None
        for gender, synonyms in GENDER_QUERY_SYNONYMS.items():
            if word in synonyms:
                matched_gender = gender
                break
        if matched_gender:
            if detected_gender and detected_gender != matched_gender:
                detected_gender = None   # conflicting → no filter
            else:
                detected_gender = matched_gender
            continue

        # Color
        if word in COLORS:
            detected_colors.append(word)
            meaningful_tokens.append(word)
            continue

        # Material
        if word in MATERIALS:
            detected_materials.append(word)
            meaningful_tokens.append(word)
            continue

        # Stopwords
        if word in STOPWORDS or len(word) <= 2:
            continue

        meaningful_tokens.append(word)

    return {
        "gender":     detected_gender,
        "colors":     detected_colors,
        "materials":  detected_materials,
        "min_price":  min_price,
        "max_price":  max_price,
        "budget_tier": budget_tier,
        "seasons":    detected_seasons,
        "occasions":  detected_occasions,
        "tokens":     meaningful_tokens,
    }


# ─── Product Text Builder ──────────────────────────────────────────────────────

def _build_product_text(product: dict) -> str:
    """
    Concatenate all text fields from a Qdrant result payload into
    one lowercase string for keyword matching.
    """
    parts = [
        product.get("name", ""),
        product.get("categories", ""),
        product.get("tags", ""),
        product.get("short_description", ""),
        product.get("description", ""),
    ]

    skip_keys = {
        "name", "categories", "tags", "short_description", "description",
        "product_id", "price", "permalink", "image_url", "stock_status",
        "score", "currency", "regular_price", "sale_price", "on_sale",
        "average_rating", "sku", "brand", "client_id",
    }
    for key, val in product.items():
        if key not in skip_keys and isinstance(val, (str, int, float)):
            parts.append(str(val))

    return " ".join(parts).lower()


def _get_product_price(product: dict) -> Optional[float]:
    """Return the effective price of a product as a float, or None."""
    for key in ("price", "sale_price", "regular_price"):
        val = product.get(key)
        if val is not None:
            try:
                return float(str(val).replace(",", "").strip())
            except (ValueError, TypeError):
                continue
    return None


# ─── Hard Blockers ─────────────────────────────────────────────────────────────

def _is_wrong_gender(product_text: str, required_gender: str) -> bool:
    unisex_markers = ["unisex", "kids", "children", "child", "toddler", "baby", "infant"]
    if any(m in product_text for m in unisex_markers):
        return False

    opposite_words = GENDER_OPPOSITES.get(required_gender, [])
    required_words = GENDER_PRODUCT_WORDS.get(required_gender, [])

    has_opposite = any(
        re.search(rf"\b{re.escape(w)}\b", product_text) for w in opposite_words
    )
    has_required = any(
        re.search(rf"\b{re.escape(w)}\b", product_text) for w in required_words
    )
    return has_opposite and not has_required


def _is_wrong_price(product: dict, min_price: Optional[float], max_price: Optional[float]) -> bool:
    """
    Returns True if the product's price falls outside the requested range.
    Only hard-blocks when a price is explicitly specified in the query.
    Gives a 10% buffer on max_price to avoid blocking ₹499 for a ₹500 query.
    """
    if min_price is None and max_price is None:
        return False

    price = _get_product_price(product)
    if price is None:
        return False   # no price info → don't block

    buffer = 1.10   # 10% tolerance on max
    if max_price is not None and price > max_price * buffer:
        return True
    if min_price is not None and price < min_price:
        return True
    return False


def _is_wrong_season(product_text: str, required_seasons: list[str]) -> bool:
    """
    Returns True if the product is clearly designed for the OPPOSITE season.
    A product is only blocked when:
      1. It contains anti-season words for ALL queried seasons, AND
      2. It contains no positive season words for any queried season.
    """
    if not required_seasons:
        return False

    for season in required_seasons:
        anti = SEASON_ANTI_WORDS.get(season, [])
        positive = SEASON_PRODUCT_WORDS.get(season, [])

        has_anti = any(re.search(rf"\b{re.escape(w)}\b", product_text) for w in anti)
        has_positive = any(re.search(rf"\b{re.escape(w)}\b", product_text) for w in positive)

        # If it's clearly wrong AND not redeemed by positive season words → block
        if has_anti and not has_positive:
            return True

    return False


def _is_wrong_occasion(product_text: str, required_occasions: list[str]) -> bool:
    """
    Hard-blocks a product if it clearly belongs to an ANTI occasion.
    E.g. if the user wants "office wear", pyjamas and swimwear are blocked.
    Only blocks when the product has strong signals for a banned occasion
    AND no signals for the required occasion.
    """
    if not required_occasions:
        return False

    for occasion in required_occasions:
        anti_occasions = OCCASION_ANTI_MAP.get(occasion, [])
        if not anti_occasions:
            continue

        required_words = OCCASION_PRODUCT_WORDS.get(occasion, [])
        has_required = any(
            re.search(rf"\b{re.escape(w)}\b", product_text) for w in required_words
        )

        if has_required:
            continue   # product already confirmed for this occasion → don't block

        for anti_occ in anti_occasions:
            anti_words = OCCASION_PRODUCT_WORDS.get(anti_occ, [])
            # Need at least 2 anti-occasion signals to hard-block (avoid false positives)
            anti_hits = sum(
                1 for w in anti_words
                if re.search(rf"\b{re.escape(w)}\b", product_text)
            )
            if anti_hits >= 2:
                return True

    return False


# ─── Soft Scorer ───────────────────────────────────────────────────────────────

def _soft_score(product_text: str, keywords: dict) -> float:
    """
    Returns a 0.0–1.0 additive soft score based on:
      - Color / material presence  (0–0.4)
      - Season match               (0–0.3)
      - Occasion match             (0–0.3)
    """
    score = 0.0

    # Color + material (0.4 weight)
    soft_targets = keywords["colors"] + keywords["materials"]
    if soft_targets:
        hits = sum(1 for t in soft_targets if t in product_text)
        score += (hits / len(soft_targets)) * 0.4

    # Season (0.3 weight)
    seasons = keywords.get("seasons", [])
    if seasons:
        season_hits = 0
        season_total = 0
        for season in seasons:
            positive = SEASON_PRODUCT_WORDS.get(season, [])
            season_total += len(positive)
            season_hits += sum(1 for w in positive if w in product_text)
        if season_total:
            score += (season_hits / season_total) * 0.3

    # Occasion (0.3 weight)
    occasions = keywords.get("occasions", [])
    if occasions:
        occ_hits = 0
        occ_total = 0
        for occasion in occasions:
            words = OCCASION_PRODUCT_WORDS.get(occasion, [])
            occ_total += len(words)
            occ_hits += sum(1 for w in words if w in product_text)
        if occ_total:
            score += (occ_hits / occ_total) * 0.3

    return min(score, 1.0)


# ─── Budget Tier Penalty ───────────────────────────────────────────────────────

def _budget_score_penalty(product: dict, budget_tier: Optional[str]) -> float:
    """
    Returns a score penalty (0.0 to 0.2) when a product's price tier
    conflicts with the user's stated budget preference.
    Only applied when no explicit price number was given.
    """
    if not budget_tier:
        return 0.0

    price = _get_product_price(product)
    if price is None:
        return 0.0

    if budget_tier == "budget" and price > 1500:
        # Expensive item when user wants cheap → penalise
        return min((price - 1500) / 5000, 0.2)

    if budget_tier == "premium" and price < 500:
        # Cheap item when user wants premium → mild penalty
        return 0.05

    return 0.0


# ─── Main Filter + Re-rank ─────────────────────────────────────────────────────

def filter_and_rerank(results: list, keywords: dict, original_limit: int) -> list:
    """
    Filter and re-rank a list of Qdrant result dicts using extracted keywords.

    Hard blocks:
      - Wrong gender
      - Price outside explicit range
      - Wrong season (when season is explicit in query)
      - Wrong occasion (when occasion is explicit and product is clearly another)

    Soft boosts:
      - Color / material match
      - Season alignment
      - Occasion alignment
      - Budget tier alignment (penalty on mismatch)

    Args:
        results:        List of product dicts from qdrant_service.search_products()
        keywords:       Output of extract_keywords()
        original_limit: How many results the caller originally asked for

    Returns:
        Filtered + re-scored list, at most original_limit items.
        Falls back to top-3 by vector score if blocking removes everything.
    """
    if not results:
        return []

    has_any_filter = any([
        keywords.get("gender"),
        keywords.get("colors"),
        keywords.get("materials"),
        keywords.get("min_price") is not None,
        keywords.get("max_price") is not None,
        keywords.get("budget_tier"),
        keywords.get("seasons"),
        keywords.get("occasions"),
    ])

    if not has_any_filter:
        return results[:original_limit]

    required_gender  = keywords.get("gender")
    min_price        = keywords.get("min_price")
    max_price        = keywords.get("max_price")
    budget_tier      = keywords.get("budget_tier")
    required_seasons = keywords.get("seasons", [])
    required_occs    = keywords.get("occasions", [])

    blocked: list[dict] = []
    passed:  list[dict] = []

    for product in results:
        product_text  = _build_product_text(product)
        block_reason  = None

        # ── Hard blocks ───────────────────────────────────────────────────────
        if required_gender and _is_wrong_gender(product_text, required_gender):
            block_reason = f"wrong gender (needed: {required_gender})"

        elif _is_wrong_price(product, min_price, max_price):
            price = _get_product_price(product)
            block_reason = f"price ₹{price} outside range [{min_price}–{max_price}]"

        elif required_seasons and _is_wrong_season(product_text, required_seasons):
            block_reason = f"wrong season (needed: {required_seasons})"

        elif required_occs and _is_wrong_occasion(product_text, required_occs):
            block_reason = f"wrong occasion (needed: {required_occs})"

        if block_reason:
            blocked.append(product)
            print(f"  🚫 Blocked [{product.get('name', '?')}] — {block_reason}")
            continue

        # ── Soft score ────────────────────────────────────────────────────────
        soft      = _soft_score(product_text, keywords)
        penalty   = _budget_score_penalty(product, budget_tier)
        qdrant_sc = product.get("score", 0.0)

        # Weighted: 60% vector similarity, 40% keyword soft signals, minus penalty
        final_score = (qdrant_sc * 0.6) + (soft * 0.4) - penalty
        product["score"] = round(max(final_score, 0.0), 4)
        passed.append(product)

    print(
        f"  🔎 Rerank: {len(passed)} passed, {len(blocked)} blocked"
        f" | gender={required_gender} price=[{min_price}–{max_price}]"
        f" seasons={required_seasons} occasions={required_occs}"
    )

    # ── Graceful fallback ──────────────────────────────────────────────────────
    if not passed:
        print("  ⚠️  All products blocked — falling back to top-3 by vector score")
        fallback = sorted(results, key=lambda r: r.get("score", 0), reverse=True)[:3]
        return fallback

    # ── Sort by final score ────────────────────────────────────────────────────
    passed.sort(key=lambda r: r.get("score", 0), reverse=True)
    return passed[:original_limit]