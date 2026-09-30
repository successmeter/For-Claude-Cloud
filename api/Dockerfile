# api/Dockerfile
# One image, four roles (docker/entrypoint.sh): web, worker, scheduler, migrate.
# PHP 8.4 on FrankenPHP in classic mode: every request boots the app fresh, as under PHP-FPM
# (no Octane-style state carried between requests; see the Plan B notes). The base image already
# has every extension the app needs (pdo_pgsql, pcntl, sodium, opcache, zip; composer
# check-platform-reqs lists the rest), so nothing is compiled here.

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress --ignore-platform-reqs

FROM serversideup/php:8.4-frankenphp-alpine AS app
USER root
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
WORKDIR /var/www/html
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
COPY --chown=www-data:www-data . .
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && rm /usr/local/bin/composer \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
RUN php artisan package:discover --ansi

ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr
EXPOSE 8080
ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["web"]
