FROM php:8.2-apache
RUN apt-get update && apt-get install -y unzip git libzip-dev \
    && docker-php-ext-install pdo pdo_mysql zip sockets
# Thư viện RabbitMQ (php-amqplib) cài ở /opt/ai, không nằm trong ./app vì thư mục app được mount đè lên /var/www/html.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN mkdir -p /opt/ai && cd /opt/ai \
    && composer require php-amqplib/php-amqplib:^3.7 --no-interaction --no-progress
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
RUN a2enmod rewrite
WORKDIR /var/www/html
EXPOSE 80
