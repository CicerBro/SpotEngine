FROM dunglas/frankenphp:latest

WORKDIR /app

RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

RUN install-php-extensions \
    bcmath \
    pcntl \
    pdo_pgsql \
    pgsql \
    redis \
    sockets

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/frankenphp/conf.d/99-app.ini /usr/local/etc/php/conf.d/99-app.ini

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
