#!/usr/bin/env bash
# Add a Laravel project to the shared server (docs/11-shared-server.md).
#
#   sudo new-app <name> <domain> [php-version]
#   e.g. sudo new-app bhatta bhatta.example.com 8.3
#
# Each project gets its own Linux user (so one project cannot read another's files), folder
# /var/www/<name>, databases <name> and <name>_restore with their own database user, PHP-FPM
# pool, nginx site, queue worker and scheduler. The code is then cloned into the folder by the
# deploy user, and the database password is in /root/apps/<name>.secrets.
set -euo pipefail

NAME="${1:?usage: new-app <name> <domain> [php-version]}"
DOMAIN="${2:?usage: new-app <name> <domain> [php-version]}"
PHP="${3:-8.3}"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
DIR="/var/www/$NAME"

[[ "$NAME" =~ ^[a-z][a-z0-9_]{1,20}$ ]] || { echo "Name: lowercase letters, digits and _ only."; exit 1; }
[ "$(id -u)" -eq 0 ] || { echo "Run with sudo."; exit 1; }
[ -x "/usr/sbin/php-fpm$PHP" ] || { echo "PHP $PHP is not installed (php$PHP-fpm)."; exit 1; }

echo "== User and folder"
id "$NAME" >/dev/null 2>&1 || useradd --system --home-dir "$DIR" --no-create-home --shell /usr/sbin/nologin "$NAME"
mkdir -p "$DIR"
chown "$DEPLOY_USER:$NAME" "$DIR"
chmod 2750 "$DIR"
# nginx (www-data) reads static files; the project's user reads the code; the deploy user writes it.
setfacl -m u:www-data:rX -m u:"$NAME":rX -m u:"$DEPLOY_USER":rwX "$DIR"
setfacl -d -m u:www-data:rX -m u:"$NAME":rX -m u:"$DEPLOY_USER":rwX "$DIR"

echo "== Databases"
mkdir -p /root/apps && chmod 700 /root/apps
SECRETS="/root/apps/$NAME.secrets"
if [ ! -f "$SECRETS" ]; then
    DB_PASS="$(openssl rand -base64 33 | tr -d '/+=' | cut -c1-32)"
    mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`$NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS \`${NAME}_restore\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$NAME'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$NAME\`.* TO '$NAME'@'localhost';
GRANT ALL PRIVILEGES ON \`${NAME}_restore\`.* TO '$NAME'@'localhost';
FLUSH PRIVILEGES;
SQL
    printf 'DB_DATABASE=%s\nDB_USERNAME=%s\nDB_PASSWORD=%s\n' "$NAME" "$NAME" "$DB_PASS" > "$SECRETS"
    chmod 600 "$SECRETS"
fi

echo "== PHP-FPM pool"
cat > "/etc/php/$PHP/fpm/pool.d/$NAME.conf" <<CONF
[$NAME]
user = $NAME
group = $NAME
listen = /run/php/$NAME.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 12
pm.process_idle_timeout = 30s
pm.max_requests = 500
php_admin_value[upload_max_filesize] = 8M
php_admin_value[post_max_size] = 8M
php_admin_value[memory_limit] = 512M
php_admin_value[max_execution_time] = 300
php_admin_flag[expose_php] = off
CONF
systemctl reload "php$PHP-fpm"

echo "== nginx site"
cat > "/etc/nginx/sites-available/$NAME" <<CONF
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;

    root $DIR/public;
    index index.php;
    charset utf-8;
    client_max_body_size 8M;

    access_log /var/log/nginx/$NAME.access.log;
    error_log  /var/log/nginx/$NAME.error.log;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.svg { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/$NAME.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    gzip on;
    gzip_types text/css application/javascript application/json image/svg+xml;
}
CONF
ln -sf "/etc/nginx/sites-available/$NAME" "/etc/nginx/sites-enabled/$NAME"
nginx -t && systemctl reload nginx

echo "== Queue worker and scheduler (start once the code is installed)"
cat > "/etc/supervisor/conf.d/$NAME-worker.conf" <<CONF
[program:$NAME-worker]
command=/usr/bin/php$PHP $DIR/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
directory=$DIR
user=$NAME
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=120
redirect_stderr=true
stdout_logfile=$DIR/storage/logs/worker.log
stdout_logfile_maxbytes=5MB
stdout_logfile_backups=3
CONF
printf '* * * * * %s cd %s && /usr/bin/php%s artisan schedule:run >> /dev/null 2>&1\n' "$NAME" "$DIR" "$PHP" > "/etc/cron.d/$NAME.disabled"
chmod 644 "/etc/cron.d/$NAME.disabled"

cat <<NEXT

Done. Next, as $DEPLOY_USER:
  cd $DIR && git clone <repository> .
  sudo app-perms $NAME                      # storage/ and bootstrap/cache/ writable by $NAME
  .env: database values are in $SECRETS (sudo cat), then: sudo chgrp $NAME .env && chmod 640 .env
  composer install, npm build, sudo -u $NAME php artisan migrate --force ...
Then: sudo app-start $NAME                  # worker + scheduler
      sudo certbot --nginx -d $DOMAIN --redirect
NEXT
