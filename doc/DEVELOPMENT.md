# Development Guide

Short, practical steps to develop and run the Calendar Bridge locally.

## 1) Setup

- Install PHP 8.4+, Composer, and PostgreSQL
- Install deps: `composer install`
- Copy env: `cp .env.example .env` and configure at minimum:
  - DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
  - API_KEY, APP_BASE_URL
  - OUTLOOK_CLIENT_ID/SECRET/TENANT_ID (if testing Outlook)
- Initialize DB: `./setup_bridge_database.sh` (or apply `database/bridge_schema.sql`)

## 2) Run

- PHP built-in server (dev):
  - `php -S localhost:8082 -t . index.php`
- Or Docker:
  - `docker-compose up -d`

## 3) Useful Endpoints (auth: header `api_key: <API_KEY>`)

- Health: `/health`, `/health/system`, `/bridges/health`
- Discovery: `/bridges/{bridge}/available-resources`, `/bridges/{bridge}/available-groups`
- Calendars: `/bridges/{bridge}/calendars`
- Resource items: `/bridges/{bridge}/resources/{resourceId}/calendar-items`
- Sync mgmt: `/bridges/sync-stats[/{bridge}]`, `/bridges/cancelled-events[/{bridge}]`
- Webhook: `/bridges/webhook/{bridge}`, subscriptions `/bridges/{bridge}/subscriptions`

See the routes table in `README_BRIDGE.md` for the full list.

## 4) Scripts

- `./test_bridge.sh` – basic API smoke
- `./test_sync_method.sh` – sync method checks
- `./test_sync_status.sh` – status/health checks
- `./scripts/enhanced_process_deletions.sh` – batch deletion handling

## 5) Add a New Route

- Implement controller method under `src/Controller`
- Register route in `index.php`
- Update the 404 `available_endpoints` list
- Update the routes table in `README_BRIDGE.md`
- Ensure API key middleware expectations (header `api_key`) are documented in README

## 6) Add a New Bridge

- Create `src/Bridge/MyBridge.php` implementing the required interface/contract
- Register it in the container via `bridgeManager` in `index.php`
- Add any required env vars to `.env.example`
- If supporting webhooks, ensure `/bridges/webhook/{bridge}` covers it

## 7) Database Changes

- Add SQL migration under `database/migrations/NNN_description.sql`
- Test locally, document apply/rollback steps in your PR and `doc/MAINTENANCE.md`

## 8) Debugging Tips

- Logs: Monolog to stdout; increase verbosity temporarily if needed
- If `.env` is missing, API requests return a friendly JSON error with guidance
- For DB outages, health endpoints handle `db` being null gracefully
- Xdebug config exists under `build_config/xdebug.ini` if enabled in your PHP setup

## 9) Legacy Endpoints

- Optional compatibility routes gated by `ENABLE_LEGACY_WEBHOOKS=false` (default)
- Only enable in controlled migrations; keep docs in sync when toggled
