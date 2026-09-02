# syntax=docker/dockerfile:1
#
# Production image for Cloud Run: nginx + php-fpm + supervisord in a single
# container (Cloud Run only runs one container per revision, so the web
# server and the PHP runtime are supervised together instead of split into
# sidecars). Two build stages keep Node/npm out of the final runtime image.
#
# Local dev keeps using SQLite (see .env.example) and `php artisan serve` /
# `composer run dev` — this file is production-only, built and pushed by
# .github/workflows/deploy-cloud-run.yml.

##############################
# Stage 1: frontend assets   #
##############################
FROM node:24-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

##############################
# Stage 2: runtime image     #
##############################
FROM php:8.4-fpm-alpine AS app

# System packages:
#   nginx      - HTTP server, reverse-proxies PHP requests to php-fpm
#   supervisor - runs nginx + php-fpm as one PID 1 process (Cloud Run runs
#                a single container per revision)
#   gettext    - provides envsubst, used at startup to inject $PORT into the
#                nginx config (Cloud Run assigns the listen port at runtime)
RUN apk add --no-cache \
        nginx \
        supervisor \
        gettext \
        postgresql-libs

# Build-only dependencies for the PHP extensions below, removed after build.
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        postgresql-dev \
        oniguruma-dev \
        curl-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo \
        pdo_pgsql \
        pgsql \
        mbstring \
        bcmath \
        pcntl \
        curl \
        opcache \
    && apk del .build-deps

# Recommended production opcache settings.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=8'; \
        echo 'opcache.max_accelerated_files=10000'; \
        echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini

# php-fpm defaults to `clear_env = yes`, which strips container environment
# variables (APP_KEY, DB_*, secrets from Secret Manager, ...) before PHP-FPM
# workers start. Cloud Run has no other way to hand Laravel its config, so
# this MUST stay disabled.
RUN { \
        echo '[www]'; \
        echo 'clear_env = no'; \
        echo 'catch_workers_output = yes'; \
        echo 'decorate_workers_output = no'; \
    } > /usr/local/etc/php-fpm.d/zz-cloud-run.conf

WORKDIR /var/www/html

# Install PHP dependencies first so this layer is cached across app-code
# changes that don't touch composer.json/composer.lock.
COPY composer.json composer.lock ./
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --no-progress \
        --optimize-autoloader

# Now bring in the application code.
COPY . .
COPY --from=frontend /app/public/build ./public/build

# Run composer's normal post-install scripts now that the full app (and all
# extensions) are present, then drop the dev-only asset source that already
# got compiled above.
RUN composer run-script post-autoload-dump \
    && rm -rf resources/js resources/css \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/nginx.conf.template /etc/nginx/templates/nginx.conf.template
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Cloud Run sets $PORT (defaults to 8080) and expects the container to
# listen on it; 8080 also matches `docker run -p 8080:8080` for local testing.
ENV PORT=8080
EXPOSE 8080

ENTRYPOINT ["entrypoint.sh"]
