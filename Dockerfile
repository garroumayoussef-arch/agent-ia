# ============================================================
# FRONTEND BUILD
# ============================================================

FROM node:22-alpine AS frontend

WORKDIR /app

COPY package*.json ./

RUN npm ci

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

# ------------------------------------------------------------
# SYSTEM DEPENDENCIES + PHP EXTENSIONS
# ------------------------------------------------------------

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    curl \
    libicu-dev \
    libpq-dev \
    libzip-dev \
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
# APPLICATION
# ============================================================

COPY . .


# ============================================================
# VITE BUILD
# ============================================================

COPY --from=frontend /app/public/build ./public/build


# ============================================================
# COMPOSER INSTALL
# ============================================================

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader


# ============================================================
# LARAVEL PERMISSIONS
# ============================================================

RUN mkdir -p storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache


# ============================================================
# RAILWAY PORT
# ============================================================

EXPOSE 8080


# ============================================================
# START LARAVEL
# ============================================================

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t public"]