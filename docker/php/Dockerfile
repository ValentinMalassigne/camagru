# Camagru PHP image: php-fpm with gd (jpeg/png/freetype), pdo_pgsql, msmtp, CA certs.
# Native logging: php-fpm runs with the image's own settings (log_level notice,
# no access log, catch_workers_output off) and logs to stderr, which the
# container console shows.
FROM php:8.3-fpm-alpine

# Build dependencies for gd (freetype, libjpeg, libpng) and runtime tools.
RUN apk add --no-cache \
        freetype \
        libjpeg-turbo \
        libpng \
        postgresql-libs \
        msmtp \
        ca-certificates \
    && apk add --no-cache --virtual .build-deps \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        postgresql-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd pdo_pgsql \
    && apk del .build-deps

# Confirm the required extensions are present (fails the build if missing).
RUN php -m | grep -E '^(gd|pdo_pgsql|fileinfo|mbstring)$'

# Application code is mounted at runtime; set the working directory.
WORKDIR /var/www/camagru

# Custom php.ini.
COPY docker/php/php.ini /usr/local/etc/php/php.ini

# Entrypoint generates the msmtp config, runs the DB setup, then starts php-fpm.
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
