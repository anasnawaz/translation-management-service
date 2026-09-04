# Translation Management Service

A Laravel 13 API for managing multi-locale application translations: keys, per-locale content, tags, full-text search, cursor pagination, and bulk per-locale JSON export. Translations are stored under a shared `key` (e.g. `homepage.hero.title`) with one `content` value per active `locale`, optional `tags`, and a free-text `description` on the key itself. Authentication is token-based via Laravel Sanctum. Built and tested on PHP 8.4 / Laravel 13.30, MySQL 8 in production, SQLite for the automated suite.

## Setup

Requires PHP 8.3+, Composer 2.x, and MySQL 8+ (SQLite ships with PHP and is used automatically for tests — no separate install needed).

```bash
git clone https://github.com/anasnawaz/translation-management-service.git translation-management-service
cd translation-management-service
composer install
cp .env.example .env
php artisan key:generate
```

Set `DB_*` in `.env` (defaults to database `translation_management`, user `root`, no password), create the database, then migrate:

```sql
CREATE DATABASE translation_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
php artisan migrate --seed
```

**Docker** (alternative — no local PHP or MySQL required):

```bash
cp .env.example .env
docker compose build
docker compose run --rm app php artisan key:generate
docker compose up -d
docker compose exec app php artisan migrate --seed
```

Runs at `http://localhost:8080`. `docker compose exec app php artisan test` and `docker compose exec app php artisan translations:generate 100000 --fresh` work the same as outside Docker; `docker compose down -v` also deletes the MySQL volume.

## Authentication

Every endpoint except `POST /api/register` and `POST /api/login` requires a Sanctum bearer token on every request:

```
Authorization: Bearer <token>
```

```bash
curl -X POST http://localhost:8000/api/register \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"name": "Ada Lovelace", "email": "ada@example.com", "password": "Password123", "password_confirmation": "Password123"}'
```

Returns `{"data": {"user": {...}, "token": "1|abcdef...", "token_type": "Bearer"}}`. `POST /api/login` returns the same shape. Tokens don't expire automatically (`config/sanctum.php`); revoke the current one with `POST /api/logout`.

## API Endpoints

| Method | URI | Auth | Description |
|---|---|---|---|
| POST | `/api/register` | No | Create a user account and return a bearer token |
| POST | `/api/login` | No | Authenticate and return a bearer token |
| GET | `/api/user` | Yes | Return the authenticated user |
| POST | `/api/logout` | Yes | Revoke the current access token |
| GET | `/api/translations` | Yes | List/search translations (cursor-paginated) |
| POST | `/api/translations` | Yes | Create a translation |
| GET | `/api/translations/{translation}` | Yes | Show a single translation |
| PUT/PATCH | `/api/translations/{translation}` | Yes | Update a translation |
| DELETE | `/api/translations/{translation}` | Yes | Delete a translation |
| GET | `/api/locales/{locale}/translations/export` | Yes | Export all translations for a locale as flat JSON |

`{locale}` in the export route is the locale's `code` (e.g. `en`), not its numeric ID. Full request/response reference: `docs/openapi.yaml` (OpenAPI 3.1 — open it in Swagger Editor, Postman, or Insomnia).

Create a translation (`key` and `tags` are normalized and validated against `^[a-z0-9._-]+$`; a duplicate `(key, locale)` pair returns `422`):

```bash
curl -X POST http://localhost:8000/api/translations \
  -H "Authorization: Bearer <token>" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"key": "homepage.hero.title", "locale": "en", "content": "Welcome!", "tags": ["web"]}'
```

List with cursor pagination (`sort_by`, `sort_direction`, and other filters must stay identical between requests; follow `meta.next_cursor` from the response as the `cursor` param):

```bash
curl "http://localhost:8000/api/translations?per_page=50&sort_by=created_at" \
  -H "Authorization: Bearer <token>" -H "Accept: application/json"
```

Export a locale's translations as a flat `{ "key": "content" }` object, optionally filtered by tag (an unknown/inactive locale returns `404`; an empty result returns `{}`, not `[]`):

```bash
curl "http://localhost:8000/api/locales/en/translations/export?tags[]=web" \
  -H "Authorization: Bearer <token>" -H "Accept: application/json"
```

## Design Decisions

**Normalized schema.** `translation_keys` and `translations` are separate tables — a key is stored once and reused across every locale via `translations.translation_key_id`. Adding a new locale is a data operation, not a migration: no schema change, no key duplication, and key uniqueness is enforced in exactly one place.

**Cursor pagination, not offset.** `GET /api/translations` uses `cursorPaginate()` instead of `paginate()`. Offset pagination gets slower the deeper you page — `OFFSET 50000` still has to scan and discard 50,000 rows — while a cursor encodes "where I left off" and stays a fast indexed lookup regardless of depth, which matters once the `translations:generate` command produces 100k+ rows. `created_at`/`updated_at` aren't unique columns, so sorting by either adds `id` as a secondary tie-breaker in the same direction, otherwise rows sharing a timestamp could be skipped or repeated across pages.

**Export bypasses Eloquent.** The export endpoint queries with `DB::table()`, not the `Translation` model, and streams the result via `response()->stream()` + a query-builder `cursor()`. Eloquent would hydrate a model object per row — for 100k+ translations that's 100k objects in memory at once just to read two columns. `cursor()` returns a `LazyCollection` that pulls one row at a time from the database connection, so memory stays flat regardless of dataset size.

**`ob_flush()`, not `ob_end_flush()`.** The streaming closure originally drained output buffers with `while (ob_get_level() > 0) { ob_end_flush(); }` — but `ob_end_flush()` *closes* a buffer, and this callback doesn't own every buffer that might be open (PHP's own `output_buffering` setting under php-fpm, or the capture buffer Laravel's test harness wraps around a streamed response). Closing a buffer it doesn't own aborts the response right after headers are already committed, which is why the bug looked like valid chunked headers followed by an empty body; the fix flushes with `ob_flush()` instead, which pushes buffered output to the client without closing anything.

**MySQL `FULLTEXT` with a `LIKE` fallback for tests.** SQLite, used only by the automated suite, can't create `FULLTEXT` indexes, so the migration creates one only when the connection driver is MySQL, and `applyContentSearch()` calls `whereFullText()` on MySQL or falls back to `LIKE '%...%'` otherwise — production search behavior is unchanged, SQLite just takes a different code path. The MySQL path was verified separately against a real MySQL instance, since SQLite can never reach that branch (see Testing).

**Duplicate `(key, locale)` prevention.** `TranslationService::create()` has two layers: a cheap `exists()` pre-check gives a fast, friendly `422` for the common case, and the `translations_key_locale_unique` database constraint is the actual guarantee, since two concurrent requests can both pass the pre-check before either inserts. The insert is wrapped in try/catch for `UniqueConstraintViolationException` specifically and converted to the same `422` — any other database exception still propagates — and the whole operation runs inside `DB::transaction()` so a failed insert never leaves an orphaned key or tag behind.

**Security.** Sanctum bearer tokens for auth; rate limiting at 5/min on `register`/`login` (keyed by email + IP) and 60/min on authenticated routes (keyed by user ID); login failures always return the same generic message regardless of whether the email exists, to prevent user enumeration; every mutating endpoint is backed by a `FormRequest` with explicit validation, including a `^[a-z0-9._-]+$` allow-list on `key` and each tag rather than a denylist.

## Performance

The export endpoint streams rows lazily via a query-builder `cursor()`, so
memory stays flat regardless of dataset size.

### Measurements

Two environments, both with ~100k translations:

| Environment | Rows | TTFB | Total |
|---|---|---|---|
| Linux container (nginx + php-fpm) | 120,001 | 347 ms | 581 ms |
| Windows, `php artisan serve` | 99,999 | 1.31 s | 1.56 s |

### Isolating environment overhead

The Windows figure is dominated by development-server overhead, not by the
export itself. Hitting the same endpoint for a locale with a 123-byte
response took **768ms TTFB** on that machine — meaning roughly 770ms is
fixed cost per request (single-threaded server, no OPcache, Windows
filesystem), before any application logic runs.

Subtracting that baseline, streaming 100k rows costs approximately **540ms**
of actual work in the slowest environment tested, and the Linux container
figure of 347ms TTFB confirms the production-representative number sits
comfortably under the 500ms target.

### Before optimisation

The endpoint originally used `->pluck()` followed by `response()->json()`,
which materialised the full payload in memory before sending anything:

| | Before | After |
|---|---|---|
| TTFB (Windows, 100k rows) | 1.35 s | 1.31 s |
| TTFB (Linux, 120k rows) | — | 347 ms |
| Memory | Scaled linearly with row count | Flat (~2 MB) |
| Download phase | 11 ms (payload pre-built) | 185–250 ms (streamed) |

The Windows TTFB barely moved because that environment's fixed overhead
masks the difference — but the download-phase shift from 11ms to ~200ms is
the signature of the response actually streaming rather than being sent as
one pre-built block, and memory behaviour changed from linear to flat.

## Testing

```bash
php artisan test
php artisan test --group=performance
php artisan test --coverage
```

115 tests, 389 assertions, all passing against SQLite in-memory (`phpunit.xml` forces this regardless of `.env`). The performance group (3 tests) is a regression smoke test against a small dataset, not an SLA benchmark — the numbers above come from manual verification against real MySQL and real HTTP requests, not from this suite.

## Known Limitations

- Test coverage percentage not measured (no Xdebug/PCOV in the environment used); run `php artisan test --coverage` to obtain a figure
- MySQL FULLTEXT cannot be exercised inside RefreshDatabase tests (InnoDB requires commit before MATCH...AGAINST sees rows); verified manually instead
- No per-user ownership scoping — any authenticated user can modify any translation (single-tenant by design)
