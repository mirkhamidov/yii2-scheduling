FROM composer

FROM php:8.0-cli

RUN apt-get update && \
    apt-get install -y \
        git \
        libonig-dev \
        unzip

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json .

RUN composer install --prefer-dist --no-interaction --no-ansi

COPY . .
