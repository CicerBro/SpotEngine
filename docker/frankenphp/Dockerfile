FROM dunglas/frankenphp:1-php8.5

WORKDIR /app

RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

RUN install-php-extensions \
    bcmath \
    pcntl \
    pdo_pgsql \
    pgsql \
    redis \
    sockets

RUN apt update && apt install -y curl ca-certificates procps nano htop wget iputils-ping \
    && install -d /usr/share/postgresql-common/pgdg \
    && curl -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc --fail https://www.postgresql.org/media/keys/ACCC4CF8.asc \
    && . /etc/os-release \
    && printf "Types: deb\nURIs: https://apt.postgresql.org/pub/repos/apt\nSuites: ${VERSION_CODENAME}-pgdg\nArchitectures: $(dpkg --print-architecture)\nComponents: main\nSigned-By: /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc\n" > /etc/apt/sources.list.d/pgdg.sources \
    && apt update \
    && apt install -y postgresql-client-18

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/frankenphp/conf.d/99-app.ini /usr/local/etc/php/conf.d/99-app.ini

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
