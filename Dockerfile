FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev unzip \
    && docker-php-ext-install bcmath pdo_pgsql pdo_mysql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && printf '%s\n' 'ServerName localhost' > /etc/apache2/conf-available/chambre-rose-server-name.conf \
    && a2enconf chambre-rose-server-name \
    && printf '%s\n' \
      'upload_max_filesize=26M' \
      'post_max_size=30M' \
      'memory_limit=192M' \
      'expose_php=Off' \
      > /usr/local/etc/php/conf.d/chambre-rose.ini

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
COPY . .

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
