"""
Configuration for API policies including rate limits, IP policies, and User-Agent rules.
All access control logic is defined here - no changes needed in core code.
"""

# Rate limit configurations
# Format: "number/period" where period can be: second, minute, hour, day
RATE_LIMITS = {
    "default": "1000/hour",      # Default rate limit for most users
    "trusted": "5000/hour",      # Increased limit for trusted IPs
    "restricted": "50/hour"      # Restricted limit for limited User-Agents
}

# Trusted IPs with higher rate limits
TRUSTED_IPS = [
    "190.60.63.10",
]

# Blocked IP ranges (CIDR notation)
# These IPs will be denied access immediately
BLOCKED_IP_RANGES = [
    "10.0.0.0/8",        # Private network
    "172.16.0.0/12",     # Private network
    "192.168.0.0/16",    # Private network
]

# Custom rate limits for specific IPs
# Override default limits for particular IP addresses
CUSTOM_LIMITS_BY_IP = {
    "190.60.63.25": "100/hour"
}

# User-Agent policy configuration
# Inspired by MediaWiki API etiquette
USER_AGENT_POLICY = {
    # Allow: Well-identified User-Agents with app/version and URL
    # These are considered legitimate and responsible
    "allow": [
        "WikiPeopleStats",
        "MediaWiki",
        "Mozilla",
        "Chrome",
        "Safari",
        "Firefox",
        "Edge",
        "Opera"
    ],
    
    # Limit: Generic User-Agents that are allowed but with reduced limits
    # These get the "restricted" rate limit
    "limit": [
        "curl",
        "wget",
        "Postman",
        "HTTPie",
        "Insomnia"
    ],
    
    # Block: User-Agents that indicate scraping or abuse
    # Empty User-Agents are also blocked
    "block": [
        "",                      # Empty User-Agent
        "python-requests",       # Generic Python library without identification
        "Go-http-client",        # Generic Go client without identification
        "Java",                  # Generic Java client
        "Apache-HttpClient",     # Generic Apache client
        "libwww-perl",          # Generic Perl client
        "bot",                   # Generic bot without proper identification
        "crawler",               # Generic crawler
        "scraper",               # Generic scraper
        "spider"                 # Generic spider
    ]
}

# Recommended User-Agent format for legitimate use
RECOMMENDED_USER_AGENT = "WikiPeopleStats/1.0 (https://wikipeoplestats.org; contact@wikipeoplestats.org)"

# Redis configuration for rate limiting
REDIS_HOST = "localhost"
REDIS_PORT = 6379
REDIS_DB = 0

# Database configuration (if needed for migration)
# Note: In production, use environment variables for sensitive data
DB_CONFIG = {
    "host": "localhost",
    "user": "root",
    "password": "",
    "database": "wikipeoplestats",
    "charset": "utf8mb4"
}

# Memcached configuration (for caching like the PHP version)
MEMCACHED_HOST = "localhost"
MEMCACHED_PORT = 11211

# Cache configuration
CACHE_ENABLED = True
DEFAULT_CACHE_DURATION = 21600  # 6 hours in seconds
