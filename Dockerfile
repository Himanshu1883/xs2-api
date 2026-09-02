FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libonig-dev \
        libxml2-dev \
        libzip-dev \
    && docker-php-ext-install \
        bcmath \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_PROCESS_TIMEOUT=600 \
    COMPOSER_DISABLE_XDEBUG_WARN=1

WORKDIR /app

COPY composer.json composer.lock ./
# Retry composer install — Railway builders often hit transient GitHub zipball 504s.
RUN set -eux; \
    composer config -g github-protocols https; \
    composer config -g repos.packagist composer https://repo.packagist.org; \
    attempt=1; \
    max_attempts=5; \
    until composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress; do \
      if [ "$attempt" -ge "$max_attempts" ]; then \
        echo "composer install failed after ${max_attempts} attempts"; \
        exit 1; \
      fi; \
      echo "composer install failed (attempt ${attempt}/${max_attempts}); clearing cache and retrying..."; \
      composer clear-cache || true; \
      attempt=$((attempt + 1)); \
      sleep $((attempt * 10)); \
    done

COPY . .
RUN touch .env \
    && rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php bootstrap/cache/routes-v7.php \
    && composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi \
    && mkdir -p \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chmod +x docker-entrypoint.sh

ENV APP_ENV=production
ENV APP_DEBUG=false
ENV LOG_CHANNEL=stderr
ENV SESSION_DRIVER=file
ENV CACHE_STORE=file
ENV QUEUE_CONNECTION=database
ENV PORT=8080

EXPOSE 8080

CMD ["./docker-entrypoint.sh"]
