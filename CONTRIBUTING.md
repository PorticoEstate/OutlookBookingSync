# Contributing Guide

A concise guide for developing and maintaining the OutlookBookingSync bridge.

## Prerequisites
- PHP 8.4+
- Composer
- PostgreSQL 12+
- Docker (optional)

## Getting Started
- Fork/clone the repo
- Install deps: `composer install`
- Copy env: `cp .env.example .env` and fill values (API_KEY, DB_*, OUTLOOK_*, APP_BASE_URL)
- Start locally via PHP built-in server or Docker (see `doc/DEVELOPMENT.md`)

## Branching and PRs
- Default branch: `bridge`
- Feature branches: `feat/<short-name>`; fixes: `fix/<short-name>`
- PR checklist:
  - [ ] PHP syntax check passes: `php -l <changed-php-files>`
  - [ ] No secrets committed; `.env` not tracked
  - [ ] Docs updated if behavior or routes change
  - [ ] `.env.example` updated for any new env vars
  - [ ] 404 help list in `index.php` updated if new endpoints are added
  - [ ] README routes table updated if routes change
  - [ ] SQL migrations added if schema changes (see below)

## Code Style and Conventions
- Follow PSR-12
- Prefer strict types and explicit return types
- Use dependency injection (services/controllers registered in `index.php`)
- Logging via Monolog; include context (bridge, mapping id, etc.)
- API responses: JSON; include `success` or `error` and actionable messages
- Security: all API endpoints protected by `ApiKeyMiddleware` (header `api_key`)

## Routes and Controllers
- Define routes in `index.php`
- Add controller methods in `src/Controller/*`
- Keep the 404 `available_endpoints` list helpful and current
- After adding/modifying routes, update the routes table in `README_BRIDGE.md`

## Database Migrations
- Place SQL in `database/migrations/` named `NNN_description.sql`
- Keep `database/bridge_schema.sql` as the baseline
- Document apply steps in PR description and `doc/MAINTENANCE.md` if needed

## Commits
- Use clear, imperative messages
  - `feat: add groups endpoint filter params`
  - `fix: gate legacy webhook routes behind env flag`
  - `docs: update routes quick reference`

## Testing
- Use provided scripts for smoke tests: `test_bridge.sh`, `test_sync_method.sh`, `test_sync_status.sh`
- Prefer adding quick script-based checks for new endpoints

## Backwards Compatibility
- Legacy webhook endpoints are gated by `ENABLE_LEGACY_WEBHOOKS=false` by default
- If you re-introduce legacy behavior, document it and update `.env.example`
