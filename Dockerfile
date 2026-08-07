FROM php:8.4-cli

# PHP + dépendances système
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    curl \
    libicu-dev \
    libzip-dev \
    && docker-php-ext-install intl zip pdo pdo_mysql pdo_pgsql

# Installer Node.js 20
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dossier de travail
WORKDIR /app

# Copier le projet
COPY . .

# Installer les dépendances PHP
RUN composer install --no-dev --optimize-autoloader

# Installer les dépendances Node
RUN npm install

# Construire les assets Vite
RUN npm run build

# Optimiser Laravel
RUN php artisan config:cache && \
    php artisan route:cache && \
    php artisan view:cache

# Port Railway
EXPOSE 8000

# Démarrer Laravel
CMD php artisan serve --host=0.0.0.0 --port=8000