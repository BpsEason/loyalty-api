#!/bin/sh
set -e

# 強制確保終端機變數正確（解決快捷鍵、方向鍵失效）
export TERM=${TERM:-xterm-256color}
export SHELL=/bin/bash

# 1. vendor 遺失時自動補裝
if [ ! -f /var/www/vendor/autoload.php ]; then
    echo "vendor/autoload.php not found. Running composer install..."
    composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts
fi

# 2. 確保 Laravel 必要目錄存在
REQUIRED_DIRS="
/var/www/storage/logs
/var/www/storage/framework/cache/data
/var/www/storage/framework/sessions
/var/www/framework/views
/var/www/bootstrap/cache
"

for dir in $REQUIRED_DIRS; do
    if [ ! -d "$dir" ]; then
        mkdir -p "$dir"
    fi
done

# 3. 清掉舊的 config cache（避免 .env 改了卻沒生效）
if [ -f /var/www/.env ]; then
    php artisan config:clear || true
fi

# 4. 展示用：啟動時自動重建資料庫並 seed
if [ "$1" = "php-fpm" ] && [ -f /var/www/.env ]; then
    DB_HOST_TARGET=${DB_HOST:-host.docker.internal}
    DB_PORT_TARGET=${DB_PORT:-3306}

    echo "Checking external database connection ($DB_HOST_TARGET:$DB_PORT_TARGET)..."

    MAX_TRIES=15
    COUNT=0
    until nc -z -w 2 "$DB_HOST_TARGET" "$DB_PORT_TARGET" || [ $COUNT -eq $MAX_TRIES ]; do
        echo "Waiting for external database..."
        sleep 2
        COUNT=$((COUNT + 1))
    done

    if [ $COUNT -lt $MAX_TRIES ]; then
        echo "Database is ready. Running migrate:fresh --seed..."
        php artisan migrate:fresh --seed --force || echo "Migration failed!"
    else
        echo "Warning: External database connection timed out. Skipping migration."
    fi
fi

# 5. 啟動原本的指令
exec "$@"