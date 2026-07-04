# auth service image: php:8.4-cli + phpredis (writes sessions/nonces to Redis).
# Baking the extension into the image mirrors a real prod PHP image (FrankenPHP,
# Cloud Run). Composer deps are installed separately (see composer.json).
FROM php:8.4-cli
RUN pecl install redis && docker-php-ext-enable redis
