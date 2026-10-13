# syntax=docker/dockerfile:1
ARG PHP_RUNTIME_IMAGE=agroconecta-app:local

FROM ${PHP_RUNTIME_IMAGE} AS dependencies
USER root
WORKDIR /var/www/html
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY application/composer.json application/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY application/app ./app
COPY application/bootstrap/app.php application/bootstrap/providers.php ./bootstrap/
COPY application/config ./config
COPY application/database/migrations ./database/migrations
COPY application/database/seeders ./database/seeders
COPY application/public/index.php application/public/favicon.ico application/public/robots.txt ./public/
COPY application/resources ./resources
COPY application/routes ./routes
COPY application/artisan ./artisan
RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private \
    && composer dump-autoload --no-dev --classmap-authoritative \
    && rm -rf /tmp/composer/cache

FROM node:22-bookworm-slim@sha256:48e4b67d85f87bd551df43704e24d252f56cc5f8e9718841aace50f19948f0f9 AS assets
WORKDIR /build
COPY application/package.json application/package-lock.json ./
RUN npm ci --ignore-scripts --no-audit
COPY application/resources ./resources
COPY --from=dependencies /var/www/html/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
COPY application/vite.config.js ./
RUN npm run build

FROM ${PHP_RUNTIME_IMAGE} AS app
USER root
WORKDIR /var/www/html
COPY --from=dependencies /var/www/html /var/www/html
COPY --from=assets /build/public/build ./public/build
COPY docker/production/app-entrypoint.sh /usr/local/bin/agroconecta-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/agroconecta-entrypoint \
    && chmod 755 /usr/local/bin/agroconecta-entrypoint \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 750 storage bootstrap/cache \
    && printf '%s\n' 'display_errors=Off' 'opcache.enable=1' 'opcache.validate_timestamps=0' > /usr/local/etc/php/conf.d/zz-production.ini
ENV APP_ENV=production APP_DEBUG=false
USER www-data
ENTRYPOINT ["/usr/local/bin/agroconecta-entrypoint"]
CMD ["php-fpm", "-F"]

FROM nginx:stable-bookworm@sha256:552e7481ca93ffccd046aa658dbbed22caefbc09c66fa7cd247cbb90b8a5c609 AS web
COPY --from=app /var/www/html/public /var/www/html/public
COPY docker/production/nginx.conf /etc/nginx/nginx.conf
COPY docker/production/site.conf.template /etc/nginx/templates/site.conf.template
COPY docker/production/nginx-entrypoint.sh /usr/local/bin/agroconecta-nginx
RUN sed -i 's/\r$//' /usr/local/bin/agroconecta-nginx \
    && chmod 755 /usr/local/bin/agroconecta-nginx
USER nginx
ENTRYPOINT ["/usr/local/bin/agroconecta-nginx"]
CMD ["nginx", "-g", "daemon off;"]

