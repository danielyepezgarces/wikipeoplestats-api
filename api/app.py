"""
WikiPeopleStats API - Flask Migration
Main application entry point.

Migrated from PHP to Flask with:
- MediaWiki-style rate limiting
- IP-based access control
- User-Agent validation
- Prometheus metrics for Grafana Cloud
"""

from flask import Flask, jsonify, request
from flask_cors import CORS
import time

# Import configuration
from config import policies

# Import middlewares
from middlewares.ip_policy import check_ip_policy
from middlewares.user_agent import check_user_agent_policy
from middlewares.rate_limit import init_limiter

# Import metrics
from metrics.prometheus import track_request, track_blocked_request, metrics_endpoint

# Import routes
from routes.api import api_bp

# Initialize Flask app
app = Flask(__name__)

# Enable CORS (matching PHP headers)
CORS(app, resources={
    r"/*": {
        "origins": "*",
        "methods": ["GET", "POST", "OPTIONS"],
        "allow_headers": ["Content-Type", "Authorization"]
    }
})

# Initialize rate limiter
limiter = init_limiter(app)

# Register blueprints
app.register_blueprint(api_bp)

# Register metrics endpoint
@app.route('/metrics', methods=['GET'])
def metrics():
    """Prometheus metrics endpoint for Grafana Cloud."""
    return metrics_endpoint()

# Health check endpoint
@app.route('/health', methods=['GET'])
def health():
    """Health check endpoint."""
    return jsonify({
        "status": "ok",
        "version": "2.0.0",
        "framework": "Flask",
        "message": "WikiPeopleStats API is running"
    })

# Root endpoint
@app.route('/', methods=['GET'])
def root():
    """Root endpoint - API documentation."""
    return jsonify({
        "name": "WikiPeopleStats API",
        "version": "2.0.0",
        "framework": "Flask",
        "documentation": "https://api.wikipeoplestats.org/docs",
        "endpoints": {
            "health": "/health",
            "metrics": "/metrics",
            "stats": "/stats?project={project}",
            "genders": {
                "stats": "/genders/stats?project={project}&start_date={date}&end_date={date}",
                "graph": "/genders/graph?project={project}&start_date={date}&end_date={date}"
            },
            "users": {
                "stats": "/users/stats?project={project}&username={user}",
                "graph": "/users/graph?project={project}&username={user}"
            },
            "events": {
                "stats": "/events/stats?project={project}&event_id={id}"
            },
            "rankings": "/rankings/{group}/{timeframe}",
            "languages": "/languages"
        },
        "recommended_user_agent": policies.RECOMMENDED_USER_AGENT,
        "api_etiquette": "https://www.mediawiki.org/wiki/API:Etiquette"
    })

# Before request middleware
@app.before_request
def before_request():
    """
    Execute before each request.
    Apply IP policy and User-Agent validation.
    """
    # Skip middleware for metrics and health endpoints
    if request.endpoint in ['metrics', 'health']:
        return None
    
    # Check IP policy
    ip_response = check_ip_policy()
    if ip_response:
        track_blocked_request('blocked-ip-range')
        return ip_response
    
    # Check User-Agent policy
    ua_response = check_user_agent_policy()
    if ua_response:
        track_blocked_request('invalid-user-agent')
        return ua_response
    
    # Store request start time for metrics
    request.start_time = time.time()
    
    return None

# After request middleware
@app.after_request
def after_request(response):
    """
    Execute after each request.
    Add CORS headers, track metrics, add rate limit headers.
    """
    # Track request metrics (skip for metrics endpoint itself)
    if request.endpoint != 'metrics':
        track_request(response)
    
    # Calculate execution time if available
    if hasattr(request, 'start_time'):
        execution_time = (time.time() - request.start_time) * 1000
        response.headers['X-Execution-Time'] = f"{execution_time:.2f}ms"
    
    return response

# Error handlers
@app.errorhandler(404)
def not_found(error):
    """Handle 404 errors."""
    return jsonify({
        "error": "Not found",
        "message": "The requested endpoint does not exist",
        "documentation": "https://api.wikipeoplestats.org/docs"
    }), 404

@app.errorhandler(500)
def internal_error(error):
    """Handle 500 errors."""
    return jsonify({
        "error": "Internal server error",
        "message": "An unexpected error occurred"
    }), 500

@app.errorhandler(403)
def forbidden(error):
    """Handle 403 errors."""
    return jsonify({
        "error": "Forbidden",
        "message": "Access denied"
    }), 403

# Run the app
if __name__ == '__main__':
    # Development server
    app.run(
        host='0.0.0.0',
        port=5000,
        debug=True
    )
