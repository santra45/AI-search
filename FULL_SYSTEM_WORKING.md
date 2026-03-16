# Full System Working Guide

This document explains the end-to-end working of the repository in practical terms: what each part does, how requests flow, how data moves, and where key operational/security controls exist.

---

## 1) What this repository contains

The repo has two major parts:

1. **FastAPI backend (`semantic-search/`)**
   - Provides semantic search APIs.
   - Validates license tokens.
   - Embeds product/query text via Gemini.
   - Stores/searches vectors in Qdrant.
   - Tracks usage and analytics in MySQL.
   - Uses Redis for caching.

2. **WooCommerce plugin (`semantic-search-woo/`)**
   - Sends product data from WordPress/WooCommerce to FastAPI.
   - Intercepts frontend WooCommerce search and routes it through semantic search.
   - Registers webhooks so product create/update/delete events stay in sync.
   - Exposes admin UI for settings, sync, analytics, and status.

---

## 2) Backend startup and routing

### Entry point
- `backend/app/main.py` initializes `FastAPI` and mounts route modules with `/api` prefix.
- Health route `/` returns basic service status.

### Core route groups
- `search.py` → customer search API (`POST /api/search`)
- `ingest.py` → manual ingestion and deletion (`POST /api/ingest`, `POST /api/ingest/delete`)
- `sync.py` → plugin batch sync (`POST /api/sync/batch`, `GET /api/sync/status`)
- `webhooks.py` → Woo webhook consumers (`product-created/updated/deleted`)
- `dashboard.py` → usage/analytics/status APIs used by WP admin UI
- `webhook_secret.py` → store per-client webhook secret

---

## 3) Authentication and license model

License/auth logic is centralized in `services/license_service.py`.

### Token generation
- `generate_license_key(...)` creates JWT containing:
  - `license_id`, `client_id`, `plan`, `domain`, limits, `exp`, `iat`
- Same token is persisted in MySQL (`license_keys` table).

### Validation on request
- `validate_license_key(token, db)`:
  1. Decodes JWT using `JWT_SECRET`.
  2. Fetches DB row (`license_keys` + `clients`) to ensure active state.
  3. Validates not expired and client active.
  4. Returns normalized license context (client_id, limits, domain, etc.).

### Quota and usage
- `increment_search_count` / `increment_ingest_count` update monthly usage logs.
- `check_search_quota` reads monthly usage and compares against plan limit.
- `log_search` inserts per-query telemetry for dashboard analytics.

---

## 4) Search request full flow (`POST /api/search`)

File: `routers/search.py`

1. **Input validation**
   - Requires `license_key`, `query`; optional `limit`, `enable_intent`.
   - Empty query is rejected.

2. **License validation**
   - Calls `validate_license_key`.

3. **Domain authorization check**
   - Reads `Origin` or `Referer` request header.
   - If license domain is configured, only matching host (or localhost) is allowed.

4. **Quota enforcement**
   - Calls `check_search_quota`; if exceeded returns HTTP 429.

5. **Result cache lookup (Redis)**
   - Keyed by `search:{client_id}:{hash(query)}`.
   - If cache hit, returns results immediately and still counts usage.

6. **Optional intent extraction**
   - If `enable_intent=true`, calls `intent_service.analyze_intent`.
   - Produces:
     - `clean_query`
     - optional `min_price`, `max_price`
     - `only_in_stock`

7. **Embedding cache lookup**
   - Keyed by `embed:{hash(query_text)}`.
   - If miss: `embedder.embed_query(...)` calls Gemini embedding model.

8. **Vector search (Qdrant)**
   - Calls `qdrant_service.search_products(...)` with:
     - tenant filter (`client_id`)
     - optional price range
     - optional stock filter
   - Fetches 2× requested limit for post-filtering headroom.

9. **Post-filter + rerank**
   - `rerank_service.extract_keywords(raw_query)` detects gender/colors/materials.
   - `filter_and_rerank`:
     - blocks explicit opposite-gender matches
     - applies soft score boosts for color/material alignment
     - sorts and trims to requested limit

10. **Cache and logs**
    - Stores final results in Redis.
    - Increments monthly search usage.
    - Logs search telemetry in MySQL.

11. **Response**
    - Returns `query`, `count`, `cached` flag, and result list.

---

## 5) Product ingestion flows

There are three ingestion paths into Qdrant:

### A) Direct ingest API (`POST /api/ingest`)
- Validates license + domain.
- Enforces product limit (`get_client_product_count + incoming`).
- For each product:
  1. Build product text (`product_service.build_product_text`)
  2. Generate embedding (`embed_document`)
  3. Build payload (`extract_payload`)
  4. Upsert vector + payload to Qdrant (`upsert_product`)
- Increments ingest count for successful items.

### B) Sync batch API (`POST /api/sync/batch`)
- Used by plugin for large catalog sync.
- Similar per-product processing pipeline as ingest.
- On last batch, invalidates client search caches.

### C) Woo webhooks (`/api/webhook/product-*`)
- Verifies `client_id` exists and client active.
- Reads raw body and JSON once.
- Verifies HMAC signature using saved `webhook_secret`.
- For create/update:
  - if unpublished/variation -> skip/delete logic
  - else embed + upsert + cache invalidation + ingest count increment
- For delete:
  - deletes vector point + invalidates cache

---

## 6) Data modeling and vector tenancy

### Vector point identity
- Point ID is deterministic UUIDv5 of `"{client_id}-{product_id}"`.
- This guarantees updates overwrite same product point for same client.

### Tenant isolation
- Search applies Qdrant filter: `client_id == <license client_id>`.
- Payload stores `client_id` and normalized product metadata.

### Payload composition
`extract_payload(...)` stores:
- fixed fields: name, price, permalink, stock, categories, tags, image, brand, etc.
- dynamic attributes flattened (size, color, material, etc.) for rerank/filtering use.

---

## 7) Caching strategy

### Redis usage (`services/cache_service.py`)
- Embedding cache:
  - key: `embed:<sha256(query)>`
  - TTL: 24h
- Search result cache:
  - key: `search:<client_id>:<sha256(query)>`
  - TTL: 1h
- Cache invalidation:
  - on product changes (webhook and sync end), all `search:<client_id>:*` keys are deleted.

This balances latency and freshness:
- frequent queries return faster
- product updates clear stale result sets

---

## 8) Analytics and dashboard APIs

`routers/dashboard.py` powers plugin dashboard cards and charts.

### Endpoints
- `/api/dashboard/stats`
  - plan info
  - search usage percentage
  - product indexed count
  - recent searches
- `/api/analytics/top-queries`
- `/api/analytics/zero-results`
- `/api/analytics/summary`
  - total volume
  - cache hit rate
  - zero-result rate
  - avg response times
  - daily series for charts
- `/api/status`
  - license validity + plan + domain + indexed count

Data sources:
- MySQL (`usage_logs`, `search_logs`, `clients`, `license_keys`)
- Qdrant (`get_client_product_count`)

---

## 9) WooCommerce plugin full flow

Main bootstrap file: `semantic-search-woo.php`

### Activation/deactivation
- Activation:
  - sets default API URL and result limit
  - creates webhook secret if missing
- Deactivation:
  - tries removing registered Woo webhooks

### Runtime initialization (`ssw_init`)
- In admin: loads admin panel (`SSW_Admin`).
- Frontend with API+license configured:
  - enables query interception (`SSW_Search`)
  - enables shortcode UI (`SSW_Shortcode`)

### Frontend search interception (`includes/class-search.php`)
- Hooks into `pre_get_posts`.
- For main Woo search query:
  - sends query to FastAPI via `SSW_API_Client->search()`.
  - if IDs returned: enforces `post__in` and ranking order.
  - if backend fails/empty: lets default Woo search run.

### Admin panel (`admin/class-admin.php`)
Provides AJAX actions for:
- save settings
- test connection
- start sync + process next batch + reset sync
- fetch dashboard and analytics
- register webhooks
- status checks

### Product sync (`includes/class-sync.php`)
- Splits catalog into batches.
- Formats Woo product objects into backend schema.
- Calls `/api/sync/batch` with batch metadata.
- Tracks progress in WordPress options.

### Webhook registration (`includes/class-webhook-manager.php`)
- Pulls `client_id` by calling backend `/api/status` with bearer token.
- Creates Woo webhooks for created/updated/deleted topics.
- Uses shared secret and delivery URL with `client_id` query param.
- Sends webhook secret to backend `/api/register-webhook-secret`.

---

## 10) Product text and semantic quality

`services/product_service.py` is central for relevance quality.

### Why it matters
Embedding quality is strongly dependent on the text you build from product metadata.

### What it does
- strips HTML
- resolves categories/tags across webhook vs plugin formats
- expands common compact codes (`XL`, age ranges)
- includes brand/gender/categories/tags/attributes/descriptions/price bucket text
- outputs a rich, semantically meaningful multiline document for embedding

This improves semantic retrieval beyond raw title-only search.

---

## 11) Operational dependencies

Runtime requires:
- MySQL (license + analytics + usage)
- Redis (cache)
- Qdrant (vector DB)
- Gemini API key (embeddings, optional intent)

If one of these is degraded:
- Search may slow down (cache miss + embedding path)
- Sync/ingest may fail for products
- Dashboard metrics may be incomplete

---

## 12) End-to-end examples

### Example A: Customer searches “red cotton kurta for women”
1. Frontend query intercepted by plugin.
2. Plugin POSTs to backend `/api/search`.
3. Backend validates token + quota.
4. Embeds query (or cache hit).
5. Qdrant returns candidates for that client.
6. Reranker boosts red/cotton and blocks opposite gender noise.
7. Backend returns ordered IDs.
8. Plugin forces Woo loop to exact ranked product set.

### Example B: Merchant edits product in Woo admin
1. Woo emits `product.updated` webhook.
2. Backend verifies signature.
3. Backend rebuilds product text + embedding.
4. Backend upserts vector point.
5. Backend invalidates cached search results for that client.
6. Next customer query sees fresh catalog state.

### Example C: Initial catalog onboarding
1. Admin clicks Start Sync.
2. Plugin computes total + batches.
3. Each batch posts products to `/api/sync/batch`.
4. Backend embeds + upserts each product.
5. At final batch backend invalidates client caches.
6. Dashboard shows indexed count and ingest metrics.

---

## 13) How to reason about failures quickly

If search returns empty unexpectedly:
1. Check `/api/status` (license validity + indexed count).
2. Verify Qdrant collection and client_id filter alignment.
3. Inspect webhook/sync success + failed product IDs.
4. Inspect search logs for query volume and response time.
5. Temporarily test with `enable_intent=false` to isolate LLM intent parsing effects.

If sync seems stuck:
1. Check WordPress options for `ssw_sync_status/current_batch/total_batches`.
2. Inspect plugin PHP error log and backend logs.
3. Verify API URL/license key and network reachability.
4. Validate Gemini API quota and response behavior.

---

## 14) Quick mental model (one paragraph)

Think of this platform as a **licensed multi-tenant semantic retrieval layer for WooCommerce**: the plugin continuously publishes catalog state (batch sync + webhooks) to a FastAPI backend, the backend converts product metadata into dense vectors and stores tenant-scoped points in Qdrant, and customer search queries are embedded/reranked then mapped back to Woo product IDs in relevance order; MySQL tracks licensing/usage analytics while Redis accelerates repeated queries.

