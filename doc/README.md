# Documentation Index

Canonical documentation set for the Generic Calendar Bridge. Each file is intentionally focused; the root `README.md` stays slim and links here.

| Area | File | Purpose |
|------|------|---------|
| Architecture & Concepts | architecture.md | Core components, data model, flows, ownership rules |
| Usage Flows | usage.md | How to create mappings, run syncs, deletion handling, troubleshooting |
| Configuration | configuration.md | Environment variables, per-tenant configuration, feature flags |
| Operations & Monitoring | operations.md | Cron schedules, health endpoints, alerts, housekeeping, KPIs |
| Development Guide | development.md | Local setup, extension patterns, testing, contribution workflow |
| Booking System Adapter | booking_system_adapter.md | Required endpoints, payload shapes, webhook/polling strategy |
| Multi-Tenancy | multi_tenancy.md | Tenant resolution, API key hierarchy, admin UI flows |
| Security Hardening | security_hardening.md | Threat model, auth layers, hardening checklist |
| API Endpoints | api_endpoints.md | Canonical list of REST endpoints with brief descriptions |
| Changelog | ../CHANGELOG.md | Release & notable changes history |

## Quick Start Navigation

Getting started: usage.md

Need to add a new calendar system? development.md (see "Extending Bridges")

Booking system implementer? booking_system_adapter.md

Production operations & cron: operations.md

Security review: security_hardening.md + architecture.md#security

## Contribution Notes

1. Keep conceptual truth in exactly one file—link instead of duplicating.
2. If a section grows beyond ~150 lines, consider splitting to a new file and link it.
3. After updating docs, add an entry to CHANGELOG.md if the change affects users/operators.
4. Prefer relative links (no absolute Git URLs) for portability.
5. Run a markdown linter (if configured locally) before opening a PR.

## Adding a New Bridge (Checklist Extract)

- Create class extending `AbstractCalendarBridge`
- Implement discovery + event CRUD methods
- Register in DI container & BridgeManager
- Add minimal config keys to configuration.md
- Document any special constraints in architecture.md (Extension section)
- Add tests (unit for transforms, integration for API boundary if feasible)

## Terminology (Short Glossary)

- Mapping: Row defining relationship between two calendars/resources + sync_direction
- Ownership: Authority over event mutations based on sync_direction
- Deletion Queue: Intermediate queue holding candidate deletions for verification
- Subscription: Webhook registration (Outlook currently); polling optional alternative

## Legacy Docs

Legacy fragmented guides were consolidated on 2025-09-22; removed files should not be reintroduced. Use this index as the authoritative map.
