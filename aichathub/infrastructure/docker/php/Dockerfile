FROM php:8.3-fpm-alpine

# System dependencies
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
    oniguruma-dev

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
    mbstring

# Redis extension
RUN apk add --no-cache $PHPIZE_DEPS \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apk del $PHPIZE_DEPS

# Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# PHP config
COPY php.ini /usr/local/etc/php/conf.d/custom.ini

# Create non-root user
RUN addgroup -g 1000 www && adduser -u 1000 -G www -s /bin/sh -D www

WORKDIR /var/www

# Install dependencies before copying code (layer caching)
COPY --chown=www:www composer.json composer.lock* ./
RUN composer install --no-scripts --no-autoloader --no-interaction 2>/dev/null || true

COPY --chown=www:www . .

RUN composer dump-autoload --optimize 2>/dev/null || true

USER www

EXPOSE 9000
CMD ["php-fpm"]
