# Configuration Reference

## Environment Variables (Core)

| Variable | Purpose | Notes |
|----------|---------|-------|
| DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS | Database connectivity | PostgreSQL required |
| APP_BASE_URL | Base URL for callbacks & linking | Include protocol |
| API_KEY | Global/admin key (fallback) | Prefer per-tenant keys |
| DEFAULT_TENANT_ID | Default scope when header absent | Single-tenant mode convenience |

## Outlook

| Variable | Description |
|----------|-------------|
| OUTLOOK_CLIENT_ID | Graph app client id |
| OUTLOOK_CLIENT_SECRET | Client secret |
| OUTLOOK_TENANT_ID | Azure AD tenant id |
| OUTLOOK_GROUP_ID | Optional room/group discovery anchor |

## Booking System

| Variable | Description |
|----------|-------------|
| BOOKING_SYSTEM_API_URL | Base URL for booking API |
| BOOKING_SYSTEM_LOGIN / PASSWORD | Optional basic credentials |
| BOOKING_SYSTEM_DOMAIN | Domain scoping if required |
| BOOKING_SYSTEM_THROW_ON_FAILURE | Toggle strict error mode |

## Feature Flags / Behavior

| Variable | Effect | Default |
|----------|--------|---------|
| ENABLE_LEGACY_WEBHOOKS | Enables legacy webhook endpoints | false |
| CLEANUP_DAYS | Log retention days | 30 |
| ADMIN_IP_ALLOWLIST | Comma list of IP/CIDR for admin | unset |

## Security Hardening (See security_hardening.md)

Add reverse proxy controls: rate limiting, size limits, TLS, IP allowlist for admin & webhook.

## Per-Tenant Configuration

Stored in future `bridge_configs` or external secrets manager; move sensitive per-tenant credentials out of environment once scale increases.

---

Operational parameters & cron guidance → `operations.md`.
