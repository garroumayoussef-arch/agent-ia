# =========================================================
# FRONTEND BUILD
# =========================================================

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


# =========================================================
# LARAVEL / PHP / APACHE
# =========================================================

FROM php:8.4-apache

# Extensions nécessaires à Laravel et PostgreSQL
RUN apt-get update && apt-get install -y \
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


# =========================================================
# COMPOSER
# =========================================================

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer


# =========================================================
# APPLICATION
# =========================================================

WORKDIR /app

COPY . .


# =========================================================
# VITE BUILD
# =========================================================

COPY --from=frontend /app/public/build ./public/build


# =========================================================
# COMPOSER INSTALL
# =========================================================

RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction


# =========================================================
# APACHE
# =========================================================

RUN rm -f /etc/apache2/mods-enabled/mpm_*.load \
    /etc/apache2/mods-enabled/mpm_*.conf

RUN a2enmod mpm_prefork rewrite

ENV APACHE_DOCUMENT_ROOT=/app/public

RUN sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" \
    /etc/apache2/sites-available/000-default.conf \
    /etc/apache2/apache2.conf


# =========================================================
# PERMISSIONS LARAVEL
# =========================================================

RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache \
    && chmod -R 775 /app/storage /app/bootstrap/cache


# =========================================================
# PORT
# =========================================================

EXPOSE 80

CMD ["apache2-foreground"]