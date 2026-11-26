#!/usr/bin/env bash
# Start a project's queue worker and scheduler once its code is installed (docs/11-shared-server.md).
#   sudo app-start <name>
set -euo pipefail
NAME="${1:?usage: app-start <name>}"

if [ -f "/etc/cron.d/$NAME.disabled" ]; then
    mv "/etc/cron.d/$NAME.disabled" "/etc/cron.d/$NAME"
fi
supervisorctl reread >/dev/null
supervisorctl update
supervisorctl status "$NAME-worker:*" 2>/dev/null || supervisorctl status "$NAME-worker"
echo "Scheduler: /etc/cron.d/$NAME"
