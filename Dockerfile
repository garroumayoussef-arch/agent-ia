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
# LARAVEL / PHP / APACHE
# ============================================================

FROM php:8.4-apache

WORKDIR /app

# ------------------------------------------------------------
# PHP extensions and system dependencies
# ------------------------------------------------------------

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


# ------------------------------------------------------------
# Composer
# ------------------------------------------------------------

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer


# ------------------------------------------------------------
# Laravel application
# ------------------------------------------------------------

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY . .


# ------------------------------------------------------------
# Vite production build
# ------------------------------------------------------------

COPY --from=frontend /app/public/build ./public/build


# ------------------------------------------------------------
# Apache configuration
# ------------------------------------------------------------

RUN a2dismod mpm_event mpm_worker mpm_prefork || true \
    && rm -f /etc/apache2/mods-enabled/mpm_*.load \
    && rm -f /etc/apache2/mods-enabled/mpm_*.conf \
    && a2enmod mpm_prefork \
    && a2enmod rewrite

ENV APACHE_DOCUMENT_ROOT=/app/public

RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/000-default.conf \
    /etc/apache2/apache2.conf


# ------------------------------------------------------------
# Laravel permissions
# ------------------------------------------------------------

RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache \
    && chmod -R 775 /app/storage /app/bootstrap/cache


# ------------------------------------------------------------
# Railway port
# ------------------------------------------------------------

EXPOSE 80

CMD ["apache2-foreground"]