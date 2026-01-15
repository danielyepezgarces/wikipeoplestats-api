# Quick Start Guide - WikiPeopleStats Flask API

## TL;DR - Get Running in 5 Minutes

### Option 1: Local Development (No Docker)

```bash
# 1. Navigate to API directory
cd api

# 2. Create virtual environment
python3 -m venv venv
source venv/bin/activate

# 3. Install dependencies
pip install -r requirements.txt

# 4. Run the API
flask --app app.py run

# API now running at http://localhost:5000
```

### Option 2: Docker Compose (Recommended)

```bash
# 1. Navigate to API directory
cd api

# 2. Start all services
docker-compose up

# API now running at http://localhost:5000
# Includes Redis, Memcached, and MySQL
```

## Test the API

### Health Check
```bash
curl http://localhost:5000/health
```

Response:
```json
{"status": "ok", "version": "2.0.0", "framework": "Flask"}
```

### Metrics (Prometheus)
```bash
curl http://localhost:5000/metrics
```

### Test Rate Limiting
```bash
# This works (proper User-Agent)
curl -H "User-Agent: WikiPeopleStats/1.0 (Test)" http://localhost:5000/

# This is blocked (empty User-Agent)
curl -H "User-Agent: " http://localhost:5000/
```

### Stats Endpoint
```bash
curl -H "User-Agent: WikiPeopleStats/1.0 (Test)" \
     http://localhost:5000/stats?project=eswiki
```

## What Works Out of the Box

✅ **Without any external services:**
- API starts and serves requests
- Rate limiting (memory-based)
- User-Agent validation
- IP blocking
- All middleware functionality
- Prometheus metrics
- Health checks

✅ **With Redis (optional):**
- Distributed rate limiting
- Multi-worker support

✅ **With Memcached (optional):**
- Response caching
- Faster response times

✅ **With MySQL (required for full functionality):**
- All database-dependent endpoints

## Configuration

Edit `config/policies.py` to customize:

```python
# Rate limits
RATE_LIMITS = {
    "default": "1000/hour",
    "trusted": "5000/hour",
    "restricted": "50/hour"
}

# Trusted IPs
TRUSTED_IPS = [
    "190.60.63.10",
    # Add your IPs here
]

# Blocked ranges
BLOCKED_IP_RANGES = [
    "10.0.0.0/8",
    # Add ranges to block
]

# User-Agent policy
USER_AGENT_POLICY = {
    "allow": ["WikiPeopleStats", "MediaWiki"],
    "limit": ["curl", "Postman"],
    "block": ["", "python-requests"]
}
```

## Production Deployment

See [DEPLOYMENT.md](DEPLOYMENT.md) for complete production setup with:
- Gunicorn + Nginx
- Systemd service
- SSL/TLS configuration
- Monitoring setup

## Testing

Run the included test suite:

```bash
cd api
source venv/bin/activate
python test_basic.py
```

Expected output:
```
✓ Flask app imported successfully
✓ Registered routes: 19 routes
✓ GET / => Status: 200
✓ GET /health => Status: 200
✓ GET /metrics => Status: 200
✓ Correctly blocked empty User-Agent
ALL BASIC TESTS PASSED ✓
```

## Troubleshooting

### "Connection refused" errors
- Redis/Memcached not running → API will use fallbacks (this is OK!)
- Database not available → Endpoints will return 503 (expected)

### Port 5000 already in use
```bash
# Use a different port
flask --app app.py run --port 5001
```

### Import errors
```bash
# Make sure you're in the api directory and venv is activated
cd api
source venv/bin/activate
pip install -r requirements.txt
```

## Next Steps

1. ✅ **API is running** - Test the endpoints
2. 📊 **Add monitoring** - Connect Grafana to `/metrics`
3. 🔧 **Customize policies** - Edit `config/policies.py`
4. 🚀 **Deploy to production** - Follow [DEPLOYMENT.md](DEPLOYMENT.md)

## Support & Documentation

- **Full README**: [README.md](README.md)
- **Deployment Guide**: [DEPLOYMENT.md](DEPLOYMENT.md)
- **Migration Details**: [MIGRATION_SUMMARY.md](MIGRATION_SUMMARY.md)
- **MediaWiki API Etiquette**: https://www.mediawiki.org/wiki/API:Etiquette

## Example Requests

### Good Request ✓
```bash
curl -H "User-Agent: WikiPeopleStats/1.0 (https://example.com)" \
     http://localhost:5000/stats?project=all
```

### Blocked Request ✗
```bash
curl -H "User-Agent: " http://localhost:5000/stats
```

Response:
```json
{
  "error": "Invalid User-Agent",
  "message": "Please identify your bot/tool with a proper User-Agent",
  "recommended": "WikiPeopleStats/1.0 (https://wikipeoplestats.org)",
  "documentation": "https://www.mediawiki.org/wiki/API:Etiquette"
}
```

## Rate Limit Headers

Every response includes:
```
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 999
X-RateLimit-Reset: 1705276800
X-Execution-Time: 15.23ms
```

---

**You're ready to go! 🎉**

Start with health checks, then explore the endpoints. All middleware and security features are active from the first request.
