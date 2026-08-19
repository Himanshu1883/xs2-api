# Railway deployment (xs2-api)

## Database parity

Railway MySQL uses database name **`railway`**. Local development typically uses **`stagingDB`**. These are different database *names* on the same logical schema — tables, migrations, and data should match between environments.

As of setup verification:

- Both environments had **41 tables** with identical column definitions.
- Key row counts matched (users, orders, integration settings, etc.).
- Railway may run migrations on deploy via `docker-entrypoint.sh` (`php artisan migrate --force`).

## Environment variables

### xs2-api service (Railway dashboard)

Link the MySQL plugin to the xs2-api service, then set Laravel DB variables using Railway references:

| Variable | Railway reference | Typical value |
|----------|-------------------|---------------|
| `DB_CONNECTION` | — | `mysql` |
| `DB_HOST` | `${{MySQL.MYSQLHOST}}` | `mysql.railway.internal` |
| `DB_PORT` | `${{MySQL.MYSQLPORT}}` | `3306` |
| `DB_DATABASE` | `${{MySQL.MYSQLDATABASE}}` | `railway` |
| `DB_USERNAME` | `${{MySQL.MYSQLUSER}}` | `root` |
| `DB_PASSWORD` | `${{MySQL.MYSQLPASSWORD}}` | *(from MySQL service)* |

Or set a single URL (internal, Railway services only):

```
DB_URL=${{MySQL.MYSQL_URL}}
```

Also required for production:

| Variable | Notes |
|----------|-------|
| `APP_KEY` | `php artisan key:generate --show` locally |
| `APP_ENV` | `production` (set in Dockerfile) |
| `APP_DEBUG` | `false` (set in Dockerfile) |
| `APP_URL` | Public xs2-api URL, e.g. `https://<service>.up.railway.app` |
| `SESSION_DRIVER` | `file` (default in Dockerfile; no Redis required) |
| `CACHE_STORE` | `file` (default in Dockerfile) |
| `QUEUE_CONNECTION` | `sync` for web-only deploy; use `database` + a worker service for queues |

Do **not** commit `.env` or real passwords to git.

### xs2-web service (Vercel dashboard)

| Variable | Example |
|----------|---------|
| `NEXT_PUBLIC_APP_URL` | `https://xs2-web.vercel.app` |
| `BACKEND_API_BASE_URL` | `https://<your-railway-service>.up.railway.app` |

The Next.js app proxies browser requests through same-origin `/api/*` routes, so Laravel CORS is not required for normal frontend traffic.

## Monorepo root directory

If the git repository root contains both `xs2-api/` and `xs2-web/`, set the Railway service **Root Directory** to `xs2-api` (or `xs2-vercel/xs2-api` if nested). Vercel should use **Root Directory** `xs2-web`.

### Local development

**Option A — local MySQL** (default in `.env.example`):

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=stagingDB
DB_USERNAME=root
DB_PASSWORD=
```

**Option B — Railway public proxy** (shared hosted DB; use when local MySQL is unavailable or you want a single source of truth):

Copy public host/port from the MySQL service **Connect** tab in Railway:

```env
DB_CONNECTION=mysql
DB_HOST=hopper.proxy.rlwy.net
DB_PORT=22841
DB_DATABASE=railway
DB_USERNAME=root
DB_PASSWORD=<MYSQLPASSWORD from Railway>
```

## Deploy flow

1. Push to the branch Railway builds from (`railway.toml` uses `Dockerfile`).
2. On container start, `docker-entrypoint.sh`:
   - validates `APP_KEY`
   - runs `package:discover`
   - runs `config:cache`
   - runs `migrate --force` (logs a warning and continues if migration fails)
   - runs `route:cache`
   - starts `php artisan serve` on `$PORT`

Health check: `GET /up` (`railway.toml`).

## Syncing local ↔ Railway

### Schema only (migrations)

```bash
# Local
cd xs2-api && php artisan migrate

# Railway — automatic on deploy, or one-off:
railway run php artisan migrate --force
```

### Full data copy (manual)

**Local → Railway** (when local has newer data):

```bash
mysqldump -h127.0.0.1 -P3306 -uroot stagingDB \
  --single-transaction --routines --triggers \
  > /tmp/stagingDB.sql

mysql -h hopper.proxy.rlwy.net -P 22841 -u root -p railway < /tmp/stagingDB.sql
```

**Railway → local**:

```bash
mysqldump -h hopper.proxy.rlwy.net -P 22841 -u root -p railway \
  --single-transaction --routines --triggers \
  > /tmp/railway.sql

mysql -h127.0.0.1 -P3306 -uroot -e "DROP DATABASE IF EXISTS stagingDB; CREATE DATABASE stagingDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -h127.0.0.1 -P3306 -uroot stagingDB < /tmp/railway.sql
```

Or use `scripts/restore-staging-db.sh` for local restore from a dump file.

## Verify connectivity

```bash
# Laravel (from xs2-api)
php artisan db:show

# Raw MySQL
mysql -h hopper.proxy.rlwy.net -P 22841 -u root -p railway -e "SELECT COUNT(*) FROM migrations;"
```
