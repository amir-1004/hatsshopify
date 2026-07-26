# Stage 1: build frontend assets (Tailwind v4 via Vite)
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm install
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# Stage 2: PHP runtime
FROM php:8.5-cli AS app

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev unzip git \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

COPY . .
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi \
    && chmod +x docker/start.sh

EXPOSE 8080
CMD ["docker/start.sh"]
