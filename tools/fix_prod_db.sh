#!/bin/bash
set -euo pipefail

SERVER="root@137.184.97.191"
KEY="$HOME/.ssh/id_ed25519"
REMOTE="/var/www/nvcloud"
PASS="NVAdmin12345"

ssh -i "$KEY" -o StrictHostKeyChecking=no "$SERVER" "php -r '
\$pass = getenv(\"DB_TEST_PASS\");
\$users = [\"nvcloud_app\", \"stockvision\", \"root\"];
foreach (\$users as \$user) {
  try {
    \$pdo = new PDO(\"mysql:host=localhost;dbname=stocks_db;charset=utf8mb4\", \$user, \$pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo \"OK user=\$user\n\";
    \$row = \$pdo->query(\"SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema=\\\"stocks_db\\\"\")->fetch(PDO::FETCH_ASSOC);
    echo \"tables=\" . \$row[\"c\"] . \"\n\";
    exit(0);
  } catch (Throwable \$e) {
    echo \"FAIL user=\$user: \" . \$e->getMessage() . \"\n\";
  }
}
exit(1);
'" DB_TEST_PASS="$PASS"
