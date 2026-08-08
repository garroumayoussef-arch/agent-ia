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

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dossier de travail
WORKDIR /app

# Copier le projet
COPY . .

# Installer les dépendances PHP
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction

# =========================================================
# CONFIGURATION APACHE
# =========================================================

# Désactiver TOUS les MPM éventuellement activés
RUN rm -f /etc/apache2/mods-enabled/mpm_*.load \
          /etc/apache2/mods-enabled/mpm_*.conf

# Activer UNIQUEMENT prefork
RUN a2enmod mpm_prefork rewrite

# Document root Laravel
ENV APACHE_DOCUMENT_ROOT=/app/public

# Configurer Apache pour Laravel
RUN sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" \
    /etc/apache2/sites-available/000-default.conf \
    /etc/apache2/apache2.conf

# Permissions Laravel
RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache \
    && chmod -R 775 /app/storage /app/bootstrap/cache

# Vérifier qu'un seul MPM est chargé
RUN apache2ctl -M | grep mpm

# Port Railway
EXPOSE 80

# Démarrage Apache
CMD ["apache2-foreground"]