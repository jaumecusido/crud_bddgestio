FROM php:8.2-apache

# Habilitar extensió PDO_PGSQL per PostgreSQL
RUN docker-php-ext-install pdo pdo_pgsql

# Copiar el codi al directori web d'Apache
COPY . /var/www/html/

# Donar permisos bàsics
RUN chown -R www-data:www-data /var/www/html

# Exposar el port 80
EXPOSE 80
