FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts --ignore-platform-reqs

FROM php:8.3-apache
# pdo_sqlite ships with the official image; intl and gd are added here.
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions intl gd \
 && a2enmod rewrite headers \
 && sed -ri 's!/var/www/html!/var/www/app/public!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
 && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && echo 'date.timezone = Europe/Istanbul' > "$PHP_INI_DIR/conf.d/timezone.ini" \
 && echo 'expose_php = Off' > "$PHP_INI_DIR/conf.d/security.ini"

WORKDIR /var/www/app
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN mkdir -p storage && chown -R www-data:www-data storage \
 && chmod +x deploy/docker-entrypoint.sh

ENV DB_PATH=storage/database.sqlite
VOLUME ["/var/www/app/storage"]
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=3s CMD php -r 'exit(@file_get_contents("http://127.0.0.1/health") ? 0 : 1);'
ENTRYPOINT ["deploy/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
