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
  --build-arg http_proxy=http://proxy.company.com:8080 \
  --build-arg https_proxy=http://proxy.company.com:8080
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

# Microsoft Graph API
OUTLOOK_CLIENT_ID=your_client_id
GRAPH_CLIENT_SECRET=your_client_secret
GRAPH_TENANT_ID=your_tenant_id
GRAPH_USER_PRINCIPAL_NAME=user@domain.com

# Optional Security
API_KEY=your_api_key
```

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
    networks:
      - portico_internal
```

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
# Basic connectivity
curl http://localhost:8082/sync/stats

# Test specific endpoint
curl -X POST http://localhost:8082/sync/populate-mapping
```

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
- Optional API key authentication
- Rate limiting recommended for production
- HTTPS termination recommended (reverse proxy)

### Data Security
- Environment variables for sensitive data
- No secrets in container images
- Database credentials properly secured
