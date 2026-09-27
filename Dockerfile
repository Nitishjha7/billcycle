FROM node:20-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm install

COPY resources ./resources
COPY vite.config.js ./
COPY resources/views ./resources/views
RUN npm run build


FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
        nginx \
        supervisor \
        gettext-base \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .
COPY --from=assets /app/public/build ./public/build

RUN composer install --no-interaction --prefer-dist --no-scripts \
    && php artisan config:clear

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Used only when this image runs as the "web" role (Railway's web service,
# or docker-compose's nginx+app split locally). worker/scheduler override
# CMD with their own artisan command and never touch nginx or supervisor.
COPY docker/nginx/web.conf.template /etc/nginx/sites-enabled/web.conf.template
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/nginx-start.sh /usr/local/bin/nginx-start.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/nginx-start.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
