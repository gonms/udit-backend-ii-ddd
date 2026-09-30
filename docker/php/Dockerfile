FROM php:8.4-fpm

# Arguments
ARG user=laravel
ARG uid=1000

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpq-dev \
    libicu-dev \
    libxslt1-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        xsl \
        soap \
        opcache

RUN apt-get update && apt-get install -y \
    libssl-dev \
    pkg-config

# Install APCu for local cache (optional but recommended)
RUN pecl install apcu-5.1.24 && docker-php-ext-enable apcu

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# Create a system user to run Composer and Artisan commands
RUN useradd -G www-data,root -u $uid -d /home/$user $user
RUN mkdir -p /home/$user/.composer && \
    chown -R $user:$user /home/$user

# Set up working directory
WORKDIR /var/www

# Copy application files
COPY --chown=$user:www-data . /var/www

# Optimize Composer autoloader
RUN composer install --no-dev --optimize-autoloader --no-interaction || true

# Switch to the created user
USER $user

# Expose port 9000 and run php-fpm
EXPOSE 9000
CMD ["php-fpm"]
