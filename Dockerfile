FROM lavoweb/php-7.3:composer

WORKDIR /build-repo

COPY composer.json ./composer.json
COPY composer.lock ./composer.lock
RUN composer install

COPY /app ./app
COPY /index.php ./index.php
COPY /local.config.json ./local.config.json

CMD ["php", "index.php", "--help"]
