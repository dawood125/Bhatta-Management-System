#!/usr/bin/env bash
# Make a project's storage/ and bootstrap/cache/ writable by its own user and the deploy user,
# and keep .env readable only by them (docs/11-shared-server.md).
#   sudo app-perms <name>
set -euo pipefail
NAME="${1:?usage: app-perms <name>}"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
DIR="/var/www/$NAME"
cd "$DIR"

for d in storage bootstrap/cache; do
    setfacl -R  -m u:"$NAME":rwX -m u:"$DEPLOY_USER":rwX "$d"
    setfacl -dR -m u:"$NAME":rwX -m u:"$DEPLOY_USER":rwX "$d"
done

if [ -f .env ]; then
    setfacl -b .env
    chown "$DEPLOY_USER:$NAME" .env
    chmod 640 .env
fi
echo "Permissions set for $NAME."
