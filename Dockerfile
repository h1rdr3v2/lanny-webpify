FROM php:8.2-apache

# Install required dependencies
RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    ffmpeg \
    zip \
    unzip

# Install the Imagick extension
RUN apt-get update; \
    apt-get install -y libmagickwand-dev; \
    pecl install imagick; \
    docker-php-ext-enable imagick;

RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set the working directory
WORKDIR /var/www/html

# Copy the application files to the working directory
COPY . /var/www/html

# Install dependencies
RUN composer install --no-scripts --no-autoloader

# Run composer dump-autoload
RUN composer dump-autoload --optimize

# Enable Apache rewrite module
RUN a2enmod rewrite

# Set the appropriate permissions
RUN chown -R www-data:www-data /var/www/html

# Expose the port on which the application will run
EXPOSE 80

# Start the Apache web server
CMD ["apache2-foreground"]