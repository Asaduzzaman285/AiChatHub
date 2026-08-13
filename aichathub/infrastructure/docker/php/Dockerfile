FROM php:8.3-fpm-alpine

# Which services/<dir> holds this build's actual application code. The build
# context is the repo root (not this directory) specifically so a single shared
# Dockerfile can reach both the generic files living alongside it (php.ini,
# www.conf) and the real per-service app code — Docker COPY can't reach outside
# the build context, so one context has to cover both. Dev's bind-mount then
# overlays /var/www with the real code at runtime regardless of what got baked
# in at build time; production has no bind-mount, so this is what actually
# supplies the real app there (a gap only production deploy ever exposed —
# without a real SERVICE_DIR, the image ends up with just this directory's own
# generic placeholder composer.json/vendor, not the real app).
ARG SERVICE_DIR

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
COPY infrastructure/docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini

# FPM pool config — factory default (pm.max_children = 5) was far too low, see
# www.conf's own comment.
COPY infrastructure/docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

# Create non-root user
RUN addgroup -g 1000 www && adduser -u 1000 -G www -s /bin/sh -D www

WORKDIR /var/www

# Install dependencies before copying code (layer caching)
COPY --chown=www:www services/${SERVICE_DIR}/composer.json services/${SERVICE_DIR}/composer.lock* ./
RUN composer install --no-scripts --no-autoloader --no-interaction 2>/dev/null || true

COPY --chown=www:www services/${SERVICE_DIR}/ .

RUN composer dump-autoload --optimize 2>/dev/null || true

USER www

EXPOSE 9000
CMD ["php-fpm"]
