# SpotEngine

A modern Spotnet web client built on Laravel 12 with PostgreSQL and Newznab compatible API.

> **Note:** Work is ongoing and this project is not finished or ready for production yet.

## Why SpotEngine?

[Spotweb](https://github.com/spotweb/spotweb) is a great piece of software, but years of incremental development have made the codebase increasingly difficult to work with. It carries legacy PHP patterns, a complex data model, and performance trade-offs that were fine at the time but show their age today.

SpotEngine aims to provide the same core experience, browsing and downloading Spotnet spots, with a clean, modern codebase that is a joy to work on:

- **Modern UI**: Tailwind CSS v4, responsive layout, and a straightforward browsing experience for spots and categories.
- **Laravel 12**: modern conventions, first-class tooling, expressive APIs. Optionally with Laravel Octane and FrankenPHP for even better performance.
- **PostgreSQL**: JSONB with GIN indexes for subcategory filtering, `tsvector`/`tsquery` full-text search with a single GIN index across title and description, and a descending index on `spot_posted_at` for fast listing queries
- **Newznab-compatible API**: Can be used with tools like Sonarr, Radarr, and similar automation software.
- **Extensible search**: `SearchDriver` contract with a `DatabaseSearchDriver` (uses PostgreSQL FTS) and a `ManticoreSearchDriver` stub. Currently Manticore is a work in progress.
- **Redis caching**: categories cached in Redis, NZB/image files cached to disk with a configurable pruning schedule
- **Parallel NNTP**: Concurrent NNTP connections for fast header retrieval. Initial full Spot retrieval can be done in under 5 minutes on an Apple M1 Pro.
- **Spot retrieval**: This is currently being done new to old. So new spots will be indexed first and then it'll work backwards. Can be changed in config.

## Requirements

- PHP 8.4+
- PostgreSQL 16+
- Redis 7+
- [Bun](https://bun.sh)

## Setup

```bash
composer run setup
```

This installs dependencies, generates an application key, runs migrations, and builds frontend assets.

## Development

### How to get started

1. **Configure `.env`** — Copy `.env.example` to `.env` and fill in your database, Redis, and NNTP settings (see [Configuration](#configuration)).
2. **Run migrations and seed**:
    ```bash
    php artisan migrate:fresh --seed
    ```
3. **Default login** — Use **admin** / **changeme123** to sign in.

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

That runs the same stack (queue, Pail, Vite) but serves the app via `php artisan octane:start --watch` (FrankenPHP). For production you would run Octane behind Nginx and use a process manager (e.g. Supervisor); see `config/octane.php` and the [Octane docs](https://laravel.com/docs/octane).

**When using Octane:**

- Keep sessions in `database` or `redis` (not `file`).
- Optionally set `CACHE_STORE=octane` in `.env` for in-memory cache; otherwise your existing cache driver is fine.
- Optional env: `OCTANE_SERVER` (default `frankenphp`), `OCTANE_HTTPS` for generated URLs.

## Configuration

Copy `.env.example` to `.env` and fill in:

| Variable                             | Description                                                      |
| ------------------------------------ | ---------------------------------------------------------------- |
| `DB_*`                               | PostgreSQL connection                                            |
| `REDIS_*`                            | Redis connection (`REDIS_CACHE_DB` defaults to `1`)              |
| `NNTP_HOST`, `NNTP_PORT`, `NNTP_SSL` | Usenet server                                                    |
| `NNTP_USERNAME`, `NNTP_PASSWORD`     | Usenet credentials                                               |
| `NNTP_CONNECTIONS`                   | Parallel connection count (default `20`)                         |
| `SEARCH_DRIVER`                      | `database` (default) or `manticore` (work in progress)           |
| `CACHE_NZB_RETENTION_DAYS`           | Days to keep cached NZB files (default `30`)                     |
| `CACHE_IMAGE_RETENTION_DAYS`         | Days to keep cached images (default `30`)                        |
| `REGISTRATION_OPEN`                  | Allow new user registrations (`true`/`false`)                    |
| **Optional (Octane)**                |                                                                  |
| `OCTANE_SERVER`                      | Octane server: `frankenphp` (default), `roadrunner`, `swoole`    |
| `OCTANE_HTTPS`                       | Set to `true` when serving over HTTPS so URLs use `https://`     |
| `CACHE_STORE`                        | Set to `octane` when using Octane for in-memory cache (optional) |

## Scheduled Jobs

| Command            | Schedule         | Description                       |
| ------------------ | ---------------- | --------------------------------- |
| `spot:retrieve`    | Every 15 minutes | Fetch new spots from Usenet       |
| `spot:prune-cache` | Daily at 03:00   | Remove old cached NZB/image files |

Run `php artisan spot:retrieve --full` to do an initial full retrieval.

## API

SpotEngine exposes a Newznab-compatible API at `/api`. Supported operations:

- `t=caps`: server capabilities
- `t=search`: general search
- `t=tvsearch`: TV search with `season` and `ep` parameters (builds `S01E02` + `Season 1 Episode 2` variants)
- `t=movie`: movie search
- `t=details`: spot details
- `t=get`: download NZB

Authenticate with `?apikey=YOUR_KEY`. API keys are shown on your profile page.

## Testing

```bash
php artisan test
```

Tests run against a dedicated `spotengine_test` PostgreSQL database with `RefreshDatabase`.

## Static Analysis

```bash
vendor/bin/phpstan analyse --memory-limit=1G
```

PHPStan level 5 with Larastan. Zero errors expected.

## Code Style

```bash
vendor/bin/pint
```

## TODO

- [ ] Comments and reports are not handled; we currently do nothing with them
- [ ] Add theme feature so users can write their own themes
- [ ] Proper tests
