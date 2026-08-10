FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql

# Content images are capped at 3 MB by the app, but PHP ships with
# upload_max_filesize=2M, which would reject them before the app ever runs.
# post_max_size must stay above it to leave room for the other form fields.
#
# display_errors is on by default in this image, so any warning is printed
# ahead of the response body - that breaks JSON parsing on every endpoint and
# leaks paths. Log them instead; Apache collects stderr.
RUN { \
      echo "upload_max_filesize = 8M"; \
      echo "post_max_size = 12M"; \
      echo "display_errors = Off"; \
      echo "log_errors = On"; \
      echo "error_log = /dev/stderr"; \
    } > /usr/local/etc/php/conf.d/app.ini

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80