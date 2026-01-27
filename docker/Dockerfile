FROM php:8.5-fpm

ARG \
  DEBIAN_FRONTEND=noninteractive

# Add in code
ADD ./ /app/

RUN \
  # Install prerequisites
  apt-get -qq update \
  && apt-get -y upgrade \
  && \
  apt-get install -y \
    zlib1g-dev \
    openssl \
    libpng-dev \
    sqlite3 \
    nginx \
    libsqlite3-dev \
    pkg-config \
    libonig-dev \
    supervisor \
  && \
  # Install gd
  docker-php-ext-configure gd \
  && docker-php-ext-install gd \
  && docker-php-ext-enable gd \
  && docker-php-ext-configure bcmath \
  && docker-php-ext-install bcmath \
  && docker-php-ext-enable bcmath \
  # Install pgsql
  && docker-php-ext-configure pdo_sqlite \
  && docker-php-ext-install pdo_sqlite \
  && docker-php-ext-enable pdo_sqlite \
  # Install mbstring
  && docker-php-ext-configure mbstring \
  && docker-php-ext-install mbstring \
  && docker-php-ext-enable mbstring \
  # Tweak php-fpm config
  && sed -i \
    -e "s/;listen.mode = 0660/listen.mode = 0666/g" \
    -e "s/;listen = 127.0.0.1:9000/listen = \/var\/run\/php-fpm.sock/g" \
    /usr/local/etc/php-fpm.d/www.conf \
  # Install vendor packages
  && cd /app \
  && php composer.phar install \
  # Set permissions
  && chown -R www-data:www-data /app \
  # Cleanup
  && apt-get autoremove -y \
  && apt-get clean \
  && rm -rf \
    /tmp/* \
    /var/lib/apt/lists/* \
    /var/tmp/


RUN sed -e 's/max_execution_time = 30/max_execution_time = 600/' -i  /usr/local/etc/php/php.ini-production && echo 'memory_limit = 4096M' >> /usr/local/etc/php/conf.d/docker-php-ram-limit.ini
RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

RUN chmod -R 700 /app/storage

# Add our nginx config
ADD ./docker/conf/nginx.conf /etc/nginx/nginx.conf
ADD ./docker/conf/nginx-site.conf /etc/nginx/sites-enabled/default
ADD ./docker/conf/supervisord.conf /etc/supervisord.conf

EXPOSE 80

WORKDIR /app
CMD ["docker/entrypoint.sh"]
