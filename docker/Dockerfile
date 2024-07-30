FROM php:8.3-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libicu-dev \
    libzip-dev \
    nano \
    libjpeg62-turbo-dev \
    xdg-utils \
    supervisor

# Configure and install the GD extension with JPEG support.
RUN docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install gd

# Install PHP extensions: PDO MySQL, Mbstring, Exif, PCNTL, BCMath, Zip.
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath zip \
    && docker-php-ext-configure intl \
    && docker-php-ext-install intl

# Install Redis extension via PECL (PHP Extension Community Library) and enable it.
RUN pecl install redis && docker-php-ext-enable redis

# Clear the package cache to reduce the image size.
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Copy the Composer binary from the official Composer image to the current image.
COPY --from=composer:2.5.8 /usr/bin/composer /usr/bin/composer

# Modify the www-data user and group to have a UID and GID of 1000 to match common host user IDs.
RUN usermod -u 1000 www-data && groupmod -g 1000 www-data

# Set the working directory to /var/www, where the application code will reside.
WORKDIR /var/www
