FROM php:8.2-fpm

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

# 安裝 Redis 擴展 (PhpRedis)
RUN pecl install redis && docker-php-ext-enable redis

# 安裝 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 設定工作目錄
WORKDIR /var/www

# 複製專案檔案
COPY . .

# 安裝 PHP 依賴
RUN composer install --optimize-autoloader --no-dev

# 設定權限
RUN chown -R www-data:www-data /var/www \
    && chmod -R 755 /var/www/storage \
    && chmod -R 755 /var/www/bootstrap/cache

# 暴露端口
EXPOSE 9000

# 啟動命令
CMD ["php-fpm"]