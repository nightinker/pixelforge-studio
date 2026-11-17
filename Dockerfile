# syntax=docker/dockerfile:1
#
# Pixelforge Studio as one container: nginx + php-fpm + a queue worker + the
# scheduler, listening on :8080. Nothing here is environment-specific; all
# configuration arrives as environment variables.

# --- 1. PHP dependencies -------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist \
    --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    --no-interaction --ignore-platform-reqs

# --- 2. Front-end assets (Vite) ------------------------------------------------
FROM node:20-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js postcss.config.js tailwind.config.js jsconfig.json ./
COPY resources ./resources
COPY public ./public
# The app imports Ziggy straight from vendor/, so the Node stage needs it.
COPY --from=vendor /app/vendor/tightenco ./vendor/tightenco
RUN npm run build

# --- 3. Runtime ----------------------------------------------------------------
FROM php:8.4-fpm-bookworm
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx curl \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_pgsql pgsql bcmath intl zip opcache pcntl

WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php-fpm-listen.conf /usr/local/etc/php-fpm.d/zz-listen.conf
COPY docker/start.sh docker/run-queue.sh docker/run-scheduler.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/start.sh /usr/local/bin/run-queue.sh /usr/local/bin/run-scheduler.sh \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache /var/log/nginx \
    && chown -R www-data:www-data storage bootstrap/cache

# No Redis here, so queue, cache and sessions live in Postgres. The cache must be
# shared: it holds the locks that stop two reconcile runs or two identical submit
# jobs from overlapping.
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=single \
    DB_CONNECTION=pgsql \
    QUEUE_CONNECTION=database \
    CACHE_STORE=database \
    SESSION_DRIVER=database

# Only caches that do not depend on runtime env are built into the image.
RUN php artisan package:discover --ansi --no-interaction \
    && php artisan route:cache --no-interaction && php artisan event:cache --no-interaction \
    && (php artisan view:cache --no-interaction || true)

EXPOSE 8080
CMD ["/usr/local/bin/start.sh"]
