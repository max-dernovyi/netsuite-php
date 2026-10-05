ARG PHP=8.5
FROM php:${PHP}-cli-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN install-php-extensions soap pcov \
    # Named volumes copy these permissions on first mount, so any --user can write them.
    && mkdir -p /app/vendor /composer && chmod 0777 /app/vendor /composer

ENV COMPOSER_HOME=/composer
WORKDIR /app
