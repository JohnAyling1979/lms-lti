# api service image: php:8.4-cli + phpredis (reads sessions from Redis).
# The Redis client is a PHP extension baked into the image, so this service needs
# no Composer dependency at all.
FROM php:8.4-cli
RUN pecl install redis && docker-php-ext-enable redis
