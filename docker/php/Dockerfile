FROM php:7.4-fpm

# Установка зависимостей для GD и PHP-расширений
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    zip \
    curl \
    git \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    libpq-dev \
    bash

# Установка GD
RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg

# Установка PHP-расширений
RUN docker-php-ext-install \
    gd \
    pdo \
    pdo_pgsql \
    mbstring \
    zip \
    xml \
    curl

RUN pecl install redis \
    && docker-php-ext-enable redis

# Установка Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Установка рабочей директории
WORKDIR /var/www/html
