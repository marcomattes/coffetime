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
# against the system SQLite library. curl is for HEALTHCHECK below.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev curl \
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

# The first-run setup token is written to the error log on generation, and a
# container operator is expected to read it with `docker compose logs`. That
# only works if PHP's log goes to stderr rather than into the container's
# filesystem, which is not the default for the apache SAPI.
RUN printf 'error_log = /dev/stderr\nlog_errors = On\n' \
    > /usr/local/etc/php/conf.d/zz-log-to-stderr.ini

WORKDIR /var/www/html

# Same layout as scripts/build-release.sh assembles for manual deployments.
COPY --from=vendor /app/vendor ./vendor
COPY public ./public
COPY src ./src
COPY tools ./tools
COPY composer.json composer.lock config.example.php .htaccess ./

# Which commit this image was built from. Version::current() reads
# src/build.json and falls back to .git, and .dockerignore excludes .git --
# so without these the footer of a container deployment would only ever say
# "dev". BUILD_COMMIT must be a 7-40 character hex sha: Version::shorten()
# rejects anything else, so the semver release tag goes on the *image* rather
# than in here.
#
# Declared this late on purpose: a build arg invalidates the cache for every
# layer after it is declared, and the commit changes on every build. Up top it
# would re-run apt-get and re-copy vendor/ each time.
ARG BUILD_COMMIT=""
ARG BUILD_AT="0"

# Mirrors what build-release.sh writes into the release bundle, so /api/version
# and the footer report the same thing however the app was deployed.
RUN if [ -n "${BUILD_COMMIT}" ]; then \
        printf '{"version":"%s","builtAt":%s}\n' "${BUILD_COMMIT}" "${BUILD_AT}" \
            > /var/www/html/src/build.json; \
    fi

# Default dbPath (see src/Config.php) is "<app root>/data/coffee.sqlite",
# which resolves to /var/www/html/data here, so no config.php is needed for
# the database to persist correctly. The directory also holds the first-run
# setup token. It sits outside the document root (public/), so the .htaccess
# Db::pdo() writes is belt-and-braces here rather than the actual guard.
RUN mkdir -p /var/www/html/data \
    && chown -R www-data:www-data /var/www/html/data

VOLUME /var/www/html/data

EXPOSE 80

# /api/version needs no session and touches no database, so it stays honest
# about the web tier being up without creating a schema as a side effect.
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -fsS http://127.0.0.1/api/version || exit 1
