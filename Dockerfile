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
# postgresql-client: needed by entrypoint.sh to load SQL schema files via psql
# libpq-dev: needed to compile pdo_pgsql + pgsql PHP extensions
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
    postgresql-client \
    && rm -rf /var/lib/apt/lists/*

# -----------------------------------------------------------------------------
# PHP extensions
# -----------------------------------------------------------------------------
# Core extensions (bundled with PHP)
# Fix GD with freetype+jpeg for PHP 8.4+
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    zip \
    opcache

# GD with freetype+jpeg (needs separate configure for PHP 8.4+)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd

# Redis extension (for phpredis session handler)
RUN pecl install redis && docker-php-ext-enable redis

# MySQL extension (for legacy archive connection via PDO)
RUN docker-php-ext-install pdo_mysql mysqli

# -----------------------------------------------------------------------------
# PHP Configuration
# -----------------------------------------------------------------------------
COPY docker/php/php.ini /usr/local/etc/php/conf.d/rcerp-custom.ini

# -----------------------------------------------------------------------------
# Node.js + npm (for building Vite frontend assets)
# -----------------------------------------------------------------------------
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

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
