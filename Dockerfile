ARG PHP_VERSION=8.2

# ────────────────────────────────────────────────────────────────
# Stage 1 — Composer dependencies (cached on composer.json)
# ────────────────────────────────────────────────────────────────
FROM composer:2 AS vendor

ARG WITH_WEBMAN=true
ARG PHP_VERSION=8.2

WORKDIR /app
COPY composer.json ./
COPY composer.lock* ./
# composer.json's classmap references database/seeds and database/factories —
# create them so `composer install` can generate the autoloader without the
# full source tree (that is copied in the app stage).
RUN mkdir -p database/seeds database/factories

# Pin dependency resolution to the runtime PHP version. Without a committed
# composer.lock, Composer would otherwise resolve to the newest releases, which
# can demand a PHP newer than the app stage ships.
RUN composer config platform.php "${PHP_VERSION}.0" --no-interaction

# `require` must resolve and write the lock in the same step: with --no-update
# it edits composer.json only, and the `composer install` below then aborts
# because the lock no longer matches ("not present in the lock file").
# pcntl is absent from this build image but present in the app stage.
RUN if [ "$WITH_WEBMAN" = "true" ]; then \
      composer require joanhey/adapterman \
        --no-install \
        --no-interaction \
        --no-scripts \
        --no-progress \
        --ignore-platform-req=ext-pcntl; \
    fi

RUN composer install \
      --no-dev \
      --prefer-dist \
      --optimize-autoloader \
      --no-interaction \
      --no-progress \
      --no-scripts \
      --ignore-platform-req=ext-pcntl

# ────────────────────────────────────────────────────────────────
# Stage 2 — Runtime app (PHP-FPM + artisan + Horizon)
# ────────────────────────────────────────────────────────────────
FROM php:${PHP_VERSION}-fpm-alpine AS app

ENV TZ=Asia/Shanghai

RUN apk add --no-cache \
      tzdata curl bash mariadb-client netcat-openbsd su-exec \
      libpng libjpeg-turbo freetype oniguruma libzip libsodium \
      ca-certificates \
    && apk add --no-cache --virtual .build-deps \
      $PHPIZE_DEPS \
      libpng-dev libjpeg-turbo-dev freetype-dev \
      oniguruma-dev libzip-dev libsodium-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
      gd pdo_mysql mbstring zip bcmath pcntl posix exif \
    && { php -m | grep -qi '^sodium$' || docker-php-ext-install sodium; } \
    && { php -m | grep -qi 'opcache'  || docker-php-ext-install opcache || docker-php-ext-enable opcache; } \
    && pecl install igbinary redis \
    && docker-php-ext-enable igbinary redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear /var/cache/apk/* \
    && cp /usr/share/zoneinfo/${TZ} /etc/localtime \
    && echo "${TZ}" > /etc/timezone

WORKDIR /var/www

COPY .docker/php/php.ini /usr/local/etc/php/conf.d/99-v2board.ini
COPY .docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/zz-v2board.conf

COPY . .

COPY --from=vendor /app/vendor ./vendor

# Pristine copy of config/ for runtime sync: /var/www/config is shadowed by
# the app_config volume, so the entrypoint diffs the volume against this
# directory to deliver new framework config on upgrade (user-managed
# config/v2board.php and config/theme/*.php are never overwritten).
RUN cp -a config /opt/v2board-config-pristine

RUN chmod +x .docker/entrypoint.sh \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views \
                storage/logs storage/app/public bootstrap/cache \
    && ln -sfn ../storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000

# Entrypoint runs as root so it can fix ownership of bind-mounted .env/storage;
# it drops to www-data before exec via su-exec.
ENTRYPOINT ["/var/www/.docker/entrypoint.sh"]
CMD ["php-fpm"]

# ────────────────────────────────────────────────────────────────
# Stage 3 — Nginx
# ────────────────────────────────────────────────────────────────
FROM nginx:stable-alpine AS nginx

COPY --from=app /var/www/public /var/www/public
COPY .docker/nginx/default.conf /etc/nginx/conf.d/default.conf

RUN ln -sfn /var/www/storage/app/public /var/www/public/storage

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=3s --retries=3 \
  CMD wget -qO- http://127.0.0.1/healthz >/dev/null 2>&1 || exit 1
