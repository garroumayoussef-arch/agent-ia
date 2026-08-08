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
# APPLICATION LARAVEL
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
# APACHE - NETTOYAGE COMPLET DES MPM
# ============================================================

RUN rm -f /etc/apache2/mods-enabled/mpm_*.load \
    && rm -f /etc/apache2/mods-enabled/mpm_*.conf

# ============================================================
# APACHE - ACTIVATION D'UN SEUL MPM
# ============================================================

RUN ln -sf /etc/apache2/mods-available/mpm_prefork.load \
        /etc/apache2/mods-enabled/mpm_prefork.load \
    && ln -sf /etc/apache2/mods-available/mpm_prefork.conf \
        /etc/apache2/mods-enabled/mpm_prefork.conf \
    && ln -sf /etc/apache2/mods-available/rewrite.load \
        /etc/apache2/mods-enabled/rewrite.load

# ============================================================
# VERIFICATION MPM
# ============================================================

RUN apache2ctl -M 2>&1 | grep mpm

# ============================================================
# DOCUMENT ROOT LARAVEL
# ============================================================

ENV APACHE_DOCUMENT_ROOT=/app/public

RUN sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

# ============================================================
# PERMISSIONS LARAVEL
# ============================================================

RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache \
    && chmod -R 775 /app/storage /app/bootstrap/cache

# ============================================================
# PORT
# ============================================================

EXPOSE 80

# ============================================================
# DEMARRAGE APACHE
# ============================================================

CMD ["apache2-foreground"]