# One tenant's runtime: nginx and PHP-FPM together, serving only that tenant.
#
# Both in one container on purpose. The edge then needs nothing but a port to
# proxy to — it never mounts a tenant's filesystem, so a compromised edge cannot
# read tenant code, and a tenant's static files are served by its own nginx.
#
# Build:  docker build -f docker/tenant/Dockerfile --build-arg PHP=8.3 \
#           -t aipanel/php:8.3 docker/tenant
ARG PHP=8.3
FROM php:${PHP}-fpm-alpine

RUN apk add --no-cache nginx gettext \
    && docker-php-ext-install pdo_mysql opcache \
    && apk add --no-cache --virtual .build $PHPIZE_DEPS \
    && apk del .build \
    # Writable by any uid: the container runs as the tenant's uid, which does
    # not exist in this image's passwd database.
    && mkdir -p /run/nginx /var/lib/nginx/tmp /var/log/nginx \
    && chmod -R 0777 /run/nginx /var/lib/nginx /var/log/nginx

COPY nginx.conf /etc/nginx/nginx.conf
COPY php-fpm.conf /usr/local/etc/php-fpm.d/zz-aipanel.conf
COPY php.ini /usr/local/etc/php/conf.d/zz-aipanel.ini
COPY entrypoint.sh /usr/local/bin/entrypoint
RUN chmod 0755 /usr/local/bin/entrypoint

# The release is mounted read-only at /app; writable state at /app/shared.
WORKDIR /app
EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint"]
