FROM php:8.1-apache

# Cài đặt các extension PHP cần thiết
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Cấu hình PHP cho upload file lớn
RUN echo "upload_max_filesize = 100M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 100M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_input_time = 300" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/uploads.ini

# Set environment variable để detect Docker
ENV DOCKER_CONTAINER=true

# Copy source code vào container
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html
RUN chmod -R 755 /var/www/html

# Tạo thư mục uploads và set quyền
RUN mkdir -p /var/www/html/uploads/health_videos \
    && chown -R www-data:www-data /var/www/html/uploads \
    && chmod -R 777 /var/www/html/uploads

# Expose port 80
EXPOSE 80