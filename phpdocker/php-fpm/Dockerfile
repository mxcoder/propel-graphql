FROM phpdockerio/php71-fpm:latest

# Install selected extensions and other stuff
RUN apt-get update \
    && apt-get -y --no-install-recommends install  php-memcached php7.1-mysql php7.1-pgsql php-redis php7.1-sqlite3 php-yaml php-xdebug \
    && apt-get clean; rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*


WORKDIR "/application"