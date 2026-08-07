FROM php:8.4-cli

# PHP + dépendances
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    curl \
    libicu-dev \
    libzip-dev \
    && docker-php-ext-install intl zip pdo pdo_mysql

# Installer Node.js 20
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

# Installer PHP
RUN composer install --no-dev --optimize-autoloader

# Installer Node
RUN npm install

# Construire Vite
RUN npm run build

EXPOSE 8000

CMD php artisan serve --host=0.0.0.0 --port=8000