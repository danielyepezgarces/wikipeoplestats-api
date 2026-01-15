"""
IP-based access control middleware.
Handles blocked IP ranges and trusted IPs.
"""

import ipaddress
from flask import request, jsonify
from functools import wraps
from config.policies import BLOCKED_IP_RANGES, TRUSTED_IPS, CUSTOM_LIMITS_BY_IP
from utils.hashing import get_client_ip


def is_ip_blocked(ip: str) -> bool:
    """
    Check if an IP address is in a blocked range.
    
    Args:
        ip: IP address to check
        
    Returns:
        True if IP is blocked, False otherwise
    """
    try:
        ip_obj = ipaddress.ip_address(ip)
        for blocked_range in BLOCKED_IP_RANGES:
            network = ipaddress.ip_network(blocked_range)
            if ip_obj in network:
                return True
        return False
    except ValueError:
        # Invalid IP address format
        return True  # Block invalid IPs


def is_trusted_ip(ip: str) -> bool:
    """
    Check if an IP address is in the trusted list.
    
    Args:
        ip: IP address to check
        
    Returns:
        True if IP is trusted, False otherwise
    """
    return ip in TRUSTED_IPS


def get_custom_limit(ip: str) -> str:
    """
    Get custom rate limit for a specific IP if defined.
    
    Args:
        ip: IP address to check
        
    Returns:
        Custom limit string or None
    """
    return CUSTOM_LIMITS_BY_IP.get(ip)


def check_ip_policy():
    """
    Middleware function to check IP-based policies.
    Returns error response if IP is blocked.
    """
    client_ip = get_client_ip(request)
    
    if is_ip_blocked(client_ip):
        response = jsonify({
            "error": "Access denied",
            "message": "Your IP address is not allowed to access this API"
        })
        response.status_code = 403
        response.headers['X-Blocked-Reason'] = 'blocked-ip-range'
        return response
    
    # Store IP metadata in request context for other middleware
    request.is_trusted_ip = is_trusted_ip(client_ip)
    request.custom_limit = get_custom_limit(client_ip)
    request.client_ip = client_ip
    
    return None
