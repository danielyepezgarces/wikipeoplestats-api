"""
Utility functions for hashing IP addresses for privacy protection.
Used in metrics to avoid exposing real IP addresses.
"""

import hashlib


def hash_ip(ip_address: str) -> str:
    """
    Hash an IP address using SHA-256.
    
    Args:
        ip_address: The IP address to hash
        
    Returns:
        SHA-256 hash of the IP address as a hexadecimal string
    """
    return hashlib.sha256(ip_address.encode('utf-8')).hexdigest()


def get_client_ip(request) -> str:
    """
    Get the client's IP address from the request.
    Handles X-Forwarded-For header for proxied requests.
    
    Args:
        request: Flask request object
        
    Returns:
        Client IP address as string
    """
    if request.headers.get('X-Forwarded-For'):
        # Get the first IP in the chain (the original client)
        return request.headers.get('X-Forwarded-For').split(',')[0].strip()
    elif request.headers.get('X-Real-IP'):
        return request.headers.get('X-Real-IP')
    else:
        return request.remote_addr
