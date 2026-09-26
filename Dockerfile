FROM php:8.3-fpm-alpine AS application
RUN apk add --no-cache icu-dev libpq-dev oniguruma-dev libzip-dev curl-dev \
    && docker-php-ext-install pdo_pgsql pgsql mbstring intl bcmath pcntl zip curl opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 9000
CMD ["php-fpm"]

FROM nginx:1.28-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=application /var/www/html/public /var/www/html/public

FROM application AS runtime

FROM php:8.3-fpm-alpine AS test
# The test image needs a readable .env file; Compose injects the real test environment.
RUN apk add --no-cache icu-dev libpq-dev oniguruma-dev libzip-dev curl-dev \
    && docker-php-ext-install pdo_pgsql pgsql mbstring intl bcmath pcntl zip curl opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN cp .env.example .env
RUN composer install --prefer-dist --no-interaction --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
