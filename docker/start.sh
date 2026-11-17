#!/bin/sh
# Bring the web server up FIRST so the platform's port check passes quickly, then
# do the slow work (migrations, config cache, worker, scheduler) in the background.
# A fresh Cloudflare instance reads its image lazily, so the first PHP boot is slow.
cd /var/www/html
LOG=/var/log/start.log
log() { echo "$(date +%T) $*" >> "$LOG"; }
log "start"

chown -R www-data:www-data storage bootstrap/cache
php-fpm -D && log "php-fpm up"
nginx && log "nginx up"

(
  # Pull the code into the page cache with many parallel readers.
  find vendor app config routes bootstrap resources database -type f 2>/dev/null | xargs -P 32 -n 40 cat > /dev/null 2>&1
  log "prefetch done"

  if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force --no-interaction >> "$LOG" 2>&1 && log "migrate ok" || log "migrate FAILED"
  fi

  php artisan config:cache --no-interaction >> "$LOG" 2>&1 && log "config cached" || log "config:cache FAILED"

  # Restart the background processes if they ever exit, without taking the web server down.
  ( while true; do su -s /bin/sh www-data -c /usr/local/bin/run-queue.sh >> /var/log/queue.log 2>&1; sleep 2; done ) &
  ( while true; do su -s /bin/sh www-data -c /usr/local/bin/run-scheduler.sh >> /var/log/scheduler.log 2>&1; sleep 2; done ) &
  log "worker + scheduler started"
) &

# Keep PID 1 alive for as long as nginx is; if nginx dies, exit so the platform restarts us.
while kill -0 "$(cat /run/nginx.pid 2>/dev/null)" 2>/dev/null; do sleep 5; done
log "nginx exited"
exit 1
