# ============================================================
# FRONTEND BUILD
# ============================================================

FROM node:22-alpine AS frontend

WORKDIR /app

COPY package*.json ./

RUN npm install

COPY resources ./resources
COPY vite.config.js ./
COPY postcss.config.js ./
COPY tailwind.config.js ./
COPY jsconfig.json ./

RUN npm run build


# ============================================================
# LARAVEL / PHP
# ============================================================

FROM php:8.4-cli

WORKDIR /app


# ============================================================
# SYSTEM DEPENDENCIES + PHP EXTENSIONS
# ============================================================

RUN apt-get update \
    && apt-get install -y \
        git \
        unzip \
        zip \
        curl \
        libicu-dev \
        libzip-dev \
        libpq-dev \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        intl \
        zip \
    && rm -rf /var/lib/apt/lists/*


# ============================================================
# COMPOSER
# ============================================================

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer


# ============================================================
# LARAVEL APPLICATION
# ============================================================

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY . .


# ============================================================
# VITE PRODUCTION BUILD
# ============================================================

COPY --from=frontend /app/public/build ./public/build


# ============================================================
# LARAVEL DIRECTORIES / PERMISSIONS
# ============================================================

RUN mkdir -p \
        /app/storage/framework/cache \
        /app/storage/framework/sessions \
        /app/storage/framework/views \
        /app/storage/logs \
        /app/bootstrap/cache \
    && chown -R www-data:www-data \
        /app/storage \
        /app/bootstrap/cache \
    && chmod -R 775 \
        /app/storage \
        /app/bootstrap/cache


# ============================================================
# RAILWAY PORT
# ============================================================

EXPOSE 8080


# ============================================================
# START LARAVEL
# ============================================================

CMD ["sh", "-c", "php artisan serve --host=0.0.0.0 --port=${PORT:-8080}"]