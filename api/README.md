# WikiPeopleStats API - Flask

API Flask para estadísticas de personas en Wikipedia, migrada desde PHP.

## Características

- **Rate Limiting**: Control de peticiones estilo MediaWiki, global por IP
- **Políticas de IP**: Soporte para IPs confiables, bloqueadas y límites personalizados
- **Validación de User-Agent**: Permite solo peticiones legítimas y bien identificadas
- **Métricas Prometheus**: Integración con Grafana Cloud
- **Configuración Centralizada**: Todo configurable sin modificar el código core

## Estructura del Proyecto

```
api/
├── app.py                      # Aplicación principal Flask
├── requirements.txt            # Dependencias Python
├── config/
│   └── policies.py            # Configuración de políticas (rate limits, IPs, User-Agents)
├── middlewares/
│   ├── ip_policy.py           # Control de acceso por IP
│   ├── user_agent.py          # Validación de User-Agent
│   └── rate_limit.py          # Rate limiting global
├── routes/
│   └── api.py                 # Endpoints de la API
├── metrics/
│   └── prometheus.py          # Métricas para Grafana Cloud
└── utils/
    └── hashing.py             # Utilidades (hash de IPs)
```

## Instalación

### 1. Crear entorno virtual

```bash
python3 -m venv venv
source venv/bin/activate  # En Windows: venv\Scripts\activate
```

### 2. Instalar dependencias

```bash
cd api
pip install -r requirements.txt
```

### 3. Configurar servicios

Asegúrate de tener corriendo:
- Redis (para rate limiting)
- Memcached (para caché de respuestas)
- MySQL/MariaDB (base de datos)

## Configuración

Edita `config/policies.py` para ajustar:

### Rate Limits

```python
RATE_LIMITS = {
    "default": "1000/hour",
    "trusted": "5000/hour",
    "restricted": "50/hour"
}
```

### IPs Confiables

```python
TRUSTED_IPS = [
    "190.60.63.10",
]
```

### Rangos de IP Bloqueados

```python
BLOCKED_IP_RANGES = [
    "10.0.0.0/8",
]
```

### User-Agent Policy

```python
USER_AGENT_POLICY = {
    "allow": ["WikiPeopleStats", "MediaWiki"],
    "limit": ["curl", "Postman"],
    "block": ["", "python-requests", "Go-http-client"]
}
```

## Ejecución

### Desarrollo

```bash
flask --app app.py run
# O con recarga automática:
flask --app app.py run --debug
```

### Producción

```bash
gunicorn app:app --bind 0.0.0.0:8000 --workers 4
```

## Endpoints

### General

- `GET /` - Información de la API y documentación
- `GET /health` - Health check
- `GET /metrics` - Métricas Prometheus

### Estadísticas

- `GET /stats?project={project}` - Estadísticas generales
- `GET /genders/stats?project={project}&start_date={date}&end_date={date}` - Estadísticas por género
- `GET /genders/graph?project={project}` - Gráficos de género

### Usuarios

- `GET /users/stats?project={project}&username={user}` - Estadísticas de usuario
- `GET /users/graph?project={project}&username={user}` - Gráficos de usuario

### Eventos

- `GET /events/stats?project={project}&event_id={id}` - Estadísticas de evento

### Rankings

- `GET /rankings/{group}/{timeframe}` - Rankings

### Otros

- `GET /languages` - Idiomas soportados

## User-Agent Recomendado

Para un acceso óptimo a la API, usa un User-Agent que identifique tu herramienta:

```
WikiPeopleStats/1.0 (https://wikipeoplestats.org; contact@wikipeoplestats.org)
```

## Headers de Rate Limit

La API incluye headers informativos:

- `X-RateLimit-Limit`: Límite total de peticiones
- `X-RateLimit-Remaining`: Peticiones restantes
- `X-RateLimit-Reset`: Timestamp de reset del límite
- `Retry-After`: Segundos hasta poder reintentar (en caso de límite excedido)

## Respuestas de Error

### 403 Forbidden

```json
{
  "error": "Blocked User-Agent",
  "message": "User-Agent 'python-requests' is not allowed...",
  "recommended": "WikiPeopleStats/1.0 (https://wikipeoplestats.org)",
  "documentation": "https://www.mediawiki.org/wiki/API:Etiquette"
}
```

### 429 Too Many Requests

```json
{
  "error": "Rate limit exceeded",
  "message": "Too many requests. Please slow down.",
  "retry_after": "3600"
}
```

## Métricas

Accede a `/metrics` para obtener métricas en formato Prometheus:

- `http_requests_total` - Total de peticiones HTTP
- `http_requests_blocked_total` - Peticiones bloqueadas
- `http_requests_by_status` - Peticiones por código de estado
- `http_request_duration_seconds` - Duración de peticiones

Las IPs se hashean (SHA-256) para proteger la privacidad.

## Seguridad

- **IPs hasheadas**: Las IPs reales nunca se exponen en métricas
- **Configuración desacoplada**: Políticas separadas del código core
- **Bloqueo temprano**: Middleware valida antes de procesar peticiones
- **CORS configurado**: Control de orígenes permitidos

## Referencias

- [MediaWiki API Etiquette](https://www.mediawiki.org/wiki/API:Etiquette)
- [Prometheus](https://prometheus.io)
- [Grafana Cloud](https://grafana.com/products/cloud/)
- [Flask-Limiter](https://flask-limiter.readthedocs.io/)

## Licencia

Ver archivo LICENSE en la raíz del proyecto.
