# Deployment Guide for WikiPeopleStats Flask API

## Production Deployment with Gunicorn

### Basic Setup

```bash
# Install dependencies
cd api
pip install -r requirements.txt

# Run with Gunicorn
gunicorn app:app --bind 0.0.0.0:8000 --workers 4 --timeout 120
```

### Systemd Service (Recommended)

Create `/etc/systemd/system/wikipeoplestats-api.service`:

```ini
[Unit]
Description=WikiPeopleStats Flask API
After=network.target redis.target memcached.service mysql.service

[Service]
Type=notify
User=www-data
Group=www-data
WorkingDirectory=/var/www/wikipeoplestats-api/api
Environment="PATH=/var/www/wikipeoplestats-api/api/venv/bin"
ExecStart=/var/www/wikipeoplestats-api/api/venv/bin/gunicorn \
    --bind 127.0.0.1:8000 \
    --workers 4 \
    --worker-class sync \
    --timeout 120 \
    --access-logfile /var/log/wikipeoplestats/access.log \
    --error-logfile /var/log/wikipeoplestats/error.log \
    --log-level info \
    app:app

Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Enable and start the service:

```bash
sudo systemctl enable wikipeoplestats-api
sudo systemctl start wikipeoplestats-api
sudo systemctl status wikipeoplestats-api
```

## Nginx Configuration

Add to `/etc/nginx/sites-available/api.wikipeoplestats.org`:

```nginx
upstream wikipeoplestats_api {
    server 127.0.0.1:8000;
}

server {
    listen 80;
    server_name api.wikipeoplestats.org;

    # Redirect HTTP to HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name api.wikipeoplestats.org;

    ssl_certificate /etc/letsencrypt/live/api.wikipeoplestats.org/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.wikipeoplestats.org/privkey.pem;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # Logs
    access_log /var/log/nginx/wikipeoplestats-api-access.log;
    error_log /var/log/nginx/wikipeoplestats-api-error.log;

    # Proxy to Flask app
    location / {
        proxy_pass http://wikipeoplestats_api;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        
        # Timeouts
        proxy_connect_timeout 60s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;
    }

    # Metrics endpoint (optional: restrict access)
    location /metrics {
        proxy_pass http://wikipeoplestats_api;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        
        # Optional: Restrict to Grafana Cloud or specific IPs
        # allow 1.2.3.4;
        # deny all;
    }
}
```

Enable the site:

```bash
sudo ln -s /etc/nginx/sites-available/api.wikipeoplestats.org /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

## Environment Variables (Production)

Create `.env` file or set environment variables:

```bash
# Database
export DB_HOST=localhost
export DB_USER=wikipeoplestats
export DB_PASSWORD=your_secure_password
export DB_NAME=wikipeoplestats

# Redis
export REDIS_HOST=localhost
export REDIS_PORT=6379

# Memcached
export MEMCACHED_HOST=localhost
export MEMCACHED_PORT=11211

# Flask
export FLASK_ENV=production
export SECRET_KEY=your_very_long_random_secret_key
```

Update `config/policies.py` to use environment variables:

```python
import os

DB_CONFIG = {
    "host": os.getenv("DB_HOST", "localhost"),
    "user": os.getenv("DB_USER", "root"),
    "password": os.getenv("DB_PASSWORD", ""),
    "database": os.getenv("DB_NAME", "wikipeoplestats"),
    "charset": "utf8mb4"
}

REDIS_HOST = os.getenv("REDIS_HOST", "localhost")
REDIS_PORT = int(os.getenv("REDIS_PORT", 6379))
```

## Required Services

### 1. Redis (for rate limiting)

```bash
sudo apt install redis-server
sudo systemctl enable redis-server
sudo systemctl start redis-server
```

### 2. Memcached (for caching)

```bash
sudo apt install memcached
sudo systemctl enable memcached
sudo systemctl start memcached
```

### 3. MySQL/MariaDB (for data)

Already configured from PHP version.

## Monitoring with Grafana Cloud

1. Sign up at https://grafana.com/products/cloud/
2. Configure Prometheus to scrape `/metrics` endpoint
3. Import dashboard or create custom dashboard

### Prometheus Configuration

Add to `prometheus.yml`:

```yaml
scrape_configs:
  - job_name: 'wikipeoplestats-api'
    scrape_interval: 30s
    static_configs:
      - targets: ['api.wikipeoplestats.org:443']
    scheme: https
    metrics_path: /metrics
```

## Performance Tuning

### Gunicorn Workers

Calculate optimal workers:

```
workers = (2 * CPU_cores) + 1
```

For 2 CPUs: `--workers 5`
For 4 CPUs: `--workers 9`

### Database Connection Pooling

Consider using `SQLAlchemy` with connection pooling for better performance:

```python
from sqlalchemy import create_engine
from sqlalchemy.pool import QueuePool

engine = create_engine(
    f"mysql+pymysql://{DB_USER}:{DB_PASSWORD}@{DB_HOST}/{DB_NAME}",
    poolclass=QueuePool,
    pool_size=10,
    max_overflow=20
)
```

## Security Checklist

- [x] Use HTTPS (SSL/TLS)
- [x] Set secure database credentials
- [x] Restrict /metrics endpoint access (optional)
- [x] Keep dependencies updated
- [x] Use environment variables for secrets
- [x] Configure firewall rules
- [x] Enable fail2ban for SSH
- [x] Regular backups

## Migration from PHP

### 1. Test in Parallel

Run both PHP and Flask APIs on different ports/domains:
- PHP: api-old.wikipeoplestats.org
- Flask: api-new.wikipeoplestats.org

### 2. Gradual Migration

Use Nginx to route traffic:

```nginx
# Route 10% traffic to Flask, 90% to PHP
upstream backend {
    server 127.0.0.1:8000 weight=10;  # Flask
    server 127.0.0.1:9000 weight=90;  # PHP
}
```

### 3. Switch Over

Once Flask is stable, switch all traffic:

```nginx
upstream backend {
    server 127.0.0.1:8000;  # Flask only
}
```

## Troubleshooting

### Check logs

```bash
# Gunicorn logs
sudo journalctl -u wikipeoplestats-api -f

# Nginx logs
sudo tail -f /var/log/nginx/wikipeoplestats-api-error.log

# Application logs
sudo tail -f /var/log/wikipeoplestats/error.log
```

### Common Issues

1. **Connection refused**: Check if services are running
2. **Rate limit errors**: Verify Redis is running
3. **Database errors**: Check DB credentials and connection
4. **502 Bad Gateway**: Gunicorn not running or wrong port

## Rollback Plan

If issues arise, quickly rollback to PHP:

```bash
# Stop Flask
sudo systemctl stop wikipeoplestats-api

# Update Nginx to use PHP
sudo nano /etc/nginx/sites-available/api.wikipeoplestats.org
# Change proxy_pass to PHP-FPM

sudo systemctl reload nginx
```

## Health Checks

Monitor these endpoints:

- `GET /health` - Application health
- `GET /metrics` - Prometheus metrics

Set up alerts in Grafana for:
- High error rates (5xx responses)
- Rate limit breaches
- High response times
- Service downtime
