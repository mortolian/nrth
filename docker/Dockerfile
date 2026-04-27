# syntax=docker/dockerfile:1

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --prefer-dist \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --ignore-platform-reqs

FROM node:22-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
COPY --from=vendor /app/vendor ./vendor
ARG VITE_APP_NAME=Laravel
ENV VITE_APP_NAME=${VITE_APP_NAME}
RUN npm run build

FROM php:8.4-cli-bookworm

ARG APP_TITLE=Laravel
LABEL org.opencontainers.image.title="${APP_TITLE}"

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    git \
    unzip \
    libicu-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libpq-dev \
    libzip-dev \
    libexif-dev \
    zlib1g-dev \
    procps \
    postgresql-client \
    supervisor \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
    bcmath \
    exif \
    gd \
    intl \
    opcache \
    pcntl \
    pdo_pgsql \
    zip

RUN pecl install redis swoole \
    && docker-php-ext-enable redis swoole

# Node/npm in the runtime image so `sail npm` / Vite can run inside the app container (not on the host).
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -sf /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build
COPY --from=frontend /app/node_modules ./node_modules

RUN mkdir -p storage/framework/{sessions,views,cache} storage/logs bootstrap/cache \
    && cp .env.example .env \
    && php artisan key:generate --force --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache \
    && composer dump-autoload --optimize --no-scripts

COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
COPY docker/supervisord.conf /etc/supervisor/conf.d/laravel.conf

RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
