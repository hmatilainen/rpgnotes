FROM dunglas/frankenphp:1-php8.3 AS base

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN install-php-extensions pdo_pgsql intl zip opcache
RUN apt-get update && apt-get install -y --no-install-recommends git \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

FROM base AS production

ENV SERVER_NAME=:80
ENV APP_ENV=prod
ENV APP_DEBUG=0

COPY composer.json composer.lock symfony.lock /app/
RUN composer install --no-scripts --no-autoloader --prefer-dist --no-interaction --no-dev

COPY . /app/
RUN composer dump-autoload --optimize --classmap-authoritative \
    && php bin/console cache:clear --env=prod --no-warmup || true

FROM base AS development

ENV SERVER_NAME=:80
ENV APP_ENV=dev

COPY composer.json composer.lock symfony.lock* /app/
RUN composer install --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . /app/
RUN composer dump-autoload --optimize
