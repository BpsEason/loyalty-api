#!/bin/sh
set -e

# 當外部 Volume 掛載導致 vendor 遺失時的補救措施
if [ ! -f /var/www/vendor/autoload.php ]; then
    composer install --no-scripts
fi

# 清除 Laravel 舊有快取，避免 Docker Volume 保留舊 cache 導致警告
php artisan optimize:clear

# 確保 Laravel 必要目錄的權限正確（僅處理必要目錄，不遞迴整個 storage）
REQUIRED_DIRS="/var/www/storage
/var/www/storage/logs
/var/www/storage/framework
/var/www/storage/framework/cache
/var/www/storage/framework/sessions
/var/www/storage/framework/views
/var/www/bootstrap/cache"

for dir in $REQUIRED_DIRS; do
    # 確保目錄存在
    mkdir -p "$dir"
    # 設定擁有者為 www-data
    chown www-data:www-data "$dir"
    # 設定目錄可寫入權限
    chmod 755 "$dir"
done

# 確保日誌檔案本身的權限正確（如果已經存在）
if [ -f /var/www/storage/logs/laravel.log ]; then
    chown www-data:www-data /var/www/storage/logs/laravel.log
    chmod 664 /var/www/storage/logs/laravel.log
fi

# 直接接管 process
exec "$@"