"""
Rate limiting middleware using flask-limiter.
Implements MediaWiki-style global rate limiting per IP.
"""

from flask_limiter import Limiter
from flask_limiter.util import get_remote_address
from flask import request
from config.policies import RATE_LIMITS, REDIS_HOST, REDIS_PORT, REDIS_DB
from utils.hashing import get_client_ip


def get_rate_limit_key():
    """
    Generate the rate limit key based on client IP.
    Uses custom IP extraction to handle proxies.
    """
    return get_client_ip(request)


def get_dynamic_limit():
    """
    Determine the appropriate rate limit for the current request.
    Based on IP trust level, custom limits, and User-Agent restrictions.
    """
    # Check for custom IP-specific limit
    if hasattr(request, 'custom_limit') and request.custom_limit:
        return request.custom_limit
    
    # Check if IP is trusted
    if hasattr(request, 'is_trusted_ip') and request.is_trusted_ip:
        return RATE_LIMITS['trusted']
    
    # Check if User-Agent is restricted
    if hasattr(request, 'is_restricted_ua') and request.is_restricted_ua:
        return RATE_LIMITS['restricted']
    
    # Default rate limit
    return RATE_LIMITS['default']


def init_limiter(app):
    """
    Initialize the Flask-Limiter with the app.
    
    Args:
        app: Flask application instance
        
    Returns:
        Configured Limiter instance
    """
    # Try to use Redis as storage backend
    try:
        storage_uri = f"redis://{REDIS_HOST}:{REDIS_PORT}/{REDIS_DB}"
    except Exception:
        # Fallback to memory storage if Redis is not available
        storage_uri = "memory://"
    
    limiter = Limiter(
        app=app,
        key_func=get_rate_limit_key,
        default_limits=[get_dynamic_limit],
        storage_uri=storage_uri,
        strategy="fixed-window",
        headers_enabled=True,
        # Custom header names for rate limit info
        header_name_mapping={
            "X-RateLimit-Limit": "X-RateLimit-Limit",
            "X-RateLimit-Remaining": "X-RateLimit-Remaining",
            "X-RateLimit-Reset": "X-RateLimit-Reset"
        }
    )
    
    # Custom handler for rate limit exceeded
    @app.errorhandler(429)
    def ratelimit_handler(e):
        from flask import jsonify
        response = jsonify({
            "error": "Rate limit exceeded",
            "message": "Too many requests. Please slow down.",
            "retry_after": e.description if hasattr(e, 'description') else None
        })
        response.status_code = 429
        if hasattr(e, 'description'):
            response.headers['Retry-After'] = str(e.description)
        return response
    
    return limiter
