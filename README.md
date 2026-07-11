# SpotEngine

A modern Spotnet web client built on Laravel 13 with PostgreSQL and a Newznab-compatible API.

## Why SpotEngine?

[Spotweb](https://github.com/spotweb/spotweb) is a great piece of software, but years of incremental development have made the codebase increasingly difficult to work with. It carries legacy PHP patterns, a complex data model, and performance trade-offs that were fine at the time but show their age today. The developers seem unwilling to properly improve things, hence SpotEngine was born.

SpotEngine aims to provide the same core experience, browsing and downloading Spotnet spots, with a clean, modern codebase that is a joy to work on:

- **Modern UI**: Tailwind CSS v4, responsive layout, and a straightforward browsing experience for spots and categories.
- **Laravel 13**: modern conventions, first-class tooling, expressive APIs. Optionally with Laravel Octane and FrankenPHP for even better performance.
- **PostgreSQL**: JSONB with GIN indexes for subcategory filtering, `tsvector`/`tsquery` full-text search with a single GIN index across title and description, and a descending index on `spot_posted_at` for fast listing queries
- **Newznab-compatible API**: Can be used with tools like Sonarr, Radarr, and similar automation software.
- **Extensible search**: `SearchDriver` contract with PostgreSQL full-text search by default and optional Manticore for larger installations.
- **Redis caching**: categories cached in Redis, NZB/image files cached to disk with a configurable pruning schedule
- **Parallel NNTP**: Concurrent NNTP connections for fast header retrieval. Initial full scan can be done in under 5 minutes on an Apple M1 Pro.
- **Two-phase spot retrieval**: Initial scan uses XOVER for fast bulk indexing so the app is usable right away. Enrichment fills in the rest via HEAD requests in the background. See [Spot Retrieval](#spot-retrieval) for details.

## Requirements

- PHP 8.5+
- [PostgreSQL](https://www.postgresql.org/) 16+ (PostgreSQL 18 is used by Docker and CI and is the recommended database)
- A Redis-compatible server [Valkey](https://valkey.io/) or Redis)
- [Bun](https://bun.sh) for front-end tooling

## Setup

```bash
composer run setup
```

This installs dependencies, generates an application key, runs migrations, and builds frontend assets.

`APP_KEY` is required for every Laravel installation. It is used to encrypt sessions, cookies, and other secure payloads. If `APP_KEY` is missing, encryption features fail; if it changes after data is written, existing encrypted data becomes unreadable.

## Docker

Use Docker Compose with FrankenPHP/PostgreSQL/Valkey:

- See [DOCKER.md](DOCKER.md)

Docker images in this repository are pinned to FrankenPHP PHP 8.5 variants.

## Development

### How to get started

1. **Configure `.env`**: Copy `.env.example` to `.env` and fill in your database, Redis/Valkey, and NNTP settings (see [Configuration](#configuration)).
2. **Install PHP dependencies**:
    ```bash
    composer install
    ```
3. **Install frontend dependencies**:
    ```bash
    bun install
    ```
4. **Generate an app key** (if `APP_KEY` is empty):
    ```bash
    php artisan key:generate
    ```
5. **Run migrations and seed**:
    ```bash
    php artisan migrate:fresh --seed
    ```
6. **Create the first administrator**:
    ```bash
    php artisan spot:admin:create
    ```
    The command prompts for unique credentials and refuses to run after an administrator exists.

Then start the stack:

```bash
composer run dev
```

This starts the Laravel dev server, queue worker, Pail log viewer, and Vite, all in one terminal.

### Optional: Laravel Octane (FrankenPHP)

The app can run with or without [Laravel Octane](https://laravel.com/docs/octane). The default `composer run dev` uses the standard `php artisan serve`; no Octane is required.

To use Octane with FrankenPHP for higher throughput (app kept in memory between requests):

```bash
composer run dev:octane
```

That runs the same stack (queue, Pail, Vite) but serves the app via `php artisan octane:start --watch` (FrankenPHP). FrankenPHP includes Caddy, so Octane can serve traffic directly; add an external reverse proxy and/or process manager only if your deployment requires it. See `config/octane.php` and the [Octane docs](https://laravel.com/docs/octane).

**When using Octane:**

- Keep sessions in `database` or `redis` (not `file`).
- Redis remains the default cache store. Optionally set `CACHE_STORE=octane` in `.env` for in-memory cache. Octane as a cache store does not work with FrankenPHP, only Swoole or OpenSwoole. By default this project uses FrankenPHP.
- Optional env: `OCTANE_SERVER` (default `frankenphp`), `OCTANE_HTTPS` for generated URLs.

## Configuration

Copy `.env.example` to `.env` and fill in:

| Variable                             | Description                                                                                                                              |
| ------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------- |
| `APP_KEY`                            | Required Laravel encryption key                                                                                                          |
| `DB_*`                               | PostgreSQL connection                                                                                                                    |
| `REDIS_*`                            | Redis connection (`REDIS_CACHE_DB` defaults to `1`)                                                                                      |
| `NNTP_HOST`, `NNTP_PORT`, `NNTP_SSL` | Usenet server; defaults are TLS on port `563`                                                                                            |
| `NNTP_TLS_VERIFY`                    | Verify the NNTP server certificate (default `true`)                                                                                      |
| `NNTP_USERNAME`, `NNTP_PASSWORD`     | Usenet credentials                                                                                                                       |
| `NNTP_CONNECTIONS`                   | Parallel connection count (default `20`)                                                                                                 |
| `SEARCH_DRIVER`                      | `database` (default) or `manticore`                                                                                                      |
| `CACHE_NZB_RETENTION_DAYS`           | Days to keep cached NZB files before pruning (default `30`)                                                                              |
| `CACHE_IMAGE_RETENTION_DAYS`         | Days to keep cached images before pruning (default `30`)                                                                                 |
| `REGISTRATION_OPEN`                  | Allow new user registrations (default `false`)                                                                                           |
| **Optional (Octane)**                |                                                                                                                                          |
| `OCTANE_SERVER`                      | Octane server: `frankenphp` (default), `roadrunner`, `swoole`                                                                            |
| `OCTANE_HTTPS`                       | Set to `true` when serving over HTTPS so URLs use `https://`                                                                             |
| `CACHE_STORE`                        | Set to `octane` when using Octane for in-memory cache (optional, requires Swoole)[https://laravel.com/docs/13.x/octane#the-octane-cache] |

## Spot Retrieval

Spot fetching uses a two-phase approach:

### 1. Initial scan (`--initial-scan`)

Run once when setting up a new instance:

```bash
php artisan spot:retrieve --initial-scan
```

Uses XOVER to bulk-index spots in parallel. With the default configuration, forward ranges are processed newest-first so recent spots become available first. The forward checkpoint is committed only after the complete range succeeds, preventing an interrupted run from skipping older batches. Spots are immediately browsable with their core metadata: title, category, size, and poster. Descriptions, images, NZB segments, and signature verification are not yet populated (see below).

NNTP Pipelining could further speed this up but during development there were some provider issues so this is on the backburner for now.

### 2. Enrichment (`spot:enrich`)

After the initial scan, run the enrich command to fill in the full X-XML data for all indexed spots:

```bash
php artisan spot:enrich
```

This fetches the `HEAD` for each unenriched spot in parallel and populates descriptions, image references, NZB segments, and verifies RSA signatures. It can run in the background while users are already browsing. When a user opens a spot that hasn't been enriched yet, it is enriched lazily in real time.

### 3. Ongoing retrieval

Once the initial scan and enrichment are done, `spot:retrieve` runs on a schedule (every 15 minutes by default). It uses XOVER to discover new articles, then fetches each matching article's `HEAD` data before committing it. New spots therefore receive full X-XML metadata in one scheduled pass and do not need a separate enrichment run.

## Scheduling

**Important:** Run the initial scan (and optionally enrichment) _before_ setting up the task scheduler. If you add the cron entry first, the scheduled `spot:retrieve` and your manual initial scan will compete because only one can run at a time, so the scheduler will keep skipping until the scan finishes. Do the initial setup first, then add the cron.

SpotEngine uses [Laravel's task scheduler](https://laravel.com/docs/scheduling#running-the-scheduler) for incremental spot retrieval and cache maintenance. To activate it, add a single cron entry on your server:

```
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

This runs the scheduler every minute, which in turn executes the configured jobs at their defined intervals:

| Command            | Schedule         | Description                                                              |
| ------------------ | ---------------- | ------------------------------------------------------------------------ |
| `spot:retrieve`    | Every 15 minutes | Discover and populate new spots                                          |
| `spot:search-sync` | Every minute     | Reconcile pending spot changes with the configured external search index |
| `spot:prune-cache` | Daily at 03:00   | Remove cached NZB/image files older than their configured retention      |

### Production processes

Run the web server, scheduler, and any asynchronous queue worker as separately supervised processes:

```bash
# Run once through cron every minute:
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1

# Keep running under Supervisor, systemd, or your container orchestrator:
php artisan queue:work --sleep=3 --tries=3 --timeout=90 --max-time=3600
```

Restart long-running workers after each deployment with `php artisan queue:restart`; restart Octane as well when it serves the application.

## API

SpotEngine exposes a Newznab-compatible API at `/api`. Supported operations:

- `t=caps`: server capabilities
- `t=search`: general search
- `t=tvsearch`: TV search with `season`, `ep`, `rid`, and `tvmazeid`
- `t=movie`: movie search with `q` and `imdbid`
- `t=details`: spot details
- `t=get`: download NZB

Search responses honor arbitrary `offset` values and limits up to 100. External IDs are matched against the source metadata fields that contain IMDb, TVmaze, or TVRage URLs/tokens. Authenticate with `?apikey=YOUR_KEY`; API keys are shown on the profile page. Requests are limited to 60 per minute per user or guest IP by default.

Downloaded spots are recorded in `user_downloads`. Saved filters, excluded-subcategory filtering, and watchlists are intentionally not exposed in the UI until complete product behavior exists; the existing tables remain in place for migration compatibility.

## Testing

```bash
composer test
```

Use `composer test` instead of calling `php artisan test` directly. The Composer script clears config cache first, then runs tests, which prevents stale cached config from pointing at your development database.

Tests run against a dedicated `spotengine_test` PostgreSQL database with `RefreshDatabase`.

If you need to run Artisan directly, clear config first:

```bash
php artisan config:clear
php artisan test
```

## Static Analysis

```bash
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/rector --dry-run
```

PHPStan level 5 with Larastan means zero errors expected. Rector is configured for PHP 8.5+ and Laravel best practices (dead code removal, code quality). Run without `--dry-run` to apply changes.

## Code Style

```bash
vendor/bin/pint
```

## TODO

- [ ] Comments and reports are not handled; we currently do nothing with them
- [ ] Add theme feature so users can write their own themes
