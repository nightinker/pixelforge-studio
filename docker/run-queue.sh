#!/bin/sh
cd /var/www/html
# Restart cleanly every hour so a long-lived worker never serves stale code or leaks memory.
exec php artisan queue:work --sleep=3 --tries=3 --max-time=3600
