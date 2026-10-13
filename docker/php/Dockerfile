# syntax=docker/dockerfile:1

FROM composer:2@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332 AS composer

FROM php:8.4-fpm-bookworm@sha256:075b11566518bfa979bb9f2fe2e5359148326d659b15a2f414c2c305a0479a4e

ARG PHPREDIS_VERSION=6.3.0

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libfcgi-bin \
        libicu-dev \
        libonig-dev \
        libpq-dev \
        libzip-dev \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_pgsql \
        pgsql \
        zip \
    && pecl install "redis-${PHPREDIS_VERSION}" \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN printf '%s\n' \
        'memory_limit=256M' \
        'upload_max_filesize=10M' \
        'post_max_size=12M' \
        'date.timezone=UTC' \
        'expose_php=Off' \
        'log_errors=On' \
        'error_log=/proc/self/fd/2' \
        > /usr/local/etc/php/conf.d/agroconecta.ini \
    && printf '%s\n' \
        '[www]' \
        'ping.path=/ping' \
        'ping.response=pong' \
        'catch_workers_output=yes' \
        'decorate_workers_output=no' \
        > /usr/local/etc/php-fpm.d/zz-agroconecta.conf \
    && mkdir -p /var/www/html /tmp/composer \
    && chown -R www-data:www-data /var/www/html /tmp/composer

ENV COMPOSER_HOME=/tmp/composer

WORKDIR /var/www/html

USER www-data

EXPOSE 9000

CMD ["php-fpm", "-F"]
