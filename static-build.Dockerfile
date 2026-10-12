# static-build.Dockerfile
FROM --platform=linux/amd64 composer:2 AS composer

FROM --platform=linux/amd64 dunglas/frankenphp:static-builder-gnu
# If you intend to run the binary on musl-libc systems, use static-builder-musl instead

# Optional: pass a GitHub token to avoid API rate limits during source downloads
# docker build --build-arg GITHUB_TOKEN=ghp_... -f static-build.Dockerfile .
ARG GITHUB_TOKEN
ENV GITHUB_TOKEN=${GITHUB_TOKEN}

COPY --from=composer /usr/bin/composer /usr/bin/composer
RUN printf '#!/bin/bash\nexec /go/src/app/dist/static-php-cli/buildroot/bin/frankenphp php-cli "$@"\n' > /usr/local/bin/php \
    && chmod +x /usr/local/bin/php

ENV COMPOSER_ALLOW_SUPERUSER=1

# Copy your app
WORKDIR /go/src/app/dist/app
COPY . .

# Remove the tests and other unneeded files to save space
# Alternatively, add these files to a .dockerignore file
RUN rm -Rf tests/
RUN rm -Rf phpunit.xml .phpantom.toml boost.json
RUN rm -f bootstrap/cache/*.php

# Copy .env file
RUN cp .env.example .env

# Change APP_ENV and APP_DEBUG to be production ready
RUN sed -i'' -e 's/^APP_ENV=.*/APP_ENV=production/' -e 's/^APP_DEBUG=.*/APP_DEBUG=false/' .env

# Install the dependencies (skip post-install scripts that need DB/services)
RUN composer install --ignore-platform-reqs --no-dev -a --no-scripts --no-interaction

# Laravel resolves view.compiled via realpath(); the directory must exist at build time
RUN mkdir -p bootstrap/cache storage/framework/{cache,sessions,views} storage/logs

# Build the static binary
WORKDIR /go/src/app/
# curl's static lib is built with libssh2/zstd support; they must be in PHP_EXTENSION_LIBS or xcaddy link fails
RUN PHP_EXTENSIONS="bcmath,ctype,curl,dom,fileinfo,filter,mbstring,openssl,pcntl,pdo,pdo_pgsql,pgsql,redis,session,sockets,tokenizer,xml,zip,opcache" \
    PHP_EXTENSION_LIBS="brotli,watcher,libavif,nghttp2,nghttp3,ngtcp2,curl,libxml2,openssl,postgresql,libzip,zlib,libiconv,libssh2,zstd" \
    EMBED=dist/app/ ./build-static.sh
