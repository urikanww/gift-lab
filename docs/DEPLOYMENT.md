# Gift Lab — DigitalOcean Ubuntu Deployment Runbook

Production deployment of the B2B gifting platform on a single DigitalOcean
Ubuntu 24.04 droplet: LEMP (Nginx + MySQL + PHP-FPM) + Redis, Laravel Reverb
(websockets), Supervisor (queue workers + Reverb kept alive), Certbot SSL, and
the Laravel task scheduler via cron.

> **Bootstrap note.** Phases 1–5 produced framework-ready source (`app/`,
> `database/`, `routes/`, `tests/`, `phpunit.xml`) plus the SPA in `frontend/`.
> Step 3 assembles these into a fresh Laravel skeleton — that is where the app
> becomes runnable.

## Topology / DNS

Point three A-records at the droplet IP:

| Host | Serves |
|------|--------|
| `api.giftlab.example` | Laravel API (`public/`) via PHP-FPM |
| `app.giftlab.example` | React SPA static build (`dist/`) |
| `reverb.giftlab.example` | Reverb websockets (Nginx → 127.0.0.1:8080) |

---

## 1. Base droplet + firewall

```bash
adduser deploy && usermod -aG sudo deploy
ufw allow OpenSSH
ufw allow 80,443/tcp
ufw enable
timedatectl set-timezone UTC          # DB + app run in UTC
```

## 2. Install the LEMP stack + tooling

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server redis-server supervisor unzip git \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-redis php8.3-mbstring \
  php8.3-xml php8.3-bcmath php8.3-curl php8.3-gd php8.3-zip php8.3-intl

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node 20 (to build the SPA)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs

sudo mysql_secure_installation
sudo mkdir -p /var/log/giftlab && sudo chown www-data:www-data /var/log/giftlab
```

## 3. Assemble the Laravel application

```bash
sudo mkdir -p /var/www && cd /var/www
git clone https://github.com/urikanww/gift-lab.git giftlab-src

# Fresh skeleton, then overlay our source.
composer create-project laravel/laravel giftlab-app
cd giftlab-app

# IMPORTANT: remove the skeleton's default migrations and example tests first —
# our framework migration defines users/cache/jobs/sessions itself, so leaving
# the defaults in place causes duplicate-table migration failures.
rm -f database/migrations/*.php
rm -f tests/Feature/ExampleTest.php tests/Unit/ExampleTest.php tests/Pest.php tests/TestCase.php

# Overlay our source (app/, database/, routes/, tests/, phpunit.xml).
cp -r ../giftlab-src/app/.               app/
cp -r ../giftlab-src/database/migrations/. database/migrations/
cp -r ../giftlab-src/database/factories/.  database/factories/
cp -r ../giftlab-src/database/seeders/.    database/seeders/
cp    ../giftlab-src/routes/api.php        routes/api.php
cp    ../giftlab-src/routes/channels.php   routes/channels.php
cp    ../giftlab-src/routes/console.php    routes/console.php   # daily scraped re-sync schedule
cp -r ../giftlab-src/tests/.              tests/
cp    ../giftlab-src/phpunit.xml           phpunit.xml

# First-party packages. Do NOT run `install:api` — it publishes a second
# personal_access_tokens migration that collides with ours. Just require Sanctum;
# our migration already creates the tokens table.
composer require laravel/sanctum laravel/reverb
composer require pestphp/pest pestphp/pest-plugin-laravel --dev --with-all-dependencies

# Broadcasting config for prod realtime (keeps our routes/channels.php).
php artisan reverb:install
```

Then wire `bootstrap/app.php` — a fresh Laravel skeleton does not register the
`api`/`channels` route files or stateful Sanctum, so add them:

```php
->withRouting(
    web:      __DIR__.'/../routes/web.php',
    api:      __DIR__.'/../routes/api.php',
    commands: __DIR__.'/../routes/console.php',
    channels: __DIR__.'/../routes/channels.php',
    health:   '/up',
    apiPrefix: 'api',
)
->withMiddleware(function (Middleware $middleware): void {
    $middleware->statefulApi();   // Sanctum cookie auth + CSRF for the SPA
})
```

Also set:

- **CORS** — `config/cors.php`: `paths` include `api/*`, `sanctum/csrf-cookie`,
  `broadcasting/auth`; `allowed_origins` = `[env('CORS_ALLOWED_ORIGINS')]`;
  `supports_credentials => true`.
- **Broadcasting auth** — the `/broadcasting/auth` route (added by
  `reverb:install`) must run behind `auth:sanctum`.

`QuotePolicy` is auto-discovered by Laravel's naming convention — no manual
registration needed.

> **Verified:** this exact assembly (Laravel 12 + Sanctum + Pest, SQLite) runs
> the full backend suite green — **35 passed, 99 assertions**.

## 4. Database

```bash
sudo mysql <<'SQL'
CREATE DATABASE giftlab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'giftlab'@'127.0.0.1' IDENTIFIED BY 'STRONG_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON giftlab.* TO 'giftlab'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
```

## 5. Configure + migrate

```bash
cp /var/www/giftlab-src/deploy/.env.production.example .env
# Edit .env: DB_PASSWORD, REVERB_APP_* (from `php artisan reverb:install`),
# SANCTUM_STATEFUL_DOMAINS, SESSION_DOMAIN, AWS_* (DO Spaces), mail.
php artisan key:generate
php artisan migrate --force --seed        # schema + pricing config + CORE catalogue + staff users
php artisan config:cache route:cache view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
```

> Change the seeded staff passwords (`superadmin@giftlab.local` /
> `ops@giftlab.local`, default `ChangeMe!123`) immediately.

## 6. Build + deploy the SPA

```bash
cd /var/www/giftlab-src/frontend
cp .env.example .env.production
# Set VITE_API_URL=https://api.giftlab.example
#     VITE_REVERB_HOST=reverb.giftlab.example  VITE_REVERB_PORT=443  VITE_REVERB_SCHEME=https
#     VITE_REVERB_APP_KEY=<REVERB_APP_KEY from backend .env>
npm ci
npm run build
sudo mkdir -p /var/www/giftlab-spa/current
sudo cp -r dist /var/www/giftlab-spa/current/
```

## 7. Nginx

```bash
sudo ln -s /var/www/giftlab-app /var/www/giftlab/current   # or use a release symlink (step 11)
sudo cp /var/www/giftlab-src/deploy/nginx-giftlab.conf /etc/nginx/sites-available/giftlab
sudo ln -s /etc/nginx/sites-available/giftlab /etc/nginx/sites-enabled/giftlab
sudo nginx -t && sudo systemctl reload nginx
```

## 8. TLS (Certbot / Let's Encrypt)

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx \
  -d api.giftlab.example -d app.giftlab.example -d reverb.giftlab.example
# Certbot adds the 443 blocks + http->https redirect and installs a renewal timer.
sudo systemctl status certbot.timer
```

## 9. Supervisor — queue workers + Reverb (perpetual)

```bash
sudo cp /var/www/giftlab-src/deploy/supervisor/giftlab-worker.conf /etc/supervisor/conf.d/
sudo cp /var/www/giftlab-src/deploy/supervisor/giftlab-reverb.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start giftlab-worker:* giftlab-reverb
sudo supervisorctl status
```

Reverb binds `127.0.0.1:8080`; Nginx (`reverb.giftlab.example`) terminates TLS
and proxies the `wss` upgrade to it. This is the only real-time transport — the
app never polls.

## 10. Cron — Laravel scheduler

The scheduler drives the daily scraped-catalogue re-scrape + drift check
(Phase 2) and any maintenance jobs. Add for the `www-data` user:

```bash
sudo crontab -u www-data -e
```
```cron
* * * * * cd /var/www/giftlab/current && php artisan schedule:run >> /dev/null 2>&1
```

## 11. Zero-downtime releases (subsequent deploys)

```bash
# Build a new timestamped release, then flip the symlink.
REL=/var/www/giftlab/releases/$(date +%Y%m%d%H%M%S)
git -C /var/www/giftlab-src pull
# ... composer install --no-dev -o, copy source, migrate --force, config:cache ...
ln -sfn "$REL" /var/www/giftlab/current
php /var/www/giftlab/current/artisan queue:restart      # workers pick up new code
sudo supervisorctl restart giftlab-reverb               # reload Reverb on backend change
sudo systemctl reload php8.3-fpm nginx
```

## 12. Post-deploy verification

```bash
php artisan about                                       # env, cache, drivers
curl -s https://api.giftlab.example/api/catalogue | head
# CSRF + estimate round-trip:
curl -si https://api.giftlab.example/sanctum/csrf-cookie
# Reverb reachable (expect HTTP 101/websocket handshake headers):
curl -si https://reverb.giftlab.example
sudo supervisorctl status                               # worker + reverb RUNNING
```
Then, in the SPA: log in as staff, load the production queue, and confirm a
quote state change on another session pushes live (no refresh).

## 13. Ops

- **Logs**: `/var/log/giftlab/{worker,reverb}.log`, `storage/logs/laravel.log`, `journalctl -u nginx`.
- **On deploy always**: `php artisan queue:restart` + `supervisorctl restart giftlab-reverb`.
- **DB backups**: nightly `mysqldump` to DO Spaces (audit logs are dispute evidence — retain).
- **Security**: `composer audit` + `npm audit` in CI; rotate the seeded staff creds; keep `APP_DEBUG=false`. See `SECURITY.md`.
