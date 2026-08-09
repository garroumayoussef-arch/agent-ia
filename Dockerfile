FROM php:8.4-apache

# =========================================================
# EXTENSIONS PHP POUR LARAVEL + POSTGRESQL
# =========================================================

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
# DEPENDANCES LARAVEL
# =========================================================

RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction

# ============================================================
# APACHE
# ============================================================

# Désactiver tous les MPM Apache existants
RUN a2dismod mpm_event mpm_worker mpm_prefork || true \
    && rm -f /etc/apache2/mods-enabled/mpm_*.load \
              /etc/apache2/mods-enabled/mpm_*.conf \
    && a2enmod mpm_prefork \
    && a2enmod rewrite

# ============================================================
# DOCUMENT ROOT LARAVEL
# ============================================================

ENV APACHE_DOCUMENT_ROOT=/app/public

RUN sed -ri \
    -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/000-default.conf

# =========================================================
# PERMISSIONS LARAVEL
# =========================================================

RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache \
    && chmod -R 775 /app/storage /app/bootstrap/cache

# =========================================================
# PORT
# =========================================================

EXPOSE 80

# =========================================================
# DEMARRAGE APACHE
# =========================================================

CMD ["apache2-foreground"]