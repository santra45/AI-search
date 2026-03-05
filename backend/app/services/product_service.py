import re
from typing import Any


# ─── HTML ──────────────────────────────────────────────────────────────────────

def strip_html(text: str) -> str:
    return re.sub(r"<[^>]+>", " ", text).strip()


# ─── Size Map ──────────────────────────────────────────────────────────────────

SIZE_MAP = {
    "XS":    "XS extra small",
    "S":     "S small",
    "M":     "M medium",
    "L":     "L large",
    "XL":    "XL extra large",
    "XXL":   "XXL double extra large",
    "3XL":   "3XL triple extra large",
    "2-3Y":  "age 2 to 3 years toddler",
    "4-5Y":  "age 4 to 5 years",
    "6-7Y":  "age 6 to 7 years",
    "8-9Y":  "age 8 to 9 years",
    "10-11Y": "age 10 to 11 years",
    "12-13Y": "age 12 to 13 years",
}


# ─── Attribute Extraction ──────────────────────────────────────────────────────

def extract_attributes(attributes: list) -> dict:
    """
    Works with both formats:

    WooCommerce webhook format (list of dicts with 'name' + 'options'):
    [{"name": "Color", "options": ["Red", "Blue"]}, ...]

    Plugin sync format (already flattened strings from class-sync.php):
    [{"name": "Color", "options": ["Red", "Blue"]}, ...]

    Returns:
        attr_text_parts  → list of strings for embedding text
        attr_map         → flat dict for Qdrant payload {color: "Red, Blue"}
    """
    attr_text_parts = []
    attr_map        = {}

    for attr in attributes:
        attr_name = attr.get("name", "").strip()
        options   = attr.get("options", [])

        # options can be list or comma-separated string
        if isinstance(options, str):
            options = [o.strip() for o in options.split(",") if o.strip()]

        if not attr_name or not options:
            continue

        # Payload key: lowercase, spaces → underscores
        payload_key = attr_name.lower().replace(" ", "_")
        attr_map[payload_key] = ", ".join(options)

        # Build embedding text
        if attr_name == "Size":
            expanded = [SIZE_MAP.get(s.strip(), s.strip()) for s in options]
            attr_text_parts.append(f"Available sizes: {', '.join(expanded)}")

        elif attr_name == "Color":
            generic = list({c.split()[-1] for c in options})
            attr_text_parts.append(f"Colors: {', '.join(options)}")
            attr_text_parts.append(f"Color family: {', '.join(generic)}")

        else:
            attr_text_parts.append(f"{attr_name}: {', '.join(options)}")

    return {"text_parts": attr_text_parts, "attr_map": attr_map}


# ─── Gender / Age Signal Detector ─────────────────────────────────────────────

def detect_gender_signals(name: str, categories: str) -> list[str]:
    combined = (name + " " + categories).lower()
    signals  = []

    if any(w in combined for w in ["women", "woman", "female", "ladies", "girl", "girls"]):
        signals.append("for women ladies girls female")

    if any(w in combined for w in ["men", "man", "male", "gents", "boys", "boy"]):
        if "women" not in combined and "female" not in combined:
            signals.append("for men gents boys male")

    if any(w in combined for w in ["kids", "kid", "child", "children", "toddler",
                                    "baby", "infant", "junior", "little"]):
        signals.append("for kids children toddler baby")

    return signals


# ─── Build Embedding Text ─────────────────────────────────────────────────────

def build_product_text(p: dict) -> str:
    """
    Builds rich text string for embedding.
    Works with both WooCommerce webhook raw format AND plugin sync flat format.

    Webhook raw format:
        categories = [{"id": 1, "name": "Party Wear"}, ...]
        tags       = [{"id": 1, "name": "sequin"}, ...]
        attributes = [{"name": "Color", "options": ["Red"]}, ...]

    Plugin sync flat format:
        categories = "Party Wear, Dresses"   (already joined string)
        tags       = "sequin, party"          (already joined string)
        attributes = [{"name": "Color", "options": ["Red"]}, ...]
    """
    parts = []

    name = p.get("name", "")
    if name:
        parts.append(f"Product: {name}")

    # ── Categories (handle both formats) ──────────────────────────────────
    cats_raw = p.get("categories", [])
    if isinstance(cats_raw, list):
        cats_str = ", ".join([
            c["name"] if isinstance(c, dict) else str(c)
            for c in cats_raw
        ])
    else:
        cats_str = str(cats_raw)   # already a string from plugin sync

    # ── Gender / age signals ───────────────────────────────────────────────
    signals = detect_gender_signals(name, cats_str)
    if signals:
        parts.append(f"Suitable for: {' | '.join(signals)}")

    if cats_str:
        parts.append(f"Category: {cats_str}")

    # ── Tags (handle both formats) ─────────────────────────────────────────
    tags_raw = p.get("tags", [])
    if isinstance(tags_raw, list):
        tags_str = ", ".join([
            t["name"] if isinstance(t, dict) else str(t)
            for t in tags_raw
        ])
    else:
        tags_str = str(tags_raw)

    if tags_str:
        parts.append(f"Tags: {tags_str}")

    # ── Attributes ─────────────────────────────────────────────────────────
    attributes = p.get("attributes", [])
    if attributes:
        attr_data = extract_attributes(attributes)
        parts.extend(attr_data["text_parts"])

    # ── Descriptions ───────────────────────────────────────────────────────
    short = strip_html(p.get("short_description", ""))
    if short:
        parts.append(f"Summary: {short}")

    desc = strip_html(p.get("description", ""))[:600]
    if desc:
        parts.append(f"Description: {desc}")

    # ── Price ──────────────────────────────────────────────────────────────
    price = p.get("price", "")
    if price:
        parts.append(f"Price: ₹{price}")

    return "\n".join(parts)


# ─── Extract Qdrant Payload ────────────────────────────────────────────────────

def extract_payload(p: dict) -> dict:
    """
    Builds the metadata payload stored alongside the vector in Qdrant.
    Works with both webhook raw format and plugin sync flat format.
    """

    # Categories
    cats_raw = p.get("categories", [])
    if isinstance(cats_raw, list):
        cats_str = ", ".join([
            c["name"] if isinstance(c, dict) else str(c)
            for c in cats_raw
        ])
    else:
        cats_str = str(cats_raw)

    # Tags
    tags_raw = p.get("tags", [])
    if isinstance(tags_raw, list):
        tags_str = ", ".join([
            t["name"] if isinstance(t, dict) else str(t)
            for t in tags_raw
        ])
    else:
        tags_str = str(tags_raw)

    # Images
    imgs_raw  = p.get("images", [])
    image_url = ""
    if isinstance(imgs_raw, list) and imgs_raw:
        first = imgs_raw[0]
        image_url = first["src"] if isinstance(first, dict) else str(first)
    elif isinstance(imgs_raw, str):
        image_url = imgs_raw   # plugin sends it as direct string

    # Price
    price = str(p.get("price", "0") or "0")

    # Attributes → flat dict
    attributes = p.get("attributes", [])
    attr_map   = extract_attributes(attributes)["attr_map"] if attributes else {}

    return {
        "name":           p.get("name", ""),
        "permalink":      p.get("permalink", ""),
        "price":          float(price),
        "regular_price":  float(p.get("regular_price") or price or "0"),
        "sale_price":     float(p.get("sale_price") or "0"),
        "on_sale":        bool(p.get("on_sale", False)),
        "categories":     cats_str,
        "tags":           tags_str,
        "image_url":      image_url,
        "stock_status":   p.get("stock_status", "instock"),
        "average_rating": float(p.get("average_rating") or "0"),
        **attr_map        # size, color, fabric, fit, occasion, etc.
    }