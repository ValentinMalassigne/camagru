# Camagru PHP image: php-fpm with gd (jpeg/png/freetype), pdo_pgsql, msmtp, CA certs.
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
RUN php -m | grep -E '^(gd|pdo_pgsql|fileinfo|mbstring|openssl)$'

# Application code is mounted at runtime; set the working directory.
WORKDIR /var/www/camagru

# Custom php-fpm tuning: quiet log level, no access log.
COPY docker/php/camagru-fpm.conf /usr/local/etc/php-fpm.d/zz-camagru.conf
# Suppress notice-level php-fpm startup lines ("ready to handle connections").
# log_level is a global directive: set it on the commented default line, which
# sits in the [global] section before the pool include.
RUN sed -i 's|^;log_level = notice|log_level = error|' /usr/local/etc/php-fpm.conf
# Custom php.ini.
COPY docker/php/php.ini /usr/local/etc/php/php.ini

# Entrypoint generates the msmtp config, runs the DB setup, then starts php-fpm.
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
