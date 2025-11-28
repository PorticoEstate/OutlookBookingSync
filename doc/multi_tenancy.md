# Multi-Tenancy Guide

This document explains how the bridge isolates and scopes data & operations per tenant. It supplements `architecture.md` (concepts) and `configuration.md` (env vars) with practical guidance.

## Tenancy Models

| Mode | Description | Activation |
|------|-------------|------------|
| Single Tenant | All requests implicitly use `DEFAULT_TENANT_ID` (or `default`). | Omit `X-Tenant-Id` header |
| Explicit Multi‑Tenant | Each request supplies a tenant id header to scope operations. | Send `X-Tenant-Id: <tenant>` |
| Hybrid | Some clients rely on default, others pass header. | Provide `DEFAULT_TENANT_ID` + selective headers |

## Tenant Resolution Flow

1. `TenantResolverMiddleware` checks route arguments (future extensibility)
2. Falls back to `X-Tenant-Id` or `x-tenant-id` header
3. Falls back to `DEFAULT_TENANT_ID` env var (else `'default'`)
4. Injects resolved id as request attribute `tenant_id`

```php
// Simplified logic
$tenantId = request.header('X-Tenant-Id') ?? $_ENV['DEFAULT_TENANT_ID'] ?? 'default';
```

All downstream controllers fetch this attribute as authoritative scope value.

## Authentication Strategies

| Strategy | Storage | Recommendation |
|----------|---------|----------------|
| Global API Key (`API_KEY`) | .env | Legacy / fallback only |
| JSON Map (`TENANT_API_KEYS_JSON`) | .env (JSON) | Small, static environments |
| Database Keys (hashed) | `tenant_api_keys` table | Preferred for rotation & audit |

Order of validation (first success wins): JSON map → DB hashed key → global key.

Rotate per-tenant keys with `POST /admin/tenants/{tenantId}/keys/rotate` (plaintext returned once; store securely).

## Admin APIs & Tenant Lifecycle

| Phase | Endpoint(s) | Notes |
|-------|-------------|-------|
| Provision | `POST /admin/tenants` | Provide `id`, `name` |
| Inspect | `GET /admin/tenants`, `GET /admin/tenants/{id}` | Include inactive via query param |
| Update | `PUT /admin/tenants/{id}` | Rename or toggle `active` |
| Rotate Key | `POST /admin/tenants/{id}/keys/rotate` | Returns new key once |
| Per-Bridge Config | `PUT/GET /admin/tenants/{id}/configs/{bridge}` | Arbitrary JSON payload |
| Decommission | `DELETE /admin/tenants/{id}` | Ensure sync queues empty first |

Inactive tenants should be denied via external gateway (the bridge currently trusts provided tenant id for auth selection; future enhancement could enforce active flag at middleware layer).

## Data Isolation

Isolation relies on tenant id columns in mutable domain tables (example snippet—verify actual schema before production hardening):

| Table | Isolation Field | Notes |
|-------|-----------------|-------|
| `bridge_mappings` | `tenant_id` | Event pair ownership & sync status |
| `bridge_queue` | `tenant_id` | Pending webhook & sync tasks |
| `bridge_subscriptions` | `tenant_id` | Provider subscription metadata |
| `bridge_sync_logs` | `tenant_id` | Audit of sync operations |
| `outlook_sync_alerts` | `tenant_id` | Alerting scope |
| `tenant_api_keys` | `tenant_id` | Auth data (hashed) |

Ensure all SELECT / UPDATE / DELETE statements include tenant filters (controllers and services already pass derived tenant id). Conduct periodic queries to detect orphan rows missing `tenant_id`.

### Cross-Tenant Leakage Prevention Checklist

- [ ] Always supply `X-Tenant-Id` in multi-tenant contexts
- [ ] Validate API response objects contain only tenant-scoped records
- [ ] Ensure log aggregation includes tenant id for correlation
- [ ] Restrict admin endpoints (role-based, future enhancement)
- [ ] Avoid embedding raw tenant secrets in logs

## Operational Patterns

### Cron Jobs Per Tenant

Option A (single script loops tenants):

```bash
for t in tenantA tenantB; do
  curl -s -H "X-API-Key: $(getKey $t)" -H "X-Tenant-Id: $t" \
    -X POST http://bridge/bridges/sync/booking_system/outlook \
    -H 'Content-Type: application/json' \
    -d '{"start_date":"$(date +%F)","end_date":"$(date -d "+7 days" +%F)"}'
done
```

Option B (distinct cron entries) for isolation & differential cadence.

### Webhook Handling

Outbound provider subscriptions (Outlook) must embed tenant context indirectly. Current strategy: subscription stored with `tenant_id`; incoming notifications are correlated using subscription id → tenant lookup before enqueuing.

### Queue Processing

`/bridges/process-queue` accepts global invocation; internally it processes rows partitioned by tenant. To isolate failure blast radius invoke per tenant with tenant-specific X-API-Key.

## Multitenant Testing Matrix

| Scenario | Expectation |
|----------|-------------|
| Missing header & default set | Request runs under default tenant |
| Missing header & no default | Falls back to `default` (document clearly) |
| Invalid tenant id | Auth fails if no matching key; otherwise acts as empty tenant scope |
| Key rotation | Old key rejected immediately |
| Cross-tenant mapping access attempt | 404 / empty list (filtered) |

## Hardening Recommendations

1. Enforce active tenant check in middleware (future patch) to block inactive tenants early.
2. Add rate limiting keyed by `tenant_id` at reverse proxy.
3. Centralize audit logging (tenant, endpoint, latency, outcome).
4. Add optional request quota per tenant (daily event operations limit).
5. Encrypt sensitive per-tenant bridge config secrets at rest.

## Future Enhancements

- Tenant-aware metrics export (Prometheus labels)
- Tenant-level circuit breakers (pause sync on repeated failure)
- Soft delete + retention policy for tenant removal

---

For implementation details see middleware: `src/Middleware/TenantResolverMiddleware.php` and admin APIs in `AdminController`.
