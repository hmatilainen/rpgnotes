FROM dunglas/frankenphp:1-php8.3

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN install-php-extensions pdo_pgsql intl zip opcache
RUN apt-get update && apt-get install -y --no-install-recommends git \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
ENV SERVER_NAME=:80
ENV APP_ENV=dev

COPY composer.json composer.lock* symfony.lock* /app/
RUN composer install --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . /app
RUN composer dump-autoload --optimize
