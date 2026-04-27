# Self-hosted installation guide

This guide covers production deployment. Set `APP_NAME` in `.env` for the application display name in the UI.

## Requirements

- PHP 8.3+
- Composer 2.7+
- Node.js 20.19+ (or 22.12+)
- npm 10+
- A database server (MySQL 8 recommended for this guide)
- Redis (cache + queues)
- `cron` for scheduler

## Option A: Docker Compose (Recommended)

### 1) Prepare host

- Install Docker Engine + Docker Compose plugin.
- Create a deployment directory, for example `/opt/nrth`.

### 2) Deploy release archive

1. Copy release files:
   - `nrth-<version>.tar.gz`
   - `nrth-<version>.tar.gz.sha256`
2. Verify checksum:
   ```bash
   ARCHIVE="nrth-<version>.tar.gz"
   EXPECTED="$(cat "${ARCHIVE}.sha256")"
   ACTUAL="$(shasum -a 256 "$ARCHIVE" | awk '{print $1}')"
   [ "$EXPECTED" = "$ACTUAL" ] && echo "Checksum OK"
   ```
3. Extract:
   ```bash
   tar -xzf nrth-<version>.tar.gz
   ```

### 3) Configure environment

1. Copy `.env.example` to `.env`.
2. Set production values:
   - `APP_ENV=production`
   - `APP_DEBUG=false`
   - `APP_URL=https://your-domain`
   - `DB_*` (MySQL host/database/user/password)
   - `REDIS_*`
   - `MAIL_*`
3. Generate app key:
   ```bash
   php artisan key:generate
   ```

### 4) Bring up services

```bash
docker compose up -d
```

### 5) Run installer

```bash
php artisan app:install
```

This command sets up application prerequisites and initial bootstrap tasks.

## Option B: Manual Installation (PHP 8.3, MySQL 8, Redis)

### 1) System packages

- PHP 8.3 with required extensions (`mbstring`, `openssl`, `pdo`, `pdo_mysql`, `tokenizer`, `xml`, `curl`, `bcmath`, `intl`, `zip`, `fileinfo`)
- MySQL 8
- Redis
- Nginx or Apache
- Supervisor (for queue workers)

### 2) Application setup

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
cp .env.example .env
php artisan key:generate
php artisan app:install
```

### 3) Web + workers

- Point web root to `public/`.
- Run queues with Supervisor:
  - `php artisan queue:work --sleep=1 --tries=3 --timeout=120`
- Add cron entry for scheduler:
  ```cron
  * * * * * cd /path/to/nrth && php artisan schedule:run >> /dev/null 2>&1
  ```

## Backups

This project includes `spatie/laravel-backup`.

### 1) Configure backup destinations

- Set filesystems/disks in `config/filesystems.php`.
- Configure backup settings in `config/backup.php`.
- Ensure destination credentials are present in `.env`.

### 2) Run and schedule

```bash
php artisan backup:run
php artisan backup:list
```

Recommended scheduler entries:
- `backup:run` daily
- `backup:clean` daily

## Updating to New Versions

### 1) Deploy new release archive

Extract new release to a versioned directory, keep previous release until verified.

### 2) Reinstall dependencies + assets

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

### 3) Run update command

```bash
php artisan app:update
```

### 4) Restart workers/services

- Restart queue workers and PHP-FPM container/service.
- Confirm health checks and dashboard load.

## Troubleshooting

### “Vite manifest not found”

Run:
```bash
npm run build
```

### “relation does not exist” / missing table

Run:
```bash
php artisan migrate
```

### Permission issues in `storage/` or `bootstrap/cache/`

Ensure web/queue user can write:
```bash
chmod -R ug+rw storage bootstrap/cache
```

### Queue jobs not processing

- Verify Redis is reachable.
- Check Supervisor status/logs.
- Restart workers.

### Emails not sending

- Verify `MAIL_*` config and SMTP connectivity.
- Use `php artisan tinker` to send a test mail.

