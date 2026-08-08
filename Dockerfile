FROM php:8.4-apache

# =========================================================

# EXTENSIONS NÉCESSAIRES À LARAVEL ET POSTGRESQL

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

# DOSSIER DE TRAVAIL

# =========================================================

WORKDIR /app

# =========================================================

# COPIER LE PROJET

# =========================================================

COPY . .

# =========================================================

# INSTALLER LES DÉPENDANCES PHP

# =========================================================

RUN composer install \

    --no-dev \

    --optimize-autoloader \

    --no-interaction

# =========================================================

# CONFIGURATION APACHE

# =========================================================

# Désactiver tous les MPM éventuellement activés

RUN rm -f /etc/apache2/mods-enabled/mpm_*.load \

          /etc/apache2/mods-enabled/mpm_*.conf

# Désactiver les MPM Apache

RUN a2dismod mpm_event mpm_worker mpm_prefork || true

# Activer UNIQUEMENT le MPM prefork

RUN a2enmod mpm_prefork

# Activer le module rewrite pour Laravel

RUN a2enmod rewrite

# =========================================================

# DOCUMENT ROOT LARAVEL

# =========================================================

ENV APACHE_DOCUMENT_ROOT=/app/public

# Configurer Apache pour Laravel

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \

    /etc/apache2/sites-available/000-default.conf \

    /etc/apache2/apache2.conf

# =========================================================

# PERMISSIONS LARAVEL

# =========================================================

RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache && \

    chmod -R 775 /app/storage /app/bootstrap/cache

# =========================================================

# VÉRIFICATION DE LA CONFIGURATION APACHE

# =========================================================

RUN apache2ctl configtest

# Vérifier le MPM chargé

RUN apache2ctl -M 2>/dev/null | grep 'mpm_'

# =========================================================

# PORT RAILWAY

# =========================================================

EXPOSE 80

# =========================================================

# DÉMARRAGE APACHE

# =========================================================

CMD ["apache2-foreground"]
