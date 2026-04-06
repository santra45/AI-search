"""
Test script for token usage tracking functionality.
"""

import sys
import os
sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from backend.app.services.token_usage_service import track_usage, TokenUsageTracker
from backend.app.services.database import get_db
import logging

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

def test_basic_tracking():
    """Test basic token usage tracking."""
    logger.info("Testing basic token usage tracking...")
    
    # Test embedding search tracking
    request_id = track_usage(
        client_id="test_client_123",
        query_type="embed_search",
        llm_provider="google",
        llm_model="gemini-embedding-001",
        input_tokens=150,
        output_tokens=0,
        input_cost=0.00002235,
        output_cost=0.0,
        request_text_length=750,
        response_text_length=0
    )
    logger.info(f"✅ Tracked embed search: {request_id}")
    
    # Test embedding document tracking
    request_id = track_usage(
        client_id="test_client_123",
        query_type="embed_document",
        llm_provider="google",
        llm_model="gemini-embedding-001",
        input_tokens=300,
        output_tokens=0,
        input_cost=0.00004470,
        output_cost=0.0,
        request_text_length=1500,
        response_text_length=0
    )
    logger.info(f"✅ Tracked embed document: {request_id}")
    
    # Test product rerank tracking
    request_id = track_usage(
        client_id="test_client_123",
        query_type="product_rerank",
        llm_provider="openai",
        llm_model="gpt-4o-mini",
        input_tokens=250,
        output_tokens=50,
        input_cost=0.00018750,
        output_cost=0.00007500,
        request_text_length=1200,
        response_text_length=200
    )
    logger.info(f"✅ Tracked product rerank: {request_id}")

def test_client_stats():
    """Test client usage statistics."""
    logger.info("Testing client usage statistics...")
    
    db = next(get_db())
    try:
        tracker = TokenUsageTracker(db)
        
        # Get client stats
        stats = tracker.get_client_usage_stats("test_client_123")
        logger.info(f"✅ Client stats for test_client_123:")
        logger.info(f"   Total requests: {stats['totals']['total_requests']}")
        logger.info(f"   Total tokens: {stats['totals']['total_tokens']}")
        logger.info(f"   Total cost: ${stats['totals']['total_cost']:.8f}")
        
        # Get usage summary
        summary = tracker.get_usage_summary()
        logger.info(f"✅ Usage summary:")
        logger.info(f"   Unique clients: {summary['unique_clients']}")
        logger.info(f"   Total requests: {summary['total_requests']}")
        logger.info(f"   Total cost: ${summary['total_cost']:.8f}")
        
    finally:
        db.close()

def test_api_endpoints():
    """Test the API endpoints (requires running server)."""
    logger.info("To test API endpoints, start the server and visit:")
    logger.info("   GET http://localhost:8000/api/token-usage/summary")
    logger.info("   GET http://localhost:8000/api/token-usage/client/test_client_123/stats")
    logger.info("   GET http://localhost:8000/api/token-usage/clients")
    logger.info("   GET http://localhost:8000/api/token-usage/models")
    logger.info("   GET http://localhost:8000/api/token-usage/hourly")

def cleanup_test_data():
    """Clean up test data."""
    logger.info("Cleaning up test data...")
    
    db = next(get_db())
    try:
        from sqlalchemy import text
        result = db.execute(text("DELETE FROM token_usage_tracking WHERE client_id = 'test_client_123'"))
        db.commit()
        logger.info(f"✅ Cleaned up {result.rowcount} test records")
    finally:
        db.close()

if __name__ == "__main__":
    logger.info("🚀 Starting token usage tracking tests...")
    
    try:
        test_basic_tracking()
        test_client_stats()
        test_api_endpoints()
        
        logger.info("✅ All tests completed successfully!")
        
        # Ask if user wants to clean up
        response = input("\nClean up test data? (y/n): ")
        if response.lower() == 'y':
            cleanup_test_data()
            
    except Exception as e:
        logger.error(f"❌ Test failed: {e}")
        raise
    
    logger.info("🎉 Token usage tracking test complete!")
