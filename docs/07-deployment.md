# 07 — Deployment, Backups and Restore

How to put Bhatta Manager on the Contabo server, keep it updated, back it up and bring a backup back. Written for **Ubuntu 24.04** (it has PHP 8.3 and MariaDB 10.11, the same versions the system is built and tested on).

> Status: the configuration files in `deploy/` and the commands below have been checked on the development laptop. They have **not yet been run on a real server**: the first deployment should be done together, step by step.

**What you need**
- A server with Ubuntu 24.04 (see "Where to host it" below; 2 CPU cores and 2–4 GB of memory are plenty for one kiln).
- An address for the system. With a domain: e.g. `bhatta.yourdomain.com`, with a DNS **A record** pointing to the server's IP address. **Without a domain** (for testing): use the free name `<ip-with-dashes>.sslip.io`, e.g. `203-0-113-10.sslip.io` for the IP 203.0.113.10. It points to the server by itself and HTTPS works with it.
- An SSH key on your laptop (`ssh-keygen -t ed25519` if you have none).

Email (invoice emails and backup alerts) is set up later **inside the app** by the owner (Settings → Email), not on the server.

In the commands, replace `bhatta.example.com` with your address, and every `change-me` with a real value.

## Where to host it

The system needs a MariaDB/MySQL database, files that stay (logo, backups), a scheduler for the nightly backup and a queue worker for emails. A plain Linux server (VPS) gives all of that; most "free app hosting" does not.

| Option | Cost | Verdict |
|---|---|---|
| **Contabo VPS** (or any small VPS) | about €5 a month | **Recommended**, also for the partner test: it is the same as the live server, so the test also rehearses the real deployment |
| Oracle Cloud "Always Free" VM | free | Works with this guide unchanged. Needs a card to sign up, and Oracle may reclaim a free VM that sits idle |
| Render (free plan) | free | **Not suitable**: no MySQL/MariaDB (its free Postgres is deleted after 30 days), files are wiped at every restart (logo, backups), the app sleeps after 15 minutes, and there is no scheduler or worker on the free plan |

---

## 1. Prepare the server

Log in as root the first time, then create a user for deployments and stop password logins.

```bash
adduser deploy
usermod -aG sudo,www-data deploy
mkdir -p /home/deploy/.ssh && cp ~/.ssh/authorized_keys /home/deploy/.ssh/ && chown -R deploy:deploy /home/deploy/.ssh
timedatectl set-timezone Asia/Karachi
```

Check that `ssh deploy@SERVER-IP` works with your key **before** the next step. Then, in `/etc/ssh/sshd_config`, set `PasswordAuthentication no` and `PermitRootLogin no`, and run `sudo systemctl restart ssh`.

Firewall, protection against password guessing, and automatic security updates:

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y ufw fail2ban unattended-upgrades
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable
sudo dpkg-reconfigure -plow unattended-upgrades
```

(`'Nginx Full'` exists once Nginx is installed in step 2; if `ufw` says it does not know it, run that line again after step 2.)

## 2. Install the software

```bash
sudo apt install -y nginx mariadb-server mariadb-client supervisor git unzip acl composer \
    php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-gd php8.3-zip php8.3-curl php8.3-intl \
    certbot python3-certbot-nginx
```

Node.js 20 (only needed to build the screens' CSS and JavaScript during installs and updates):

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

## 3. Database

```bash
sudo mariadb-secure-installation
sudo mariadb
```

In the MariaDB prompt (use a long random password, and keep it for `.env`):

```sql
CREATE DATABASE bhatta CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE bhatta_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bhatta'@'localhost' IDENTIFIED BY 'change-me';
GRANT ALL PRIVILEGES ON bhatta.* TO 'bhatta'@'localhost';
GRANT ALL PRIVILEGES ON bhatta_restore.* TO 'bhatta'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

`bhatta_restore` stays empty: the monthly restore drill puts the newest backup into it to prove it can be restored, checks it, and empties it again.

## 4. Get the code

```bash
sudo mkdir -p /var/www/bhatta && sudo chown deploy:www-data /var/www/bhatta
cd /var/www/bhatta
git clone https://github.com/dawood125/Bhatta-Management-System.git .
sudo setfacl -R  -m u:www-data:rwX -m u:deploy:rwX storage bootstrap/cache
sudo setfacl -dR -m u:www-data:rwX -m u:deploy:rwX storage bootstrap/cache
```

The two `setfacl` lines let both the web server and the deploy user write logs, caches and backups.

## 5. Configure

```bash
cp .env.production.example .env
chmod 640 .env
nano .env
```

Fill in `APP_URL`, `DB_PASSWORD`, `BACKUP_ARCHIVE_PASSWORD` (long and random) and, if you have them, `GOOGLE_DRIVE_CLIENT_ID` and `GOOGLE_DRIVE_CLIENT_SECRET` (see "Google Drive copies" below; the same two values for every kiln). Then:

```bash
php artisan key:generate --force
```

**Save a copy of `.env` in a password manager now.** It holds the application key and the backup password: without them a backup cannot be opened on another server.

## 6. Install

```bash
composer install --no-dev --optimize-autoloader --no-interaction
npm ci --no-audit --no-fund && npm run build
php artisan migrate --force
php artisan storage:link
php artisan permissions:sync
php artisan kiln:install
php artisan optimize
```

`kiln:install` asks for the kiln's name and the owner's name, username and password. Never run `php artisan db:seed` on the server: it is blocked there, because it would load demo data.

## 7. Web server and HTTPS

```bash
sudo cp deploy/nginx-bhatta.conf /etc/nginx/sites-available/bhatta
sudo nano /etc/nginx/sites-available/bhatta        # put your address in server_name
sudo ln -s /etc/nginx/sites-available/bhatta /etc/nginx/sites-enabled/
sudo rm /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d bhatta.example.com --redirect -m you@example.com --agree-tos
```

Certbot renews the certificate by itself.

## 8. Queue worker and scheduler

The queue worker sends invoice emails; the scheduler makes the nightly backup (2:00), checks it (9:00) and runs the restore drill (1st of each month, 3:00).

```bash
sudo cp deploy/supervisor-bhatta-worker.conf /etc/supervisor/conf.d/bhatta-worker.conf
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status
sudo cp deploy/cron-bhatta /etc/cron.d/bhatta && sudo chmod 644 /etc/cron.d/bhatta
```

## 9. Check, then make the first backup

```bash
php artisan backup:run
php artisan kiln:backup-drill
```

Wait five minutes (for the first heartbeats of the worker and scheduler), then:

```bash
php artisan kiln:check
```

Every line should say **ok**. A line saying **check** works but should be looked at; **fix** means something will not work. Then open the address in a browser, log in as the owner and open **Backups**.

Then, as the owner in the app:
- **Settings → Email**: the email account that sends invoice emails and backup alerts (for Gmail: press "Use Gmail" and use an app password), then "Send test email".
- **Backups → Connect Google Drive**: a copy of every backup goes to the owner's Google Drive (needs the Google sign-in details below). Without it, the page asks the owner to download a copy every week, because a backup kept only on the server is lost with the server.

## Google Drive copies (once, for all kilns)

The app asks Google for permission with a short code that the owner enters at google.com/device, and it can only see the backup folder it creates. For that, Google needs to know the app once. Do this one time with your own Google account; the two values it gives go into the `.env` of every server.

1. Open https://console.cloud.google.com, create a project, e.g. "Bhatta Manager".
2. **APIs & Services → Library**: enable **Google Drive API**.
3. **APIs & Services → OAuth consent screen**: user type **External**; app name "Bhatta Manager", your support email; add the scope `.../auth/drive.file`; then **Publish app** (the drive.file scope does not need Google's review).
4. **APIs & Services → Credentials → Create credentials → OAuth client ID**: application type **TVs and Limited Input devices**, name "Bhatta Manager backups".
5. Copy the **Client ID** and **Client secret** into `.env`:
   ```
   GOOGLE_DRIVE_CLIENT_ID=...
   GOOGLE_DRIVE_CLIENT_SECRET=...
   ```
   then `php artisan optimize`. Keep them in your password manager; never put them in the repository.

The newest 30 copies are kept on Drive (`GOOGLE_DRIVE_KEEP` changes that). If the owner removes the app's access in their Google account, the Backups page shows the failed copy and asks to connect again.

---

## Updating to a new version

On the server, as the deploy user:

```bash
cd /var/www/bhatta && ./deploy/deploy.sh
```

It makes a database backup, shows "Being updated" to users, gets the new code, updates the database, rebuilds the caches, restarts the queue worker, opens the site again and runs `kiln:check`. If a step fails the site stays in maintenance mode and the script says what to do.

## Restoring a backup

**To look at a backup without touching the live data**, restore it into the drill database:

```bash
php artisan kiln:restore-backup --into=bhatta_restore
```

**To put the live system back to a backup** (for example after a serious mistake). Anything entered after the backup was made is lost:

```bash
php artisan kiln:restore-backup                               # the newest backup
php artisan kiln:restore-backup 2026-10-24-02-00-00.zip      # a particular one (names are on the Backups page)
```

It asks you to type the database name, then backs up the database as it is now (so this step can be undone), switches the site to maintenance mode, restores the database and the uploaded files, updates the database to the current version, checks the books and opens the site again.

**From a downloaded copy** (for example on a new server): copy the zip into `storage/app/backups/bhatta-manager/` and run the command with its file name. The server's `.env` must have the same `BACKUP_ARCHIVE_PASSWORD` as the server that made it.

## Moving to a new server

Do steps 1 to 4, copy the old `.env` (same `APP_KEY` and backup password, new database password if it changed), do step 6 without `kiln:install`, copy the newest backup over and restore it, then steps 7 to 9.

## When something goes wrong

| What you see | What to do |
|---|---|
| "Something went wrong" | The details are in `storage/logs/laravel-YYYY-MM-DD.log`. Nothing is saved halfway. |
| Invoice emails are not sent | Settings → Email → Send test email shows the email server's own message. Then `sudo supervisorctl status` and `php artisan kiln:check`. Failed emails can be sent again from Sales → Invoice emails. |
| The Backups page is red | `php artisan backup:run` and read its message; `php artisan kiln:check`. |
| "Being updated" stays on | An update stopped halfway: run `./deploy/deploy.sh` again, or `php artisan up`. |
| Anything else | `php artisan kiln:check` shows most setup problems in one go. |

## Security on the server

- `APP_DEBUG=false` on the server, always. `kiln:check` reports it.
- Only SSH keys, no passwords; firewall open only for SSH and the website; security updates install themselves.
- `.env` is readable only by the deploy user and the web server, and is never committed (the repository is public).
- Backups are password-protected (AES-256) and are never reachable through a web address; only the owner can download them, and every download is in the activity log. The copies on Google Drive are the same password-protected files.
- The email password and the Google Drive access are stored encrypted with the application key (`APP_KEY`): keep `.env` safe, and use the same `APP_KEY` when moving to a new server.
- The database user can reach only the two Bhatta databases.
