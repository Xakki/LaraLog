ARG PHP_IMAGE=php:8.3-cli-alpine
FROM ${PHP_IMAGE}

WORKDIR /app

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN apk update && apk --no-cache add linux-headers bash curl curl-dev oniguruma-dev --no-interactive
RUN docker-php-ext-install curl mbstring pdo sockets
RUN docker-php-source delete && rm -rf /var/cache/apk/*

STOPSIGNAL SIGKILL

##CMD tail -f /var/log/*.log -n 5