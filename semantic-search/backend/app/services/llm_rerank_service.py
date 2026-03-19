import json
from typing import List, Dict, Optional
from google import genai
from backend.app.config import GEMINI_API_KEY
import os

# Configure Gemini
client = genai.Client(api_key=GEMINI_API_KEY)

def llm_rerank_products(query: str, products: List[Dict], limit: int = 10) -> List[Dict]:
    """
    Uses LLM to re-rank products based on relevance to the customer query.
    Only returns products that are genuinely relevant to the query.
    """
    
    if not products or not GEMINI_API_KEY:
        return products[:limit] if products else []
    
    # Prepare product summaries for LLM analysis
    product_summaries = []
    product_map = {}
    for product in products[:25]:
        p_id = str(product.get("product_id") or product.get("id"))
        if not p_id: continue
        
        summary = {
            "id": p_id,
            "name": product.get("name", ""),
            "category": product.get("categories", ""),
            "price": product.get("price", 0),
        }
        product_summaries.append(summary)
        product_map[p_id] = product
    
    # Create the LLM prompt
    prompt = f"""
    You are an expert e-commerce product recommendation assistant. Your task is to analyze customer queries and product data to determine which products are genuinely relevant.
    Customer search query:
    {query}

    Products:
    {json.dumps(product_summaries)}

    Task:
    Select products that clearly match the customer's search intent.

    Rules:
    - Ignore products with wrong gender or category
    - Ignore loosely related products
    - If none match, return []

   Return ONLY a JSON array of product IDs. Example: ["123", "456"] or []
    """

    try:
        # Call Gemini API
        model_id = "gemma-3-27b-it"
        response = client.models.generate_content(model=model_id, contents=prompt)
        
        # Parse the response
        response_text = response.text.strip()
        
        # Try to extract JSON array from response
        if "[" in response_text and "]" in response_text:
            json_start = response_text.find("[")
            json_end = response_text.rfind("]") + 1
            relevant_ids = json.loads(response_text[json_start:json_end])
            
            # Validate indices and get corresponding products
            relevant_products = []
            for p_id in relevant_ids:
                p_id_str = str(p_id)
                if p_id_str in product_map:
                    relevant_products.append(product_map[p_id_str])
            
            # Apply original limit
            if relevant_products:
                return relevant_products[:limit]
        
        return []
        
    except Exception as e:
        print(f"LLM re-ranking error: {e}")
        # Fallback to original order if LLM fails
        return products[:limit]

def should_use_llm_reranking(query: str, products: List[Dict]) -> bool:
    """
    Determines if LLM re-ranking should be applied based on query complexity and result quality.
    """
    
    # Don't use LLM for very simple queries
    simple_indicators = ["shirt", "pants", "dress", "shoes", "bag", "watch"]
    query_lower = query.lower()
    
    if any(indicator in query_lower for indicator in simple_indicators) and len(query.split()) <= 2:
        return False
    
    # Use LLM for complex queries or when we have many results
    if len(products) > 5 or len(query.split()) > 3:
        return True
    
    # Use LLM for queries with specific requirements
    complex_indicators = ["for", "with", "that", "which", "under", "over", "between", "size", "color", "material"]
    if any(indicator in query_lower for indicator in complex_indicators):
        return True
    
    return False
