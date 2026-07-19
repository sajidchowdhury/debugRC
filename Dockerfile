# =============================================================================
# RC_ERP_v2 — Dockerfile for PHP-FPM Application Container
# =============================================================================
# Based on php:8.4-fpm with all required extensions for Laravel 12 + PostgreSQL
# + Redis + MySQL archive connection.
# =============================================================================

FROM php:8.4-fpm-bookworm

# -----------------------------------------------------------------------------
# System dependencies
# -----------------------------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libpq-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libwebp-dev \
    unzip \
    supervisor \
    cron \
    nginx \
    && rm -rf /var/lib/apt/lists/*

# -----------------------------------------------------------------------------
# PHP extensions
# -----------------------------------------------------------------------------
# Core extensions (bundled with PHP)
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    opcache

# Redis extension (for phpredis session handler)
RUN pecl install redis && docker-php-ext-enable redis

# MySQL extension (for legacy archive connection via PDO)
RUN docker-php-ext-install pdo_mysql mysqli

# -----------------------------------------------------------------------------
# PHP Configuration
# -----------------------------------------------------------------------------
COPY docker/php/php.ini /usr/local/etc/php/conf.d/rcerp-custom.ini

# -----------------------------------------------------------------------------
# Composer
# -----------------------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# -----------------------------------------------------------------------------
# Working directory
# -----------------------------------------------------------------------------
WORKDIR /var/www/laravel

# -----------------------------------------------------------------------------
# Entrypoint
# -----------------------------------------------------------------------------
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
