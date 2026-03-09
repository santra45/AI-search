from google import genai
from backend.app.config import GEMINI_API_KEY, EMBED_MODEL

# Initialize the client
client = genai.Client(api_key=GEMINI_API_KEY)

def embed_query(text: str) -> list[float]:
    """
    Embed a search query using the new SDK.
    """
    result = client.models.embed_content(
        model=EMBED_MODEL,
        contents=text,
        config={
            'task_type': 'RETRIEVAL_QUERY'
        }
    )
    # The new SDK returns an object, not a dictionary
    return result.embeddings[0].values


def embed_document(text: str) -> list[float]:
    """
    Embed a product document for indexing.
    """
    result = client.models.embed_content(
        model=EMBED_MODEL,
        contents=text,
        config={
            'task_type': 'RETRIEVAL_DOCUMENT'
        }
    )
    return result.embeddings[0].values