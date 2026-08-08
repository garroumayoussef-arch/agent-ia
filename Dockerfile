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
# DEPENDANCES LARAVEL
# ============================================================

RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction

# ============================================================
# CONFIGURATION APACHE
# ============================================================

RUN a2dismod mpm_event mpm_worker || true \
    && a2enmod mpm_prefork \
    && a2enmod rewrite

# ============================================================
# DOCUMENT ROOT LARAVEL
# ============================================================

ENV APACHE_DOCUMENT_ROOT=/app/public

RUN sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

# ============================================================
# PORT RAILWAY
# ============================================================

RUN sed -ri 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf

RUN sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' \
    /etc/apache2/sites-available/000-default.conf

# ============================================================
# PERMISSIONS LARAVEL
# ============================================================

RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache \
    && chmod -R 775 /app/storage /app/bootstrap/cache

# ============================================================
# PORT
# ============================================================

EXPOSE 8080

# ============================================================
# DEMARRAGE APACHE
# ============================================================

CMD ["apache2-foreground"]