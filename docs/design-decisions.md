# Design decisions

The full version of the notes summarised in the [README](../README.md#design-decisions), plus the security controls.

## Preventing double-booking without exclusion constraints

PostgreSQL could enforce "no overlapping ranges per resource" with an exclusion constraint. MySQL cannot, and a plain check-then-insert races: two sales people can both see the hall free and both insert. `ResourceScheduler::assign()` therefore runs one transaction with these steps:

1. `SELECT … FOR UPDATE` on the booking row (only that row; the customer is read without a lock).
2. `SELECT … FOR UPDATE` on the **resource** row. Every writer that can create an overlap on that resource has to take this lock first, so those writers queue up one at a time.
3. Run the overlap query against bookings that still hold the resource (`inquiry`, `quoted`, `confirmed`). The test is `existing.setup_start < new.end + buffer AND existing.teardown_end > new.start − buffer`.
4. Insert the assignment, write the activity log entry, and commit.

The connection uses `READ COMMITTED`, so the overlap query after the lock sees rows committed by the transaction that held the lock before. This matters because `createQuote` and `updateQuote` read the catalog before they reach the resource lock; under `REPEATABLE READ` that earlier read would fix the snapshot and the overlap query could miss a hold committed while the transaction waited. Windows are half-open, so with a zero buffer a 06:00–12:00 hold and a 12:00–18:00 hold do not conflict.

Locks are always taken in the same order: the booking first, then resources by ascending id. No transaction locks two bookings. Together these rules mean the lock graph cannot form a cycle.

Changing a booking's window and confirming a booking also lock the resources the booking holds, then re-run the check. `ResourceConflictTest::testResourceRowLockMakesConcurrentAssignmentsWait` holds the lock from a second connection to prove the wait happens.

## Inquiries hold resources too

A tentative hold from a phone call is still a hold. The calendar shows these holds hatched, so sales can see them and release them. The alternative, only blocking confirmed bookings, would let two quotes for the same evening both reach the deposit stage.

## The deposit gates confirmation; there is no separate deposit due date

The business asks for the deposit to "lock in" a date. If the deposit is still unpaid when the balance falls due, the whole outstanding amount becomes overdue. `deposit_percent` is copied onto each booking, so changing the config later does not move the goalposts on existing quotes.

## Server-side totals only

The browser never submits a total. Only catalog ids, quantities and (for owners) unit-price overrides are posted. The JS preview mirrors `QuoteCalculator` for convenience, and a test posts a tampered `total` to show it is ignored.

## Sessions in the database instead of PHP's file handler

Storing sessions in the database makes revocation possible: deactivating a user or changing their password deletes their sessions. It also makes regeneration on login explicit, and it lets the HTTP tests drive real sessions without `headers_sent()` problems.

## Domain errors as exceptions plus one middleware

`InvalidTransition`, `ResourceConflict` and `ValidationFailed` carry messages written for staff. A single middleware (`DomainErrorMiddleware`) turns them into "redirect back with a flash message and the old input", or JSON 422 for `/api/*`. Every form behaves the same way and controllers contain no try/catch boilerplate.

## No ORM or DI framework

The query paths are few and specific (the overlap query, receivables, the month grid). Hand-written SQL in small repositories keeps every query visible and parameterised. The container is ~70 lines, and the wiring file reads top to bottom. `config/container.php` holds every service factory in one file, and the HTTP tests build the app through the same `config/app.php`.

## Database details

- **Money.** Every amount is an integer number of rupiah in a `BIGINT` column. Floats never appear in PHP, SQL or JS. The deposit rounds *up* to the next rupiah.
- **Assignment history.** `booking_resources` keeps released assignments for history. A generated `active_flag` (1 while held, NULL once released) plus `UNIQUE (booking_id, resource_id, active_flag)` acts as a partial unique index. A resource can be held only once per booking, but can be released and re-assigned later.
- **Hashed keys.** Sessions are stored as SHA-256 hashes of the session id. Rate-limit keys (email+IP, IP) are stored hashed as well.
- **Migrations** are plain `.sql` files in `database/migrations/`, applied in order by `bin/migrate` and recorded in `schema_migrations`.

## Security controls

- Parameterised queries everywhere (`PDO::ATTR_EMULATE_PREPARES = false`). Sort columns come from a whitelist.
- Passwords use `password_hash`/`password_verify` with rehash-on-login. Unknown emails still run a dummy bcrypt verify, so response timing does not reveal which accounts exist.
- Login is throttled per email+IP. While locked, even the correct password is refused. Each attempt is written to the counter before the limit is checked, so a burst of parallel requests cannot get more guesses than the limit allows (the same applies to the inquiry form).
- CSRF tokens are compared with `hash_equals`. Logout is POST only.
- Session cookies are `HttpOnly` and `SameSite=Lax`, plus `Secure` over HTTPS. The session id is regenerated on login, and the pre-login id is deleted.
- CSP: `default-src 'self'` with no `unsafe-inline` for scripts or styles. A test scans templates for inline scripts, styles and event handlers. Responses also send `frame-ancestors 'none'`, `X-Frame-Options: DENY`, `Referrer-Policy` and `nosniff`.
- In production the error pages are generic. The details go to Monolog. `APP_DEBUG` cannot be enabled in production.
- `X-Forwarded-For` is ignored for rate limiting, because it is spoofable without a trusted proxy list.

## Test coverage

The suite has 235 tests.

- **Unit:** pricing and totals, money parsing, the payment schedule and overdue boundaries, the full state transition table, the role × ability matrix, time windows, and CSV escaping.
- **Integration:** runs against real MySQL/MariaDB. It covers conflict detection including the buffer boundary and edge-touching windows, a second connection holding the row lock, a hold committed by another connection mid-transaction (the reason for `READ COMMITTED`), the rate limiter counting parallel attempts, cancellation releasing resources, price snapshots, the payment rules, and migrations applying cleanly to an empty database.
- **HTTP:** requests go through the full Slim middleware stack. It covers CSRF rejection, role 403s, login throttling and its expiry, session regeneration, cookie flags, security headers, the inquiry honeypot and rate limit, validation messages, and the quote → payment → confirm flow.
