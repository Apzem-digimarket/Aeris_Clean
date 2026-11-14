#!/bin/zsh
# Double-click this file in Finder to open the Aeris Clean admin.
# It starts the local admin (if it isn't running yet) and opens it in your browser.
cd "$(dirname "$0")/.."
if ! lsof -nP -iTCP:8772 -sTCP:LISTEN >/dev/null 2>&1; then
  PHP=/opt/homebrew/bin/php; [ -x "$PHP" ] || PHP=php
  PHP_CLI_SERVER_WORKERS=4 nohup "$PHP" -S 127.0.0.1:8772 _cms/router.php >/dev/null 2>&1 &
  sleep 1
fi
open "http://127.0.0.1:8772/admin/"
echo "Aeris Clean admin: http://127.0.0.1:8772/admin/  (you can close this window)"
