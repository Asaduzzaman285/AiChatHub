FROM php:8.3-cli-alpine

# See infrastructure/docker/php/Dockerfile's identical ARG comment — same reasoning.
ARG SERVICE_DIR

# System dependencies
# linux-headers is required to compile ext-sockets on musl/Alpine — its source
# includes <linux/sock_diag.h>, a kernel UAPI header not present in the base image.
RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    zip \
    unzip \
    git \
    curl \
    shadow \
    oniguruma-dev \
    linux-headers

# PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    zip \
    gd \
    opcache \
    pcntl \
    bcmath \
    mbstring \
    sockets

# Redis + Swoole extensions (both need the PHP build toolchain, removed after)
RUN apk add --no-cache $PHPIZE_DEPS \
 && pecl install redis swoole \
 && docker-php-ext-enable redis swoole \
 && apk del $PHPIZE_DEPS

# Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# PHP config — same as the php-fpm image
COPY infrastructure/docker/php-octane/php.ini /usr/local/etc/php/conf.d/custom.ini

# Create non-root user
RUN addgroup -g 1000 www && adduser -u 1000 -G www -s /bin/sh -D www

WORKDIR /var/www

# Install dependencies before copying code (layer caching)
COPY --chown=www:www services/${SERVICE_DIR}/composer.json services/${SERVICE_DIR}/composer.lock* ./
RUN composer install --no-scripts --no-autoloader --no-interaction 2>/dev/null || true

COPY --chown=www:www services/${SERVICE_DIR}/ .

RUN composer dump-autoload --optimize 2>/dev/null || true

USER www

EXPOSE 8000
CMD ["php", "artisan", "octane:start", "--server=swoole", "--host=0.0.0.0", "--port=8000"]
