# Docker

SpotEngine ships with a Docker Compose setup based on FrankenPHP.

## Services

- `app`: FrankenPHP + Caddy (classic mode by default)
- `db`: PostgreSQL 18
- `redis`: Redis 7 (default cache backend)

The app image sets PHP `memory_limit=1G` (CLI and HTTP runtime).

## Dev vs Prod

- Development mode: bind-mounts your local source into the container (`.:/app`) for fast edit/reload.
- Production-like mode: copies source code into an immutable image (no bind mount) for reproducible deployments.

## Environment Variables

Compose reads values from the Laravel `.env` file in this project root.

Database credentials are reused for PostgreSQL container initialization:

- `DB_DATABASE` -> `POSTGRES_DB`
- `DB_USERNAME` -> `POSTGRES_USER`
- `DB_PASSWORD` -> `POSTGRES_PASSWORD`

For container networking, Compose overrides:

- `DB_HOST=db`
- `REDIS_HOST=redis`

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

4. Generate app key and migrate:

```bash
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

App URL:

- `http://localhost:8000` (or your `APP_PORT`)

## Development Setup with Octane (Worker Mode)

Use the Octane override file:

```bash
docker compose -f compose.yml -f compose.octane.yml up --build -d
```

This switches `app` to:

```bash
php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=80
```

FrankenPHP includes Caddy, so no separate Nginx/Caddy container is required for this mode.

## Production-Like Setup (Immutable Image)

Use the production override to copy application code into the image (no bind mount):

```bash
docker compose -f compose.yml -f compose.prod.yml up --build -d
```

This keeps infrastructure from `compose.yml` and replaces the app build with `docker/frankenphp/Dockerfile.prod`.

Run app initialization commands:

```bash
docker compose -f compose.yml -f compose.prod.yml exec app php artisan key:generate
docker compose -f compose.yml -f compose.prod.yml exec app php artisan migrate --seed
```

To run Octane in worker mode with the immutable image:

```bash
docker compose -f compose.yml -f compose.prod.yml -f compose.octane.yml up --build -d
```

The same applies in production-like mode: FrankenPHP serves traffic directly via built-in Caddy.

## Redis (Default) and Non-Redis Setup

Redis is included by default because this project commonly uses Redis cache/session.

If you do not use Redis, edit `.env` before starting containers:

```dotenv
CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database
```

Then start only required services:

```bash
docker compose up --build -d app db
```

Production-like equivalent:

```bash
docker compose -f compose.yml -f compose.prod.yml up --build -d app db
```

## Useful Commands

```bash
docker compose logs -f app
docker compose exec app php artisan optimize:clear
docker compose down
docker compose down -v
```
