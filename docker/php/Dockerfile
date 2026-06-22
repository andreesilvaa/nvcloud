FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    zip \
    unzip \
    libzip-dev \
    default-mysql-client \
    poppler-utils \
    tesseract-ocr \
    tesseract-ocr-por \
    && docker-php-ext-install mysqli pdo pdo_mysql zip \
    && a2enmod rewrite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*