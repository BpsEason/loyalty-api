FROM php:8.4-fpm

# 1. 補全終端機與快捷鍵相關環境變數
ENV TERM=xterm-256color \
    SHELL=/bin/bash \
    COMPOSER_ALLOW_SUPERUSER=1

# 2. 安裝系統依賴、開發工具與網路檢測工具 (netcat-openbsd)
RUN apt-get update && apt-get install -y --no-install-recommends \
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
    netcat-openbsd \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
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

# 3. 設定 GD 擴展選項並安裝 PHP 擴展
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    mbstring \
    zip \
    bcmath \
    gd \
    exif \
    pcntl \
    intl

# 4. 啟用 OPcache 與安裝 Redis 擴展
RUN pecl install redis \
    && docker-php-ext-enable redis opcache

# 5. 設定基礎 OPcache 配置
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=256'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'opcache.max_accelerated_files=20000'; \
    echo 'opcache.validate_timestamps=1'; \
    echo 'opcache.revalidate_freq=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# 6. 安裝 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# 7. 複製 Composer 宣告檔並預先安裝依賴
COPY composer.json composer.lock ./
RUN composer install \
    --no-scripts \
    --no-autoloader \
    --no-interaction

# 8. 複製專案原始碼
COPY . .

# 9. 建立必要目錄與設定權限
RUN mkdir -p \
    /var/www/storage/framework/cache \
    /var/www/storage/framework/sessions \
    /var/www/storage/framework/views \
    /var/www/storage/logs \
    /var/www/bootstrap/cache \
    && chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache \
    && chmod -R 775 /var/www/storage /var/www/bootstrap/cache

# 10. 產生 Class map autoload
RUN composer dump-autoload --optimize --no-scripts

# 11. 複製 ENTRYPOINT 腳本
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["php-fpm"]