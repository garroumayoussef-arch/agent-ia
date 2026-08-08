FROM php:8.4-apache

# Extensions système nécessaires à Laravel et PostgreSQL
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    curl \
    libicu-dev \
    libzip-dev \
    libpq-dev \
    libonig-dev \
    && docker-php-ext-install \
    pdo_pgsql \
    intl \
    mbstring \
    zip \
    opcache \
    && rm -rf /var/lib/apt/lists/*

# Configuration Apache : un seul MPM
RUN a2dismod mpm_event mpm_worker mpm_prefork || true \
    && a2enmod mpm_prefork \
    && a2enmod rewrite

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

# Configuration Apache pour Laravel
ENV APACHE_DOCUMENT_ROOT=/app/public

RUN sed -ri \
    -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

# Permissions Laravel
RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache \
    && chmod -R 775 /app/storage /app/bootstrap/cache

# Port
EXPOSE 80

# Démarrage Apache
CMD ["apache2-foreground"]