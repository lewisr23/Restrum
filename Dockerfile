# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1 - build the React bundle.
#
# Create React App inlines REACT_APP_* at BUILD time, so anything the browser
# needs has to be known here rather than injected when the container starts.
# REACT_APP_API_BASE_URL is deliberately left empty: nginx serves the bundle
# and proxies /api to PHP on the same origin, which is the "same origin" case
# frontend/src/lib/config.ts documents.
# ---------------------------------------------------------------------------
# Node 24 to match CI and development. npm 10 and npm 11 disagree
# about lock files, and the mismatch fails `npm ci` outright.
FROM node:24-alpine AS frontend

ARG REACT_APP_REVERB_APP_KEY=""
ARG REACT_APP_REVERB_HOST="localhost"
ARG REACT_APP_REVERB_PORT="8080"
ARG REACT_APP_REVERB_SCHEME="http"

ENV REACT_APP_API_BASE_URL="" \
    REACT_APP_REVERB_APP_KEY=$REACT_APP_REVERB_APP_KEY \
    REACT_APP_REVERB_HOST=$REACT_APP_REVERB_HOST \
    REACT_APP_REVERB_PORT=$REACT_APP_REVERB_PORT \
    REACT_APP_REVERB_SCHEME=$REACT_APP_REVERB_SCHEME

WORKDIR /build

# package files first so a source-only change doesn't reinstall node_modules.
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

COPY frontend/ ./
RUN npm run build

# ---------------------------------------------------------------------------
# Stage 2 - resolve PHP dependencies.
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /build

COPY composer.json composer.lock ./

# --no-scripts because the post-install hooks want the full application tree,
# which isn't copied in yet; the autoloader is generated in the final stage.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

# ---------------------------------------------------------------------------
# Stage 3 - runtime. PHP-FPM only; nginx runs as its own container.
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS app

# pcntl earns its place here even though nothing in the application code
# calls it: Reverb's server uses SIGINT and SIGTERM to shut down cleanly,
# and without the extension those constants do not exist, so the container
# crash-loops on "Undefined constant ... SIGINT" before it ever listens.
# The same image runs app, queue-worker, scheduler and reverb, so it has to
# satisfy the most demanding of the four.
RUN apk add --no-cache mysql-client \
    && docker-php-ext-install pdo_mysql bcmath opcache pcntl

# Laravel's own recommended production opcache settings.
RUN { \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=8'; \
        echo 'opcache.max_accelerated_files=10000'; \
        echo 'opcache.revalidate_freq=0'; \
        echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www/html

# The composer binary itself, not only the packages. The autoloader is
# generated below, once the full application tree is present, and this
# runtime image has no composer of its own.
COPY --from=vendor /usr/bin/composer /usr/bin/composer

COPY --from=vendor /build/vendor ./vendor
COPY . .

# The React bundle lives outside Laravel's public/ so that nginx can serve the
# SPA and the PHP front controller from two clearly separate roots, rather
# than having index.html and index.php fight over the same directory.
COPY --from=frontend /build/build /var/www/frontend

RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && rm /usr/bin/composer \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 9000

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# Stage 4 - the web tier. Carries the React bundle so nginx can serve it
# without mounting anything from the PHP image, and proxies the API paths on
# to PHP-FPM.
# ---------------------------------------------------------------------------
FROM nginx:1.27-alpine AS web

COPY --from=frontend /build/build /var/www/frontend
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 80
