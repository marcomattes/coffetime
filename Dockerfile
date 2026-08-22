# syntax=docker/dockerfile:1

# --- Stage 1: resolve PHP dependencies ---------------------------------
# Only composer.json/composer.lock are needed here: the app uses its own
# spl_autoload_register (see src/Bootstrap.php), not a Composer PSR-4 map,
# so vendor/ can be built before the rest of the source is copied in.
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

# --- Stage 2: runtime image ---------------------------------------------
FROM php:8.2-apache AS app

# pdo_sqlite is not compiled into the default php:apache image; build it
# against the system SQLite library.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

# Serve public/ as the document root (front controller: public/index.php)
# and allow the app's own .htaccess to apply its rewrite rules.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN { \
        echo '<VirtualHost *:80>'; \
        echo '    DocumentRoot ${APACHE_DOCUMENT_ROOT}'; \
        echo ''; \
        echo '    <Directory ${APACHE_DOCUMENT_ROOT}>'; \
        echo '        AllowOverride All'; \
        echo '        Require all granted'; \
        echo '    </Directory>'; \
        echo ''; \
        echo '    ErrorLog ${APACHE_LOG_DIR}/error.log'; \
        echo '    CustomLog ${APACHE_LOG_DIR}/access.log combined'; \
        echo '</VirtualHost>'; \
    } > /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

# Same layout as scripts/build-release.sh assembles for manual deployments.
COPY --from=vendor /app/vendor ./vendor
COPY public ./public
COPY src ./src
COPY tools ./tools
COPY composer.json composer.lock config.example.php .htaccess ./

# Default dbPath (see src/Config.php) is "<app root>/data/coffee.sqlite",
# which resolves to /var/www/html/data here, so no config.php is needed for
# the database to persist correctly.
RUN mkdir -p /var/www/html/data \
    && chown -R www-data:www-data /var/www/html/data

VOLUME /var/www/html/data
