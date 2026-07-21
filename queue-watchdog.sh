#!/usr/bin/env bash
cd "$HOME/sites/v3.app24.ma/back-end" || exit 1
if ! pgrep -f "artisan queue:work" >/dev/null; then
  nohup /opt/php8.3/bin/php artisan queue:work --sleep=3 --tries=3 --timeout=1800 >> "$HOME/sites/v3.app24.ma/back-end/storage/logs/queue-worker.log" 2>&1 &
fi
