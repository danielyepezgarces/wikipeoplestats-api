"""
Prometheus metrics for Grafana Cloud integration.
Exposes metrics about API usage without exposing sensitive data.
"""

from prometheus_client import Counter, Histogram, generate_latest, CONTENT_TYPE_LATEST
from flask import Response, request
from utils.hashing import hash_ip, get_client_ip


# Define metrics
http_requests_total = Counter(
    'http_requests_total',
    'Total HTTP requests',
    ['method', 'endpoint', 'status', 'user_agent_type']
)

http_requests_blocked_total = Counter(
    'http_requests_blocked_total',
    'Total blocked HTTP requests',
    ['reason', 'ip_hash']
)

http_request_duration_seconds = Histogram(
    'http_request_duration_seconds',
    'HTTP request duration in seconds',
    ['method', 'endpoint']
)

http_requests_by_status = Counter(
    'http_requests_by_status',
    'HTTP requests grouped by status code',
    ['status_code', 'endpoint']
)


def track_request(response):
    """
    Track request metrics after a request is completed.
    
    Args:
        response: Flask response object
    """
    # Get user agent type (without exposing full UA)
    user_agent = request.headers.get('User-Agent', '')
    ua_type = 'unknown'
    
    if 'WikiPeopleStats' in user_agent:
        ua_type = 'wikipeoplestats'
    elif 'MediaWiki' in user_agent or 'Mozilla' in user_agent:
        ua_type = 'browser'
    elif any(tool in user_agent.lower() for tool in ['curl', 'wget', 'postman', 'httpie']):
        ua_type = 'tool'
    elif 'bot' in user_agent.lower() or 'crawler' in user_agent.lower():
        ua_type = 'bot'
    
    # Track total requests
    http_requests_total.labels(
        method=request.method,
        endpoint=request.endpoint or 'unknown',
        status=response.status_code,
        user_agent_type=ua_type
    ).inc()
    
    # Track requests by status
    http_requests_by_status.labels(
        status_code=response.status_code,
        endpoint=request.endpoint or 'unknown'
    ).inc()
    
    return response


def track_blocked_request(reason: str):
    """
    Track a blocked request in metrics.
    
    Args:
        reason: Reason for blocking (e.g., 'invalid-user-agent', 'blocked-ip-range')
    """
    client_ip = get_client_ip(request)
    ip_hash = hash_ip(client_ip)
    
    http_requests_blocked_total.labels(
        reason=reason,
        ip_hash=ip_hash[:16]  # Only use first 16 chars for grouping
    ).inc()


def metrics_endpoint():
    """
    Endpoint handler for /metrics.
    Returns Prometheus metrics in text format.
    """
    return Response(generate_latest(), mimetype=CONTENT_TYPE_LATEST)
