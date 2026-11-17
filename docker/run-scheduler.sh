#!/bin/sh
cd /var/www/html
# Runs the schedule (generations:reconcile every minute) in the foreground.
exec php artisan schedule:work
