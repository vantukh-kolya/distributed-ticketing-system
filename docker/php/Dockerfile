# syntax=docker/dockerfile:1

FROM php:8.4-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        libpq-dev \
        librabbitmq-dev \
        unzip \
    && pecl install amqp \
    && docker-php-ext-enable amqp \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ARG SERVICE_PATH
WORKDIR /app/${SERVICE_PATH}

COPY contracts /app/contracts
COPY packages/messenger-idempotency /app/packages/messenger-idempotency
COPY ${SERVICE_PATH}/composer.json ${SERVICE_PATH}/composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts

COPY ${SERVICE_PATH} .
RUN composer dump-autoload --optimize

COPY docker/php/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
