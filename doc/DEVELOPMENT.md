# Development Guide

Short, practical steps to develop and run the Calendar Bridge locally.

## 1) Setup

- Install PHP 8.4+, Composer, and PostgreSQL
- Install deps: `composer install`
- Copy env: `cp .env.example .env` and configure at minimum:
  - DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
  - API_KEY, APP_BASE_URL
  - OUTLOOK_CLIENT_ID/SECRET/TENANT_ID (if testing Outlook)
- Initialize DB: `scripts/setup_bridge_database.sh` (or apply `database/bridge_schema.sql`)

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

- `./scripts/setup_bridge_database.sh` – Initialize the database schema
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


## Query plan validation (optional, recommended)

To verify that indexes are used efficiently, you can run EXPLAIN ANALYZE on representative queries:

1) Ensure environment variables for Postgres are set (see .env or README):

```bash
export DB_HOST=localhost
export DB_PORT=5432
export DB_NAME=calendar_bridge
export DB_USER=bridge_user
export DB_PASS=bridge_password
```

2) Run the helper script (adjust variables as needed):

```bash
bash scripts/run_explain_plans.sh
```

Optional overrides:

```bash
TENANT_ID=acme HOURS_BACK=24 SOURCE_CAL=room1@company.com TARGET_CAL=123 bash scripts/run_explain_plans.sh
```

This executes `scripts/explain_plans.sql` with timing enabled and prints query plans to the console.

## 8) Debugging Tips

- Logs: Monolog to stdout; increase verbosity temporarily if needed
- If `.env` is missing, API requests return a friendly JSON error with guidance
- For DB outages, health endpoints handle `db` being null gracefully
- Xdebug config exists under `build_config/xdebug.ini` if enabled in your PHP setup


## 9) Legacy Endpoints

- Optional compatibility routes gated by `ENABLE_LEGACY_WEBHOOKS=false` (default)
- Only enable in controlled migrations; keep docs in sync when toggled
