# auth service image: php:8.4-cli + phpredis (ephemeral: sessions/nonces/tokens
# in Redis) + pdo_mysql (durable: the tool's own DB — submissions). Baking the
# extensions in mirrors a real prod PHP image (FrankenPHP, Cloud Run). Composer
# deps are installed separately (see composer.json).
FROM php:8.4-cli
RUN pecl install redis && docker-php-ext-enable redis \
    && docker-php-ext-install pdo_mysql
