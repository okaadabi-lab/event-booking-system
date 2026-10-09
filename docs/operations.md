# Operations reference

HTTP endpoints, configuration and maintenance commands. Setup steps are in the [README](../README.md#getting-started).

## HTTP endpoints

All pages are server-rendered. Every non-GET request needs the session's CSRF token (form field `_csrf` or `X-CSRF-Token` header).

| Method & path | Who | Purpose |
|---|---|---|
| `GET /health` | public | `{"status":"ok","database":"ok","latency_ms":1}`. Returns 503 if the DB is unreachable. |
| `GET/POST /inquiry` | public | Inquiry form. Honeypot plus 5 submissions/hour per IP (429 with `Retry-After`). |
| `GET/POST /login`, `POST /logout` | public | Session login with per email+IP lockout. |
| `GET /today?range=today\|week\|fortnight` | sales, owner, finance | Run sheet with readiness flags. |
| `GET /calendar?month=YYYY-MM` | all staff | Resource × day occupancy grid. |
| `GET /bookings?status&from&to&q&sort&dir&page` | all staff | Filterable, sortable, paginated list. |
| `GET /bookings/new`, `POST /bookings` | sales, owner | Quote builder and save. |
| `GET /bookings/{id}` / `…/edit`, `POST /bookings/{id}` | staff / sales, owner | Detail page, then edit while `inquiry` or `quoted`. |
| `POST /bookings/{id}/quote\|confirm\|complete\|cancel` | sales, owner | Guarded transitions. |
| `POST /bookings/{id}/resources`, `…/resources/{rid}/release` | sales, owner | Assign or release under lock. |
| `GET /api/availability?start&end&exclude` | all staff | JSON availability preview for the quote builder. |
| `POST /bookings/{id}/payments` | finance, owner | Record a payment or refund. |
| `GET /payments`, `GET /payments/export?month=YYYY-MM` | finance, owner | Receivables and monthly CSV. |
| `GET /setup`, `POST /setup/{packages,addons,resources,users}` | owner | Catalog, resources and staff accounts. |

## Environment variables

| Variable | Default | Meaning |
|---|---|---|
| `APP_ENV` | `production` | `development`, `production` or `testing`. |
| `APP_DEBUG` | `false` | Show exception details on 500 pages. Ignored in production. |
| `APP_TIMEZONE` | `Asia/Jakarta` | Business timezone for all DATETIME values. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | | Connection settings. |
| `DB_TEST_DATABASE` | `event_booking_test` | Test database. Must end in `_test` because the suite truncates it. |
| `SESSION_SECURE_COOKIE` | `auto` | `auto` sets `Secure` when the request is HTTPS. |
| `DEPOSIT_PERCENT` | `30` | Deposit required before confirmation. Copied onto each booking. |
| `BALANCE_DUE_DAYS_BEFORE_EVENT` | `14` | Balance due date offset. |
| `RESOURCE_BUFFER_MINUTES` | `120` | Changeover time required between two holds on one resource. |
| `LOGIN_MAX_FAILURES`, `LOGIN_WINDOW_MINUTES` | `5`, `15` | Login lockout per email+IP. |
| `INQUIRY_MAX_PER_HOUR` | `5` | Public inquiries per IP per hour. |
| `LOG_PATH`, `LOG_LEVEL` | `storage/logs/app.log`, `info` | Monolog target. |

## Maintenance commands

- `php bin/migrate` applies `database/migrations/*.sql` in order; `php bin/migrate --fresh` drops and rebuilds the schema.
- `php bin/seed` loads fictional demo data dated relative to today.
- `php bin/prune` clears idle sessions and old rate-limit rows. Schedule it with cron; nothing runs it automatically.

Neither `--fresh` nor `bin/seed` will run with `APP_ENV=production`.
