# Configuration Reference

## Environment Variables (Core)

| Variable | Purpose | Notes |
|----------|---------|-------|
| DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS | Database connectivity | PostgreSQL required |
| APP_BASE_URL | Base URL for callbacks & linking | Include protocol |
| API_KEY | Global/admin key (fallback) | Prefer per-tenant keys via X-API-Key header |
| DEFAULT_TENANT_ID | Default scope when header absent | Single-tenant mode convenience |

## Outlook

Bridge configuration is stored per-tenant in the database via the Admin API:

```json
{
  "client_id": "your-graph-app-client-id",
  "client_secret": "your-client-secret",
  "tenant_id": "your-azure-ad-tenant-id",
  "group_id": "optional-room-group-discovery-anchor",
  "timezone": "Europe/Oslo",
  "webhook_client_secret": "a-strong-secret-for-webhook-validation"
}
```

Use `PUT /admin/tenants/{tenantId}/configs/outlook` to configure.

## Booking System

Bridge configuration is stored per-tenant in the database via the Admin API:

```json
{
  "api_base_url": "http://your-booking-api/",
  "system_login": "your-login-name",
  "system_password": "your-password",
  "system_domain": "your-domain",
  "system_proxy": "none",
  "throw_on_api_failure": true,
  "timezone": "Europe/Oslo",
  "defaults": {
    "activity_id": 1,
    "agegroup_id": 1,
    "targetaudience_id": 7
  }
}
```

Use `PUT /admin/tenants/{tenantId}/configs/booking_system` to configure.

## Feature Flags / Behavior

| Variable | Effect | Default |
|----------|--------|---------|
| CLEANUP_DAYS | Log retention days | 30 |
| ADMIN_IP_ALLOWLIST | Comma list of IP/CIDR for admin | unset |

## Security Hardening (See security_hardening.md)

Add reverse proxy controls: rate limiting, size limits, TLS, IP allowlist for admin & webhook.

## Per-Tenant Configuration

Bridge configurations are stored in the database and managed via the Admin API:

### Managing Tenant Configurations

```bash
# Create/update bridge configuration for a tenant
PUT /admin/tenants/{tenantId}/configs/{bridgeName}
Content-Type: application/json

{
  "client_id": "...",
  "client_secret": "...",
  // ... bridge-specific configuration
}

# Get bridge configuration for a tenant
GET /admin/tenants/{tenantId}/configs/{bridgeName}
```

### Configuration Storage

- Configurations are stored in the `bridge_configs` database table
- Each tenant can have different configurations for each bridge type
- Sensitive values (passwords, secrets) are stored encrypted
- Environment variables are now used only for system-level settings (database, global X-API-Key, etc.)

---

Operational parameters & cron guidance → `operations.md`.
