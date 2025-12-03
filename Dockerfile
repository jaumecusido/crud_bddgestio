FROM php:8.2-apache

# Paquets necessaris per a PostgreSQL (client + headers)
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && rm -rf /var/lib/apt/lists/*

# Habilitar extensió PDO_PGSQL per PostgreSQL
RUN docker-php-ext-install pdo pdo_pgsql

# Copiar el codi al directori web d'Apache
COPY . /var/www/html/

# Donar permisos bàsics
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

