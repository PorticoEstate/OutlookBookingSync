# Docker Setup Guide

This guide covers the Docker deployment and configuration for the OutlookBookingSync service.

## Container Architecture

The Docker container includes:
- **PHP 8.4 with Apache** - Web server and API endpoints
- **Cron daemon** - Automated scheduled tasks
- **PostgreSQL extensions** - Database connectivity
- **Microsoft Graph SDK** - Outlook integration
- **Xdebug** - Development debugging support

## Build Configuration

### Dockerfile Overview
- Base image: `php:8.4-apache`
- Proxy support for corporate environments
- System dependencies: PostgreSQL libs, cron, curl
- PHP extensions: PDO, pdo_pgsql, xdebug
- Apache mod_rewrite enabled

### Build Arguments
```bash
# For corporate proxy environments
docker compose build \
  --build-arg http_proxy=http://proxy.company.com:8082 \
  --build-arg https_proxy=http://proxy.company.com:8082
```

## Container Services

### Web Service (Apache + PHP)
- **Port**: 8082 (external) → 80 (internal)
- **Document Root**: `/var/www/html`
- **User**: www-data
- **PHP Version**: 8.4.8
- **Apache Version**: 2.4.62

### Cron Service
Automated tasks running as `www-data` user:

| Schedule | Task | Endpoint |
|----------|------|----------|
| */15 * * * * | Poll Outlook changes | `/polling/poll-changes` |
| 0 * * * * | Detect missing events | `/polling/detect-missing-events` |
| */10 * * * * | Process cancellations | `/bridges/sync-deletions-and-process` |
| 0 8 * * * | Daily statistics | `/polling/stats` |

## Environment Configuration

### Required Variables
```env
# Database Configuration
DB_HOST=localhost
DB_PORT=5432
DB_NAME=your_database
DB_USER=your_username
DB_PASS=your_password

# Application Auth
API_KEY=your_api_key

# Tenant Mode
# single: app uses DEFAULT_TENANT_ID when X-Tenant-Id is not provided
# multi: cron and automation iterate tenants from DB and send X-Tenant-Id per tenant
TENANT_MODE=single
DEFAULT_TENANT_ID=default
```

### Optional Defaults (single-tenant bootstrap)
In the new database-driven multi-tenant model, per-tenant bridge credentials and options
are stored in the database (`bridge_configs`). 

See Maintenance and Admin docs for managing tenants and per-tenant configs via the API/UI.

### Docker Compose Setup
```yaml
services:
  portico_outlook:
    container_name: portico_outlook
    hostname: portico_outlook
    build:
        context: .
        dockerfile: Dockerfile
        args:
           http_proxy: ${http_proxy}
           https_proxy: ${https_proxy}
    ports:
      - "8082:80"
    volumes:
      - .:/var/www/html
    environment:
      - APACHE_RUN_USER=www-data
      - APACHE_RUN_GROUP=www-data
    env_file:
      - .env.compose
    networks:
      - portico_internal
```

### Compose environment file (.env.compose)

Use a dedicated Compose env file to inject container runtime variables (separate from the PHP app `.env`).

Steps:

- Create a file named `.env.compose` next to `docker-compose.yml` with at least:

```dotenv
# Used by ApiKeyMiddleware and cron jobs inside the container
API_KEY=change-me-strong-random

# Optional HTTP proxies available to the container (leave empty if not used)
http_proxy=
https_proxy=

# Feature flags
ENABLE_LEGACY_WEBHOOKS=false

# Tenant processing mode
# single: scope to DEFAULT_TENANT_ID when no X-Tenant-Id header is present
# multi: iterate tenants from DB and send X-Tenant-Id for each in cron/automation
TENANT_MODE=single

# Fallback tenant id used when no X-Tenant-Id is provided (single-tenant mode)
DEFAULT_TENANT_ID=default
```

- Ensure `docker-compose.yml` references it via `env_file: - .env.compose` (see snippet above).
- Rebuild/recreate containers so the env vars are available to Apache/PHP and cron.

Notes:

- PHP’s Dotenv won’t override existing environment variables. If `API_KEY` is set via Compose, `$_ENV['API_KEY']` will be available to the app and cron jobs (the entrypoint propagates it to curl requests).
- Keep sensitive values out of version control. Prefer `.env.compose` kept locally or managed via secrets.
- If you keep an application `.env` for other settings, ensure `API_KEY` there matches or simply omit it to avoid confusion.

## Database Initialization and Tenants

This service persists configuration in Postgres, including per-tenant bridge settings in
`bridge_configs`.

- Initialize the database schema once (inside or outside the container):
  - Use `database/bridge_schema.sql` or the provided helper script in `scripts/setup_bridge_database.sh`.
- Create tenants and set per-tenant configs via the Admin UI or Admin API.
  - The app will read tenant-specific settings from `bridge_configs` based on the `X-Tenant-Id` header.
  - When no `X-Tenant-Id` is provided, `DEFAULT_TENANT_ID` is used (single-tenant mode).

In multi-tenant mode (`TENANT_MODE=multi`), automation and cron jobs iterate tenants from the
database and call APIs with the appropriate `X-Tenant-Id` for each tenant.

## Deployment Commands

### Full Rebuild (Recommended)
```bash
# Clean rebuild with no cache
docker compose build --no-cache
docker compose up -d
```

### Standard Operations
```bash
# Start services
docker compose up -d

# Stop services
docker compose down

# View logs
docker compose logs -f

# Shell access
docker exec -it portico_outlook bash
```

## Health Checks

### Container Status
```bash
# Check running containers
docker ps -f name=portico_outlook

# View container processes
docker exec portico_outlook ps aux
```

### Service Verification
```bash
# Test web service
curl -I http://localhost:8082/sync/pending-items

# Check cron jobs
docker exec portico_outlook crontab -u www-data -l

# View application logs
docker logs portico_outlook --tail 50
```

### API Health Check
```bash
# Basic connectivity (include API key)
curl -H "api_key: change-me-strong-random" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/health

# Test specific endpoint
curl -X POST -H "api_key: change-me-strong-random" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/sync-deletions
```

Note: All protected endpoints require both `api_key` and `X-Tenant-Id` headers. If `X-Tenant-Id`
is omitted, the app falls back to `DEFAULT_TENANT_ID` (single-tenant behavior). Webhook
validation GETs remain unauthenticated by design.

## Troubleshooting

### Common Issues

#### Container Won't Start
- Check port 8082 availability
- Verify network `portico_internal` exists
- Review build logs for errors

#### Database Connection Errors
- Verify `.env` database settings
- Check network connectivity to database
- Confirm PostgreSQL extensions installed

#### Cron Jobs Not Running
- Check cron daemon: `docker exec portico_outlook ps aux | grep cron`
- View cron logs: `docker exec portico_outlook tail /var/log/cron.log`
- Verify www-data crontab: `docker exec portico_outlook crontab -u www-data -l`

#### Microsoft Graph API Issues
- Verify Graph API credentials in `.env`
- Check Graph API permissions in Azure
- Test connectivity to Microsoft 365

### Log Locations
- **Apache Logs**: `/var/log/apache2/`
- **PHP Logs**: Available via `docker logs`
- **Cron Logs**: `/var/log/cron.log`
- **Application Logs**: Custom logging in application

### Debug Mode
```bash
# Enable Xdebug (already configured)
# Check Xdebug status
docker exec portico_outlook php -m | grep -i xdebug

# View PHP configuration
docker exec portico_outlook php -i | grep -i xdebug
```

## Performance Considerations

### Resource Usage
- **Memory**: ~50-100MB per container
- **CPU**: Low usage except during sync operations
- **Disk**: Logs and temporary files

### Scaling
- Single container handles multiple room calendars
- Cron jobs run sequentially to avoid conflicts
- Database connections pooled efficiently

### Monitoring
- Use `/sync/stats` for sync health
- Use `/polling/stats` for polling health
- Monitor Docker container metrics
- Track database connection usage

## Security Notes

### Container Security
- Runs as non-root user (www-data)
- Limited system access
- Network isolation via Docker networks

### API Security
- API key authentication (header: `api_key`)
- Rate limiting recommended for production
- HTTPS termination recommended (reverse proxy)

### Data Security
- Environment variables for sensitive data
- No secrets in container images
- Database credentials properly secured

### Dashboard Authentication
- The dashboard prompts for the API key on first load and stores it in the browser.
- Press Ctrl+K on the dashboard to update the stored key.
