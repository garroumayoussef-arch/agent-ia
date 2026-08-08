FROM php:8.4-apache

<<<<<<< HEAD
# =========================================================
# EXTENSIONS PHP POUR LARAVEL + POSTGRESQL
# =========================================================
=======
# Extensions système nécessaires à Laravel et PostgreSQL
>>>>>>> 648c816 (Fix Apache multiple MPM)

RUN apt-get update && apt-get install -y \

    git \

    unzip \

    zip \

    curl \

    libicu-dev \

    libzip-dev \

    libpq-dev \

    && docker-php-ext-install \
<<<<<<< HEAD
    pdo \
    pdo_pgsql \
    intl \
    zip \
=======

    pdo \

    pdo_pgsql \

    intl \

    zip \

>>>>>>> 648c816 (Fix Apache multiple MPM)
    && rm -rf /var/lib/apt/lists/*
    
# =========================================================
# COMPOSER
# =========================================================

<<<<<<< HEAD
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# =========================================================
# APPLICATION
# =========================================================

WORKDIR /app

COPY . .

# =========================================================
# DÉPENDANCES LARAVEL
# =========================================================
=======
# Composer

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dossier de travail

WORKDIR /app

# Copier le projet

COPY . .

# Installer les dépendances PHP
>>>>>>> 648c816 (Fix Apache multiple MPM)

RUN composer install \

    --no-dev \

    --optimize-autoloader \

    --no-interaction

<<<<<<< HEAD
# =========================================================
# APACHE
# =========================================================

# L'image officielle php:apache utilise déjà mpm_prefork.
# On active uniquement rewrite pour Laravel.
RUN a2enmod rewrite

# =========================================================
# DocumentRoot Laravel
# =========================================================

ENV APACHE_DOCUMENT_ROOT=/app/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/000-default.conf \

    /etc/apache2/apache2.conf

# =========================================================

# PERMISSIONS LARAVEL

# =========================================================

RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache && \
    chmod -R 775 /app/storage /app/bootstrap/cache

# =========================================================
# PORT
# =========================================================

EXPOSE 80

# =========================================================
# DÉMARRAGE
# =========================================================

CMD ["apache2-foreground"]
=======
# Configuration Apache pour Laravel

# Supprimer complètement tous les MPM déjà activés

RUN rm -f /etc/apache2/mods-enabled/mpm_*.load \

    /etc/apache2/mods-enabled/mpm_*.conf

# Activer uniquement le MPM prefork et rewrite

RUN a2enmod mpm_prefork rewrite

# Document root Laravel

ENV APACHE_DOCUMENT_ROOT=/app/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \

    /etc/apache2/sites-available/*.conf \

    /etc/apache2/apache2.conf \

    /etc/apache2/conf-available/*.conf

# Permissions Laravel

RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache \

    && chmod -R 775 /app/storage /app/bootstrap/cache

# Port Railway

EXPOSE 80

# Démarrer Apache

CMD ["apache2-foreground"]
>>>>>>> 648c816 (Fix Apache multiple MPM)
