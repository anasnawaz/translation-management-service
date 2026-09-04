# Translation Management Service

A Laravel 13 API for managing multi-locale application translations: keys, per-locale content, tags, full-text/attribute search, cursor pagination, and bulk JSON export. Authentication is token-based via Laravel Sanctum.

## 1. Overview

The service exposes a JSON API for storing translation strings under a shared `key` (e.g. `homepage.hero.title`), with one `content` value per active `locale`, optional `tags` for categorization, and a free-text `description` on the key itself. It supports searching by key prefix, content, locale and tags; cursor-based pagination for large result sets; and a per-locale JSON export endpoint intended for consumption by front-end applications. A console command can generate large synthetic datasets (default 100,000 translations) for local performance testing.

## 2. Features

- Sanctum bearer-token authentication (register, login, logout, current user).
- Translation CRUD restricted to authenticated users.
- Multi-locale support with an `is_active` flag controlling which locales accept new translations.
- Tagging, with automatic normalization and de-duplication.
- Search by key prefix, content, locale, tags, or a combined `search` parameter.
- MySQL `FULLTEXT` content search in production, with an automatic `LIKE` fallback where `FULLTEXT` isn't available (SQLite, used only by the automated test suite).
- Cursor pagination (`cursorPaginate`) for efficient large-list traversal.
- Per-locale JSON export (`{ "key": "content" }`), optionally filtered by tags.
- A `translations:generate` console command for seeding large datasets for performance testing.

## 3. Tech stack

| Component | Version (as installed) |
|---|---|
| PHP | ^8.3 (developed/tested here on 8.4.21) |
| Laravel Framework | 13.30.1 |
| Laravel Sanctum | 4.3.3 |
| Laravel Pint | 1.30.5 |
| PHPUnit | 12.5.34 |
| Database (app) | MySQL 8.0+ |
| Database (tests) | SQLite (`:memory:`) |

## 4. Requirements

- PHP 8.3 or later, with the `pdo_mysql` and `pdo_sqlite` extensions enabled.
- Composer 2.x.
- MySQL 8.0+ for local development and production use.
- SQLite support built into PHP (bundled with PHP itself) for running the automated test suite — no separate SQLite server is needed.

## 5. Installation

```bash
git clone https://github.com/anasnawaz/translation-management-service.git translation-management-service
cd translation-management-service

composer install

cp .env.example .env
php artisan key:generate
```

## 6. MySQL configuration

Edit `.env` and set your local MySQL credentials:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=translation_management
DB_USERNAME=root
DB_PASSWORD=
```

Create the database (MySQL must already be running):

```sql
CREATE DATABASE translation_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

`.env.example` intentionally ships with `DB_PASSWORD` empty. Never commit a real `.env` file — it is already excluded via `.gitignore`.

## 7. Migration and seeding

```bash
php artisan migrate --seed
```

The default seeders create a handful of active locales (see `database/seeders/`) so that translations can be created immediately. To generate a large dataset for performance testing, see section 15.

To reset the schema entirely:

```bash
php artisan migrate:fresh --seed
```

## 8. Authenticating with Sanctum

All endpoints except `POST /api/register` and `POST /api/login` require a Sanctum bearer token. Include it on every subsequent request:

```
Authorization: Bearer <token>
```

Tokens are created via `createToken()` and, per `config/sanctum.php`, do not expire automatically (`'expiration' => null`) — see section 22 for the security implications of this default.

## 9. API endpoints

| Method | URI | Auth required | Description |
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

`{locale}` in the export route is the locale's `code` (e.g. `en`, `fr`), not its numeric ID.

Unauthenticated (`/register`, `/login`) requests are throttled to 5/minute, keyed by the submitted email plus IP (`RateLimiter::for('auth', ...)` in `AppServiceProvider`). All other routes are throttled to 60/minute, keyed by authenticated user ID (falling back to IP) via the `api` limiter.

## 10. Register / login examples

Register:

```bash
curl -X POST http://localhost:8000/api/register \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{
        "name": "Ada Lovelace",
        "email": "ada@example.com",
        "password": "Password123",
        "password_confirmation": "Password123"
      }'
```

```json
{
  "message": "User registered successfully.",
  "data": {
    "user": { "id": 1, "name": "Ada Lovelace", "email": "ada@example.com", "...": "..." },
    "token": "1|abcdef...",
    "token_type": "Bearer"
  }
}
```

Login:

```bash
curl -X POST http://localhost:8000/api/login \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email": "ada@example.com", "password": "Password123"}'
```

Both endpoints accept an optional `device_name` field used as the Sanctum token name (defaults to `api-token`).

Login failures always respond with a generic `"The provided credentials are incorrect."` message on the `email` field, regardless of whether the email exists, to avoid leaking which emails are registered.

## 11. Translation CRUD examples

Create:

```bash
curl -X POST http://localhost:8000/api/translations \
  -H "Authorization: Bearer <token>" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{
        "key": "homepage.hero.title",
        "locale": "en",
        "content": "Welcome!",
        "description": "Hero section headline",
        "tags": ["web", "marketing"]
      }'
```

`key` and `tags` are normalized (lowercased, trimmed, de-duplicated) before validation. `key` must match `^[a-z0-9._-]+$`. `locale` must reference an existing, active locale. `description` is optional and limited to 500 characters. A duplicate `(key, locale)` pair is rejected with a `422` on the `key` field.

Show / Update / Delete:

```bash
curl http://localhost:8000/api/translations/1 -H "Authorization: Bearer <token>" -H "Accept: application/json"

curl -X PUT http://localhost:8000/api/translations/1 \
  -H "Authorization: Bearer <token>" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"content": "Welcome back!", "tags": ["web"]}'

curl -X DELETE http://localhost:8000/api/translations/1 -H "Authorization: Bearer <token>"
```

`update` accepts any subset of `content`, `description` and `tags` (all optional via `sometimes`); omitted fields are left unchanged. `destroy` returns `204 No Content`.

## 12. Search and filter parameters

`GET /api/translations` accepts the following query parameters, all optional:

| Parameter | Type | Notes |
|---|---|---|
| `key` | string, max 255 | Prefix match against the translation key |
| `content` | string, max 255 | Content match — `FULLTEXT` on MySQL, `LIKE '%...%'` on SQLite |
| `search` | string, max 255 | Combined: matches `key` prefix **or** `content` |
| `locale` | string | Must be an existing locale code |
| `tags` | array or comma-separated string, max 20 | Each tag max 50 chars, de-duplicated |
| `sort_by` | `id` \| `created_at` \| `updated_at` | Defaults to `id` |
| `sort_direction` | `asc` \| `desc` | Defaults to `desc` |
| `per_page` | integer, 1–100 | Defaults to 25 |
| `cursor` | string | Opaque cursor from a previous response |

`tags` may be sent either as `tags[]=web&tags[]=mobile` or as a single comma-separated string (`tags=web,mobile`); both are normalized identically.

## 13. Cursor pagination usage

The list endpoint uses Laravel's `cursorPaginate()` rather than offset pagination, which stays performant on large tables regardless of how deep you page. The response includes `meta.next_cursor` / `meta.prev_cursor` and `links.next` / `links.prev`:

```bash
curl "http://localhost:8000/api/translations?per_page=50" -H "Authorization: Bearer <token>" -H "Accept: application/json"

# follow the next page using the returned cursor
curl "http://localhost:8000/api/translations?per_page=50&cursor=<next_cursor>" -H "Authorization: Bearer <token>" -H "Accept: application/json"
```

`sort_by`/`sort_direction`/other filters must stay identical between requests for a cursor to remain valid, since the cursor encodes a position relative to that specific ordering.

`sort_by=created_at` and `sort_by=updated_at` are not unique columns — multiple translations can share the exact same timestamp. When sorting by either of them, `id` (in the same direction as the primary sort) is automatically applied as a secondary tie-breaker, so pagination stays deterministic even when timestamps tie; `sort_by=id` is already unique and needs no tie-breaker.

## 14. Export examples

```bash
curl "http://localhost:8000/api/locales/en/translations/export" -H "Authorization: Bearer <token>" -H "Accept: application/json"

# filtered by tag
curl "http://localhost:8000/api/locales/en/translations/export?tags[]=web" -H "Authorization: Bearer <token>" -H "Accept: application/json"
```

The response is a flat object of `{ "translation.key": "content", ... }` pairs for every translation in that locale (an unknown or inactive locale code returns `404`). An empty result set returns `{}`, not `[]`.

## 15. The 100k-record generator command

```bash
php artisan translations:generate            # generates 100,000 translations (default)
php artisan translations:generate 500000     # generate a specific count
php artisan translations:generate --fresh    # remove previously generated records first, then generate
```

The command requires at least one active locale to exist (run `migrate --seed` first). It distributes the requested count evenly across active locales, batches inserts in chunks of 1,000 via `insertOrIgnore()`, and bypasses Eloquent events/observers for speed — it is intended purely for local performance testing, not as a fixture for functional tests.

## 16. SQLite test setup

`phpunit.xml` forces every test run onto an in-memory SQLite database, regardless of what `DB_CONNECTION` is set to in `.env`:

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

No setup is required — SQLite support ships with PHP itself. Because these `<env>` values are not marked `force="true"`, an already-exported shell environment variable (e.g. `DB_CONNECTION=mysql php artisan test`) can still override them, which is how this project's MySQL compatibility was independently verified (section 24) without editing `phpunit.xml`.

## 17. Running the normal test suite

```bash
php artisan test
```

## 18. Running the performance test group

```bash
php artisan test --group=performance
```

This runs only `tests/Feature/Performance/PerformanceTest.php`. These are described in detail, and their results reported, in the Performance notes at the end of this README — they are regression smoke tests, not SLA benchmarks.

## 19. Running the coverage command

```bash
php artisan test --coverage
```

This requires a coverage driver (Xdebug or PCOV) enabled in the PHP CLI build running the tests. See section 26 for this project's actual coverage status.

## 20. Database schema and design

Four core tables: `locales`, `translation_keys`, `translations`, `tags`, plus a `tag_translation` pivot. `users` and Sanctum's `personal_access_tokens` support authentication.

- **`locales`**: `code` (unique, e.g. `en`), `name`, `is_active` (indexed). A translation may only be created against an active locale.
- **`translation_keys`**: `key` (unique), `description` (`varchar(500)`, nullable). One row per distinct key, shared across all locales.
- **`translations`**: `translation_key_id`, `locale_id`, `content` (`longtext`). A translation is the `(key, locale)` pair's content.
- **`tags`**: `name` (unique). Shared across all translations.
- **`tag_translation`**: pivot linking `translations` to `tags` (many-to-many).

Relationships: `TranslationKey hasMany Translation`, `Translation belongsTo TranslationKey`, `Translation belongsTo Locale`, `Translation belongsToMany Tag` (via `tag_translation`).

## 21. Indexing and performance decisions

- `translations_key_locale_unique` — a unique composite index on `(translation_key_id, locale_id)`. This is the actual source of truth preventing duplicate `(key, locale)` pairs; see section 25 for how the API layer cooperates with it under concurrent writes.
- `translations_locale_key_index` — a composite index on `(locale_id, translation_key_id)`, matching the export endpoint's and locale-scoped list queries' access pattern.
- `locales.code` — unique index, looked up on nearly every write and read.
- `locales.is_active` — indexed, since active-locale filtering happens on every translation create/list.
- `tags.name` — unique index, used by `firstOrCreate()` during tag sync and by tag filtering.
- `translations.content` — `FULLTEXT` index on MySQL only (see section 23).
- List queries `select()` only the columns each endpoint actually needs and eager-load `translationKey`, `locale`, and `tags` with narrowed column lists, to avoid both N+1 queries and over-fetching.
- Cursor pagination (`cursorPaginate()`) is used instead of `paginate()`/`OFFSET` specifically so listing stays fast at high page depths against the 100k+ dataset the generator command produces.

## 22. Security decisions

- **Authentication**: Sanctum bearer tokens (`personal_access_tokens` table). `config/sanctum.php` sets `'expiration' => null` — tokens do not auto-expire. This is a deliberate default for an API-only service without a refresh-token flow, but it means a leaked token remains valid until the user calls `logout` (which revokes only the *current* token) or another mechanism explicitly revokes it. See section 26 for the tradeoff this leaves open.
- **Password hashing**: bcrypt, `BCRYPT_ROUNDS=12` in production (`.env.example`), reduced to 4 in the test environment (`phpunit.xml`) purely for test speed.
- **Rate limiting**: 5/min on `register`/`login` (keyed by email+IP, mitigating credential-stuffing/enumeration), 60/min on all authenticated routes (keyed by user ID).
- **User enumeration**: login failures always return the same generic message regardless of whether the email exists.
- **Mass assignment**: models expose only the fields intended to be assignable; request classes (`StoreTranslationRequest`, `RegisterRequest`, etc.) are the single point of input validation and normalization (trimming, lowercasing, tag de-duplication) before anything reaches the database.
- **Input validation**: every mutating endpoint is backed by a `FormRequest` with explicit rules — including `key`/`tag` format restriction to `^[a-z0-9._-]+$`, which also rules out control characters or path-like input reaching stored keys.
- **Database-level integrity as the final guarantee**: validation and pre-checks reduce round-trips and give friendly error messages, but the actual duplicate-prevention guarantee is the `translations_key_locale_unique` database constraint (section 25), not the application layer alone.
- **Authorization**: every translation/export route requires `auth:sanctum`; there is currently no per-user ownership scoping on translations (all authenticated users share the same translation set) — this matches the existing single-tenant design and was not changed as part of this task.

## 23. MySQL FULLTEXT vs. SQLite LIKE fallback

MySQL supports `FULLTEXT` indexes; SQLite (used only for the automated test suite) does not support creating them via Laravel's schema builder. Three places cooperate to keep both working without weakening the production search:

1. **Migration** (`database/migrations/..._create_translations_table.php`): the `FULLTEXT` index on `translations.content` is created only when the active connection driver is `mysql`, detected once via a small `usesMysql()` helper rather than inline in `up()`.
2. **Controller** (`TranslationController::applyContentSearch()`): content search calls `whereFullText()` when the query's own connection (via a typed `Illuminate\Database\Eloquent\Builder` parameter, not an untyped `$query`) reports the `mysql` driver, and falls back to a `LIKE '%...%'` clause otherwise.
3. **Tests**: the SQLite-only suite exercises the `LIKE` fallback path; MySQL's `FULLTEXT` path was independently verified against a real MySQL instance (section 24) rather than being asserted from SQLite, since SQLite can never take that branch.

This preserves the MySQL production optimization exactly as before — no index or query behavior was removed or weakened — while keeping the full suite runnable without a MySQL server.

## 24. MySQL verification results

A real MySQL 8.0 instance was stood up and used to independently verify the items above, using the project's own (unmodified) `.env` MySQL credentials:

- `php artisan migrate:fresh --seed` completed successfully against MySQL.
- `DESCRIBE translation_keys` confirmed `description` is `varchar(500)`, matching the `max:500` validation rule (section 20 background).
- `SHOW CREATE TABLE translations` confirmed the `FULLTEXT` index on `content` is created on MySQL, and is absent from the SQLite test schema.
- `php artisan test` against the MySQL connection: **112 of 114 tests passed.** All 13 tests introduced for deterministic cursor pagination and tag-filter normalization passed on MySQL.

The 2 failing tests — both asserting content search results — are `test_content_search_works_using_sqlites_like_fallback` and `test_general_search_finds_matching_content`. Investigation via direct raw SQL confirmed this is **not an application defect**: InnoDB `FULLTEXT` indexes only become visible to `MATCH ... AGAINST` (and therefore Laravel's `whereFullText()`) after the inserting transaction **commits**. Laravel's `RefreshDatabase` test trait wraps each test in a transaction that is always rolled back, never committed, so a row inserted and searched within the same test is structurally invisible to `FULLTEXT` on MySQL — regardless of correctness. This is a known limitation of testing `FULLTEXT` under transactional test isolation, not a bug in this codebase; see section 26.

If a MySQL server is unavailable in your environment, this verification cannot be reproduced locally — the automated suite (section 17) still runs entirely against SQLite and does not require MySQL.

## 25. Handling the key/locale duplicate race condition

`TranslationService::create()` has two layers of duplicate protection:

1. A cheap `exists()` pre-check before any write, producing a fast, friendly `422` for the common case (a user submitting an obvious duplicate).
2. The `translations_key_locale_unique` database constraint, which is the actual guarantee — two concurrent requests can both pass the `exists()` check before either has inserted. The insert is now wrapped in a `try`/`catch` that catches only `Illuminate\Database\UniqueConstraintViolationException` (a specific, driver-detected subtype of `QueryException`, on both MySQL and SQLite) and converts it into the same `422` validation response used by the pre-check. Any other database exception (a connection failure, a different constraint violation) is intentionally left to propagate rather than being silently swallowed. The whole operation remains wrapped in `DB::transaction()`, so a failed insert never leaves a partially-created `translation_keys` row or orphaned tags behind.

**Documented limitation**: a genuine two-process race, where a second request's transaction commits independently of the first, cannot be reproduced against SQLite's single-connection `:memory:` database inside a synchronous PHPUnit process — there is only one connection to race against itself. The test suite instead injects a conflicting row directly between the pre-check and the insert (via a `DB::listen()` hook), which reliably exercises the real `UniqueConstraintViolationException` catch-and-convert path and confirms the whole operation rolls back atomically. It does not, and cannot, prove the *cross-connection* commit-ordering behavior that only two independent real connections (e.g. against MySQL) would exhibit.

## 26. Known limitations

- **Test coverage is not measured.** No coverage driver (Xdebug or PCOV) is available in the environment used to prepare this project. Coverage percentage is therefore **not reported** anywhere in this document — do not assume a specific percentage from test count alone. Run `php artisan test --coverage` in an environment with Xdebug or PCOV installed to obtain a real figure.
- **MySQL `FULLTEXT` cannot be exercised end-to-end inside `RefreshDatabase`-based tests** (section 24) due to InnoDB's commit-visibility requirement for `MATCH ... AGAINST`. This is verified, understood, and does not indicate a defect, but it does mean the `FULLTEXT` code path itself is only covered by manual/ad hoc MySQL verification, not by the automated suite.
- **A genuine cross-connection duplicate-insert race is not reproducible in the SQLite test suite** (section 25); the test coverage for that path is the closest reliable synchronous approximation, documented as such in the test itself.
- **Performance figures in this README are not authoritative SLA benchmarks.** The included `PerformanceTest` group (section 18 and the Performance notes below) uses deliberately generous thresholds against a small (~100-record) SQLite dataset purely to catch catastrophic regressions (e.g. an accidental N+1 query), not to certify production latency.
- **No per-user ownership/authorization on translations** — any authenticated user can read, modify, or delete any translation. This matches the existing single-tenant design and was out of scope for this review.
- **Tag ordering in API responses is not guaranteed** — tags are returned as a plain array without a defined sort order.
- The `translations:generate` command bypasses Eloquent events/observers for insert performance; anything that depends on model events (if added later) would not fire for generated data.

## 27. Optional future improvements

These are suggestions only and were explicitly **not** implemented as part of this review, per the task's scope:

- Response/query caching for hot read paths (e.g. per-locale export).
- Redis for cache/queue/session drivers in production.
- Laravel Octane for higher-throughput request handling.
- Per-user or per-team ownership and authorization scoping on translations.
- Soft deletes and/or an audit trail for translation changes.
- API versioning (e.g. `/api/v1/...`) ahead of any breaking change.

## 28. API documentation

A complete OpenAPI 3.1 specification of this API is available at [`docs/openapi.yaml`](docs/openapi.yaml). Open it in Swagger Editor, Swagger UI, Postman, Insomnia, or another OpenAPI-compatible client to browse the endpoints or try requests.

## 29. Docker setup

A minimal, three-service Docker Compose setup (`app` = PHP 8.4-FPM, `nginx`, `mysql` 8) is included for local development. It does not replace sections 5-7 (a plain local PHP/MySQL install still works); it's an alternative that doesn't require PHP or MySQL installed on the host at all.

**First-time setup:**

```bash
cp .env.example .env
docker compose build
docker compose run --rm app php artisan key:generate
docker compose up -d
docker compose exec app php artisan migrate --seed
```

`key:generate` works reliably here because `docker-compose.yml` bind-mounts only the single `.env` file into the `app` container (`./.env:/var/www/html/.env`) — never the whole project directory or `vendor/`, which would shadow the Composer dependencies already installed into the image at build time. Because it's the same file on disk, `key:generate` writes the generated `APP_KEY` straight back to your host `.env`, and it's picked up by every container on the next `docker compose up`.

**Where things run:**

- Application: `http://localhost:8080` (Nginx; forwards PHP requests to the `app` container internally).
- MySQL host from inside containers (`app`, or any container on the same Compose network): `mysql`.
- MySQL is **not** exposed to the host by default — there is no `ports:` mapping on the `mysql` service, so `127.0.0.1:3306` on your machine will not reach it. It is only reachable from other containers on the Compose network, as `mysql:3306`. If you need a host-side database client, either add a `ports: ["3306:3306"]` mapping to the `mysql` service yourself, or connect through `docker compose exec mysql mysql -u root -proot_password`.

**Local Docker development credentials** (set in `docker-compose.yml`, not secrets): database `translation_management`, user `translation_user` / `translation_password`, root password `root_password`. These override the `.env` file's own `DB_*` values only inside the containers (`.env` itself is unaffected and still describes a plain local MySQL install, per section 6).

**Everyday commands:**

```bash
docker compose exec app php artisan test
docker compose exec app ./vendor/bin/pint --test
docker compose exec app php artisan translations:generate 100000 --fresh
docker compose logs -f
docker compose down
```

`php artisan test` inside the container still runs against SQLite in-memory (phpunit.xml forces this regardless of the container's MySQL connection), exactly as it does outside Docker.

**Stopping and resetting:**

```bash
docker compose down       # stops and removes containers; the mysql-data volume is kept
docker compose down -v    # WARNING: also deletes the mysql-data named volume, permanently destroying the MySQL database
```

Since the application code is baked into the `app` image at build time (not bind-mounted, so Composer dependencies can't be accidentally hidden), a code change requires `docker compose build` (or `docker compose up -d --build`) again to take effect — this setup does not hot-reload PHP file edits.

---

### Performance notes (read before interpreting any numbers above)

- **Local export timing**: measured locally at approximately **0.82–0.98 seconds** for a **2.65MB** export response — not below 500ms. Do not treat 500ms as an achieved figure; it is not.
- **Local startup overhead**: local Windows/PHP development environment startup overhead was measured at approximately **300ms**, independent of and additional to request-handling time — relevant context when interpreting any wall-clock figure measured locally rather than in a production-like environment.
- **The automated `PerformanceTest` group is a regression smoke test**, not an authoritative SLA benchmark (see the class docblock in `tests/Feature/Performance/PerformanceTest.php` for the full reasoning). Its own SQLite-suite timings — reported for context only, not as a production claim — were, at time of writing: full normal suite — 114 tests, 380 assertions, all passing. Observed execution time was approximately 1.7–1.9 seconds in the verification environment; performance group alone (3 tests, 10 assertions) in ~0.3–0.4s. These numbers describe test-suite execution time, not API response latency or an SLA benchmark, and can vary considerably run to run depending on machine load, container/virtualization overhead, and whether OPcache is warm.
- No production-representative (real MySQL, OPcache-warmed, non-test-harness) latency benchmark has been produced for this document. Section 26 lists this as a known limitation.
