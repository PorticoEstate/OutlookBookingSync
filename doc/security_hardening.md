# Security Hardening Guide

This guide provides actionable steps to strengthen the security posture of the Generic Calendar Bridge deployment. It complements brief notes in `architecture.md` and `configuration.md`.

## Threat Model Snapshot

| Vector | Risk | Mitigation |
|--------|------|------------|
| Stolen API key | Unauthorized data access | Per-tenant rotation, short lifetime, IP allow list |
| Webhook spoofing | False sync / deletion | Provider validation, shared secret, IP filtering |
| Queue poisoning | Malicious payloads cause drift | Input validation, serialization constraints, size limits |
| Excessive sync load | DoS / resource exhaustion | Rate limiting, batch size caps, circuit breakers |
| Event data leakage in logs | Compliance breach | PII scrubbing, structured redaction |
| Inactive tenant reuse | Data exposure | Disable & enforce active tenant flag in middleware |
| Lateral tenant traversal | Cross data access | Tenant scoping + row-level checks |
| Subscription expiry | Missed changes | Renewal cron + alerting on subscription age |

## X-API-Key Management

1. Prefer DB-backed, hashed per-tenant keys (`tenant_api_keys`).
2. Rotate regularly: `POST /admin/tenants/{id}/keys/rotate` and distribute securely.
3. Never log plaintext keys; store only bcrypt/argon2 hashes.
4. Maintain issuance audit trail (timestamp, rotated_by). Add if absent.
5. Expire global `API_KEY` once all tenants migrated to X-API-Key headers.

### Rotation Playbook

| Step | Action |
|------|--------|
| 1 | Admin rotates via endpoint, captures plaintext once |
| 2 | Update dependent systems (CI secrets, clients) |
| 3 | Smoke test authorized requests |
| 4 | Revoke old secret (immediate—already invalid) |
| 5 | Log rotation metadata (manual until automated) |

## Webhook Security

| Control | Recommendation |
|---------|---------------|
| Validation Token | Support GET `validationToken` (already implemented) |
| Signature | Add HMAC header validation for booking system → bridge (future) |
| IP Filtering | Restrict `/bridges/webhook/outlook` to Microsoft Graph ranges at reverse proxy |
| TLS | Enforce HTTPS with modern ciphers; redirect HTTP → HTTPS |
| Retry Handling | Idempotent processing using composite identifiers |
| Rate Limiting | 429 bursty webhook floods |

### Suggested Nginx Snippet

```nginx
location /bridges/webhook/ {
  limit_req zone=webhook burst=20 nodelay;
  # Optional: allowlist provider IP blocks
  # if ($remote_addr !~* (20\.\d+\.\d+\.\d+)) { return 403; }
  proxy_pass http://php-backend;
}
```

## Transport & Headers

| Header | Purpose | Action |
|--------|---------|--------|
| Strict-Transport-Security | Enforce HTTPS | `max-age=31536000; includeSubDomains; preload` |
| Content-Security-Policy | Mitigate XSS (dashboard) | Restrictive script sources |
| X-Content-Type-Options | MIME sniffing | `nosniff` |
| X-Frame-Options | Clickjacking | `DENY` |
| Referrer-Policy | Data leakage | `strict-origin-when-cross-origin` |

Serve static dashboard assets with these headers set at the web server layer.

## Input Validation & Payload Limits

| Area | Recommendation |
|------|---------------|
| JSON Body Size | Cap with reverse proxy (e.g., 512 KB) |
| Date Parameters | Enforce ISO 8601 / fallback rejection |
| Batch Sizes | Clamp `batch_size` in queue processors (e.g., 1–100) |
| Mapping Creation | Validate `sync_direction` enumerations |
| Webhook Queue | Reject unknown bridge names early |

## Rate Limiting & Abuse Controls

Implement tenant-scoped rate limits (e.g., 200 sync invocations / 5 minutes) at gateway.

Fallback soft limit approach (application-level): track counts in Redis (future enhancement). Exceeding thresholds returns 429 with `Retry-After`.

## Logging & PII Hygiene

| Principle | Application |
|-----------|------------|
| Avoid sensitive fields | Exclude participant emails from INFO logs |
| Structured context | Log JSON: tenant, endpoint, latency, operation, status |
| Redact tokens | Mask secrets in error traces |
| Error grouping | Hash stack traces for deduplication |

Centralize logs (ELK / Loki) & attach tenant dimension for anomaly detection.

## Queue & Deletion Integrity

| Risk | Control |
|------|---------|
| Duplicate deletion tasks | De-dup by (tenant, event_id, bridge) composite |
| Orphan queue rows | Periodic sweep job with age > X hours |
| Poison payload (arbitrary fields) | Strict schema decode before enqueue |

## Subscription Renewal Reliability

Monitor upcoming expirations: trigger alert when `expires_in_minutes < 60` (Outlook). Automate `/maintenance/renew-subscriptions` every 30 minutes.

## Deployment Hardening Checklist

- [ ] All traffic forced to HTTPS
- [ ] Reverse proxy rate limiting enabled
- [ ] Webhook endpoint IP filtered or HMAC signed
- [ ] Default global API key removed
- [ ] Per-tenant keys rotated in last 90 days
- [ ] Logs free of plaintext secrets
- [ ] CSP applied to dashboard
- [ ] Regular backups of database (encrypted at rest)
- [ ] Alerting for high 5xx or auth failures per tenant
- [ ] Subscription renewal cron active

## Incident Response Quick Steps

| Scenario | Immediate Action | Follow-Up |
|----------|------------------|-----------|
| Key Leak | Rotate tenant key, invalidate sessions | Audit logs, notify tenant |
| Webhook Flood | Temporarily block offending IPs | Add stricter rate rules |
| Data Drift | Pause sync (`sync_enabled=false`) | Run targeted reconciliation |
| Subscription Lapse | Manual renewal via maintenance endpoint | Add proactive alert rule |

## Future Security Enhancements

- mTLS between internal services
- OPA/Rego policy checks for admin actions
- Tenant-level encryption keys (envelope pattern)
- Structured tracing with span-level redaction

---

For a high-level overview see `architecture.md`; for tenancy specifics see `multi_tenancy.md`.
