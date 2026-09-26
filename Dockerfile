FROM php:8.4-fpm

# 安裝系統依賴與開發工具
RUN apt-get update && apt-get install -y \
    git \
    curl \
    wget \
    vim \
    nano \
    less \
    grep \
    findutils \
    tree \
    procps \
    iproute2 \
    iputils-ping \
    net-tools \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libicu-dev \
    zip \
    unzip \
    tar \
    gzip \
    bash \
    && rm -rf /var/lib/apt/lists/*

# 安裝 PHP 擴展
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    xml \
    zip \
    bcmath \
    gd \
    exif \
    pcntl \
    intl

# 啟用 OPcache
RUN docker-php-ext-enable opcache

# 安裝 Redis 擴展（PhpRedis）
RUN pecl install redis \
    && docker-php-ext-enable redis

# 設定 OPcache
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.enable_cli=1'; \
    echo 'opcache.memory_consumption=256'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'opcache.max_accelerated_files=20000'; \
    echo 'opcache.validate_timestamps=1'; \
    echo 'opcache.revalidate_freq=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# 安裝 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 設定工作目錄
WORKDIR /var/www

# 複製 Composer 檔案，利用 Docker layer cache
COPY composer.json composer.lock ./

# Image build 階段安裝 Composer 套件
# runtime 的 vendor 會由 Docker named volume 管理
RUN composer install \
    --optimize-autoloader \
    --no-scripts

# 複製專案原始碼
COPY . .

# Laravel 套件發現
RUN php artisan package:discover --ansi

# 建立 Laravel 必要目錄
RUN mkdir -p \
    /var/www/storage \
    /var/www/bootstrap/cache

# 設定 Laravel 目錄權限
RUN chown -R www-data:www-data \
    /var/www/storage \
    /var/www/bootstrap/cache \
    && chmod -R 775 \
    /var/www/storage \
    /var/www/bootstrap/cache

# 建立容器啟動腳本
RUN printf '%s\n' \
    '#!/bin/sh' \
    'set -e' \
    '' \
    '# Docker vendor volume 為空時，初始化 Composer dependencies' \
    'if [ ! -f /var/www/vendor/autoload.php ]; then' \
    '    composer install --optimize-autoloader' \
    'fi' \
    '' \
    '# Bind Mount 可能覆蓋 Image 原本的權限，因此啟動時重新設定 Laravel 目錄權限' \
    'chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache' \
    'chmod -R 775 /var/www/storage /var/www/bootstrap/cache' \
    '' \
    '# 啟動 PHP-FPM' \
    'exec "$@"' \
    > /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

# PHP-FPM
EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]

CMD ["php-fpm"]