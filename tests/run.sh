#!/bin/sh
# Starts from the repository root. Creates/reuses ONLY admin_product_test; never drops a DB.
set -eu
cd "$(dirname "$0")/.."
docker compose exec -T mysql sh -c '
    case "$MYSQL_USER" in ""|*[!a-zA-Z0-9_]*) echo "Unsupported test database username" >&2; exit 2;; esac
    export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
    mysql -uroot -e "CREATE DATABASE IF NOT EXISTS admin_product_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON admin_product_test.* TO '\''$MYSQL_USER'\''@'\''%'\'';"
'
TABLE_COUNT=$(docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='\''admin_product_test'\''"')
if [ "$TABLE_COUNT" = "0" ]; then
    python3 -c 'from pathlib import Path; s=Path("database/init.sql").read_text(); print("USE admin_product_test;"); print("\n".join(s.splitlines()[2:]))' |
        docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot admin_product_test'
fi
docker compose cp tests/integration.php php-app:/tmp/admin-integration.php
docker compose exec -T -e DB_DATABASE=admin_product_test -e TEST_APP_DIR=/var/www/html php-app php /tmp/admin-integration.php
docker compose exec -T php-app sh -c 'find /var/www/html -name "*.php" -exec php -l {} \;'
