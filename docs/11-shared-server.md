# 11 — The shared server (Contabo) for several projects

The client's live Bhatta Manager runs on a Contabo Cloud VPS that is also meant for other clients' systems and test projects. This explains how that server is set up and how to add a project. The one-app setup in `07-deployment.md` still applies to each project; only the users and paths below differ.

## The server

| | |
|---|---|
| Provider | Contabo, Cloud VPS 6, Hub Europe |
| IP | 77.216.129.1 (hostname `apps-server`) |
| System | Ubuntu 24.04, 6 CPU, 11 GB memory, 4 GB swap, 193 GB disk, timezone Asia/Karachi |
| Login | `ssh deploy@77.216.129.1`, **SSH keys only**. Root login and passwords are switched off. `deploy` has sudo |
| Firewall | ufw: only SSH, 80 and 443. fail2ban blocks SSH password guessing. Security updates install themselves |
| Shared software | nginx, MariaDB 10.11 (local only), PHP 8.3 (FPM), Composer, Node 20, supervisor, certbot |

To let another person or laptop in, add their **public** key to `/home/deploy/.ssh/authorized_keys`. If every key is lost, use the VNC console in the Contabo panel.

## How projects are kept apart

Each project `<name>` has:

| Part | Where |
|---|---|
| Linux user | `<name>`, no login. PHP, the worker and the scheduler run as this user, so one project cannot read another's files or `.env` |
| Folder | `/var/www/<name>` (owner `deploy`, group `<name>`, closed to everybody else) |
| Databases | `<name>` and `<name>_restore`, database user `<name>` with access to only those two. The password is in `/root/apps/<name>.secrets` |
| PHP-FPM pool | `/etc/php/8.3/fpm/pool.d/<name>.conf`, socket `/run/php/<name>.sock` |
| nginx site | `/etc/nginx/sites-available/<name>`, logs `/var/log/nginx/<name>.*.log` |
| Queue worker | `/etc/supervisor/conf.d/<name>-worker.conf` |
| Scheduler | `/etc/cron.d/<name>` |

An address that no project uses (such as the bare IP) gets no site (`00-default`).

The helper scripts are in `deploy/server/` and installed on the server as `new-app`, `app-perms` and `app-start` in `/usr/local/sbin`.

## Add a Laravel project

```bash
ssh deploy@77.216.129.1
sudo new-app shop shop.clientdomain.com          # user, folder, databases, PHP pool, nginx, worker, cron
cd /var/www/shop && git clone <repository> .
cp .env.example .env && nano .env                # DB values: sudo cat /root/apps/shop.secrets
sudo app-perms shop                              # storage/ + bootstrap/cache writable, .env private
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan key:generate --force                 # as deploy: only deploy can write .env
php artisan storage:link                         # as deploy: only deploy can write public/
sudo -u shop php artisan migrate --force
sudo -u shop php artisan optimize
sudo app-start shop                              # worker + scheduler
sudo certbot --nginx -d shop.clientdomain.com --redirect --agree-tos --register-unsafely-without-email
```

Point the domain's **A record** at 77.216.129.1 before running certbot. A project that does not use Laravel needs its own nginx site, but the same user, folder and database steps apply.

The rule for artisan: **writes to `.env` or `public/` run as `deploy`; everything else runs as the project's user** (`sudo -u <name> php artisan ...`). Otherwise files end up owned by the wrong user and pages fail.

## Bhatta Manager on this server

- Folder `/var/www/bhatta`, user `bhatta`, address https://77-216-129-1.sslip.io for now. When the client's domain is ready: point it here, change `server_name` in `/etc/nginx/sites-available/bhatta` and `APP_URL` in `.env`, run `sudo -u bhatta php artisan optimize`, then certbot for the new name.
- Update: `cd /var/www/bhatta && ./deploy/deploy.sh`. It runs artisan as the group of `.env`, which is `bhatta` here.
- Backups: `storage/app/backups`, every night at 2:00, plus the monthly restore drill into `bhatta_restore`.
- Check: `sudo -u bhatta php artisan kiln:check`.

## Room

One kiln uses well under 1 GB of memory. With 11 GB the server can hold many small client systems. Watch `free -h` and `df -h` as projects are added; each pool's `pm.max_children` (12) limits how much one project can take.
