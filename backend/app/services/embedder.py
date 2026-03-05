import google.generativeai as genai
from backend.app.config import GEMINI_API_KEY, EMBED_MODEL

genai.configure(api_key=GEMINI_API_KEY)

def embed_query(text: str) -> list[float]:
    """
    Embed a search query.
    Uses retrieval_query task type — different from indexing.
    """
    result = genai.embed_content(
        model=EMBED_MODEL,
        content=text,
        task_type="retrieval_query",
    )
    return result["embedding"]


def embed_document(text: str) -> list[float]:
    """
    Embed a product document for indexing.
    Uses retrieval_document task type.
    """
    result = genai.embed_content(
        model=EMBED_MODEL,
        content=text,
        task_type="retrieval_document",
    )
    return result["embedding"]