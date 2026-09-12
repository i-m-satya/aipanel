FROM php:8.3-fpm-alpine

RUN apk add --no-cache git openssh-client nginx supervisor mysql-client \
    && docker-php-ext-install pdo_mysql opcache \
    && apk add --no-cache --virtual .build $PHPIZE_DEPS linux-headers \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-dev --no-scripts --prefer-dist

COPY . .
RUN composer dump-autoload --optimize --no-dev

EXPOSE 9000
CMD ["php-fpm"]
