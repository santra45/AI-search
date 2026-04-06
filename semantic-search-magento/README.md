# Semantic Search Magento 2 Module

Magento 2 module that mirrors the WooCommerce plugin flow against the same FastAPI backend, using Magento-specific endpoints.

## Features

- Configurable API URL, license key, and result limit from Admin.
- Full catalog sync via CLI command: `bin/magento semantic:sync:catalog`.
- Real-time single product sync on save and delete via observers.
- Ajax endpoint for storefront integrations: `/semanticsearch/ajax/search?q=...`.
- Uses FastAPI endpoints:
  - `POST /api/magento/search`
  - `POST /api/magento/sync/batch`
  - `POST /api/magento/sync/delete`

## Install

1. Copy this folder to `app/code/Czar/SemanticSearch`.
2. Run:
   - `bin/magento module:enable Czar_SemanticSearch`
   - `bin/magento setup:upgrade`
   - `bin/magento cache:flush`
3. Configure in **Stores > Configuration > Semantic Search**.

## Notes

- This module expects your existing FastAPI server to be reachable.
- It intentionally uses Magento-dedicated endpoints while keeping the same backend services and data pipeline.
