# AI Chatbot Magento 2 Module

Magento 2 chatbot module that shares the same backend platform and vector collection as semantic search while keeping chat sync, chat UX, and chat analytics fully separate.

## Highlights

- Reuses existing product vectors when `Czar_SemanticSearch` has already indexed them.
- Adds chatbot-only content sync for CMS pages, CMS blocks, widgets, reviews, policy/FAQ content, and selected store config text.
- Provides storefront chat endpoints and an admin dashboard for conversations, usage, and sync visibility.
- Uses dedicated FastAPI chatbot routes under `/api/magento/chatbot/*`.
