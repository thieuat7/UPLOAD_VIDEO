FROM php:8.2-fpm

# Cài system dependencies + FFmpeg + ICU (cho intl)
RUN apt-get update && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    ffmpeg \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libicu-dev \
    libzip-dev \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Cài PHP extensions (Laravel + Filament + FFmpeg + S3)
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    intl \
    zip

# Cài đặt extension Redis cho PHP
RUN pecl install redis && docker-php-ext-enable redis

# Cài Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Tăng giới hạn dung lượng upload file cho PHP
RUN echo "upload_max_filesize = 500M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 500M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 3600" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_input_time = 3600" >> /usr/local/etc/php/conf.d/uploads.ini
# PHP-FPM port
EXPOSE 9000

CMD ["php-fpm"]