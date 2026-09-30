#!/bin/sh
set -e

# 當外部 Volume 掛載導致 vendor 遺失時的補救措施
if [ ! -f /var/www/vendor/autoload.php ]; then
    composer install --no-scripts
fi

# 直接接管 process，不對 storage 進行遞迴權限掃描
exec "$@"