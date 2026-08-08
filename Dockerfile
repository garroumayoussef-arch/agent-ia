FROM php:8.4-apache

# ============================================================
# EXTENSIONS PHP POUR LARAVEL + POSTGRESQL
# ============================================================

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

# ============================================================
# COMPOSER
# ============================================================

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ============================================================
# APPLICATION
# ============================================================

WORKDIR /app

COPY . .

# ============================================================
# DÉPENDANCES LARAVEL
# ============================================================

RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction

# ============================================================
# APACHE
# ============================================================

# L'image php:apache utilise déjà mpm_prefork.
# On active uniquement rewrite pour Laravel.

RUN a2enmod rewrite

# ============================================================
# DOCUMENT ROOT LARAVEL
# ============================================================

ENV APACHE_DOCUMENT_ROOT=/app/public

RUN sed -ri \
    -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/000-default.conf \
    /etc/apache2/apache2.conf

# ============================================================
# PERMISSIONS LARAVEL
# ============================================================

RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache \
    && chmod -R 775 /app/storage /app/bootstrap/cache

# ============================================================
# PORT RAILWAY
# ============================================================

EXPOSE 80

# ============================================================
# DÉMARRAGE APACHE
# ============================================================

CMD ["sh", "-c", "sed -ri \"s/^Listen 80$/Listen ${PORT:-80}/\" /etc/apache2/ports.conf && sed -ri \"s/<VirtualHost \\*:80>/<VirtualHost *:${PORT:-80}>/\" /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]
