#!/bin/sh
set -e

# 1. 確保 terminal 變數存在，避免快捷鍵與方向鍵失效
export TERM=${TERM:-xterm-256color}

# 2. 當外部 Volume 掛載導致 vendor 遺失時的補救措施
if [ ! -f /var/www/vendor/autoload.php ]; then
    echo "vendor/autoload.php not found. Running composer install..."
    composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts
fi

# 3. 確保 Laravel 必要目錄存在
REQUIRED_DIRS="
/var/www/storage/logs
/var/www/storage/framework/cache/data
/var/www/storage/framework/sessions
/var/www/storage/framework/views
/var/www/bootstrap/cache
"

for dir in $REQUIRED_DIRS; do
    if [ ! -d "$dir" ]; then
        mkdir -p "$dir"
    fi
done

# 4. 安全清除設定快取（避免無 .env 時造成 set -e 觸發崩潰）
if [ -f /var/www/.env ]; then
    php artisan config:clear || true
fi

# 5. 直接接管 process
exec "$@"