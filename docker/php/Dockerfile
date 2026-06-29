FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    zip \
    unzip \
    libzip-dev \
    default-mysql-client \
    poppler-utils \
    tesseract-ocr \
    tesseract-ocr-por \
    curl \
    && docker-php-ext-install mysqli pdo pdo_mysql zip opcache \
    && a2enmod rewrite expires deflate headers \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# OPcache estava completamente desligado — cada pedido HTTP obrigava o
# PHP a reanalisar e recompilar o app.php (8000+ linhas) e todos os
# includes do zero. Isto guarda o bytecode compilado em memória entre
# pedidos, o que tipicamente reduz bastante o tempo de resposta numa
# app deste tamanho. validate_timestamps=1 mantém a detetação automática
# de ficheiros alterados (sem precisar de reiniciar o Apache a cada deploy).
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=10000'; \
        echo 'opcache.validate_timestamps=1'; \
        echo 'opcache.revalidate_freq=2'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini

# Esconde a versao do Apache/PHP nos headers de resposta
RUN { \
        echo 'ServerTokens Prod'; \
        echo 'ServerSignature Off'; \
    } >> /etc/apache2/apache2.conf \
    && echo 'expose_php = Off' > /usr/local/etc/php/conf.d/zz-security.ini

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://localhost/login.php -o /dev/null || exit 1