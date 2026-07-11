# Docker

SpotEngine ships with a Docker Compose setup based on FrankenPHP.

## Services

- `app`: FrankenPHP + Caddy (classic mode by default, PHP 8.5 image)
- `pgsql`: PostgreSQL 18
- `valkey`: Valkey/Redis-compatible service (default cache, sessions, and locks)
- `vite`: optional Bun/Vite dev server (HMR)

The app image sets PHP `memory_limit=1G` (CLI and HTTP runtime).

## PHP Version in Docker

Docker images are pinned to `dunglas/frankenphp:1-php8.5`, so both dev and production-like Docker app containers run PHP 8.5.

You can verify at runtime with:

```bash
docker compose exec app php -v
```

## Dev vs Prod

- Development mode: bind-mounts your local source into the container (`.:/app`) for fast edit/reload.
- Production-like mode: copies source code into an immutable image (no bind mount) for reproducible deployments.

## APP_KEY (Required)

Every Laravel installation needs `APP_KEY`.

It is used for encryption of session/cookie payloads and other encrypted values. If `APP_KEY` is missing, Laravel will fail for encryption features. If it changes after data is written, previously encrypted data becomes unreadable.

Set `APP_KEY` in `.env` before normal use.

## Environment Variables

Compose reads values from the Laravel `.env` file in this project root.

Database credentials are reused for PostgreSQL container initialization:

- `DB_DATABASE` -> `POSTGRES_DB`
- `DB_USERNAME` -> `POSTGRES_USER`
- `DB_PASSWORD` -> `POSTGRES_PASSWORD`

For container networking, Compose overrides:

- `DB_HOST=pgsql`
- `REDIS_HOST=valkey`

So your local `.env` can still use `127.0.0.1` outside Docker.

## Development Setup (Bind Mount)

1. Copy and configure env:

```bash
cp .env.example .env
```

2. Start the stack:

```bash
docker compose up --build -d
```

3. Install PHP dependencies (first run):

```bash
docker compose exec app composer install
```

4. Generate app key (if `APP_KEY` is empty):

```bash
docker compose exec app php artisan key:generate --force
```

5. Initialize database:

First bootstrap or full reset (destructive):

```bash
docker compose exec app php artisan migrate:fresh --seed --force
```

Normal updates (non-destructive):

```bash
docker compose exec app php artisan migrate --seed --force
```

App URL:

- `http://localhost:8000` (or your `APP_PORT`)

### Frontend Assets in Docker Dev

Default `docker compose up` does not start Vite automatically.

Build assets once:

```bash
docker compose run --rm vite sh -lc "bun install && bun run build"
```

Run Vite dev server (HMR):

```bash
docker compose --profile vite up -d vite
```

Vite URL:

- `http://localhost:5173` (or your `VITE_PORT`)

## Development Setup with Octane (Worker Mode)

Use the Octane override file:

```bash
docker compose -f compose.yml -f compose.octane.yml up --build -d
```

This switches `app` to:

```bash
php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=80 --admin-port=2019
```

FrankenPHP includes Caddy, so no separate Nginx/Caddy container is required for this mode.

## Production-Like Setup (Immutable Image)

Use the production override to copy application code into the image (no bind mount):

```bash
docker compose -f compose.yml -f compose.prod.yml up --build -d
```

This keeps infrastructure from `compose.yml` and replaces the app build with `docker/frankenphp/Dockerfile.prod`.

In this mode, frontend assets are built in the image during Docker build (`bun run build` in a Bun build stage).

### Cache Bind Mounts (NZB and Images)

In production mode, the NZB and image caches are bind-mounted separately from the host into the container:

- `./storage/app/cache/nzb` → `/app/storage/app/cache/nzb`
- `./storage/app/cache/images` → `/app/storage/app/cache/images`

This keeps cached files outside the image, persistent across container rebuilds, and accessible on the host. Create the directories before first run if needed: `mkdir -p storage/app/cache/nzb storage/app/cache/images`.

Ensure your `.env` contains a non-empty `APP_KEY` before first run.

Generate one if needed:

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Then initialize schema/data:

```bash
docker compose -f compose.yml -f compose.prod.yml exec app php artisan migrate --seed --force
```

To run Octane in worker mode with the immutable image:

```bash
docker compose -f compose.yml -f compose.prod.yml -f compose.octane.yml up --build -d
```

The same applies in production-like mode: FrankenPHP serves traffic directly via built-in Caddy.

### Scheduler and queue worker

The production Compose overrides run the web process only. Configure your host scheduler or orchestrator to execute the scheduler every minute:

```bash
docker compose -f compose.yml -f compose.prod.yml exec -T app php artisan schedule:run
```

If `QUEUE_CONNECTION` uses the default asynchronous `redis` connection, run `php artisan queue:work redis --sleep=3 --tries=3 --timeout=90 --max-time=3600` as a separate supervised container or host process. Restart it after deployments with `php artisan queue:restart`.

## Redis/Valkey Defaults

The default `.env.example` stores cache data, sessions, and queues in the Redis-compatible Valkey service:

```dotenv
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_CONNECTION=default
QUEUE_CONNECTION=redis
```

To run cache and sessions in PostgreSQL instead, override both values in `.env`:

```dotenv
CACHE_STORE=database
SESSION_DRIVER=database
```

With those overrides, you may start only the app and database services:

```bash
docker compose up --build -d app pgsql
```

Production-like equivalent:

```bash
docker compose -f compose.yml -f compose.prod.yml up --build -d app pgsql
```

## Why Prod Uses `composer install --no-scripts`

The production image build intentionally skips Composer scripts. In this project, Composer post-install hooks run Artisan commands (for example `spot:categories:update`) that depend on runtime services such as the database. Those services are not available during image build, so running scripts at build-time is brittle and can fail builds.

Runtime initialization (`APP_KEY`, migrations, seeding) should happen after containers are up.

## Useful Commands

```bash
docker compose logs -f app
docker compose exec app php artisan optimize:clear
docker compose down
docker compose down -v
```

With production compose, the cache lives in `./storage/app/cache/nzb` and `./storage/app/cache/images` on the host (bind mounts), so it persists regardless of `docker compose down -v`.
