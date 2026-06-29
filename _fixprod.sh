#!/usr/bin/env bash
KEY="$HOME/.ssh/id_ed25519"
SRV="root@137.184.97.191"
SSH="ssh -i $KEY -o StrictHostKeyChecking=no -o ConnectTimeout=15"
PASS='NVAdmin12345'

echo "== testar credenciais (TCP 127.0.0.1) =="
WORKS=$($SSH "$SRV" "for U in nvcloud_app stockvision; do if mysql -h127.0.0.1 -u\$U -p'$PASS' -e 'SELECT 1' stocks_db >/dev/null 2>&1; then echo \$U; break; fi; done")
echo "WORKS_USER=[$WORKS]"
if [ -z "$WORKS" ]; then echo "NENHUM_UTILIZADOR_LIGA — abortar"; exit 1; fi

echo "== backup + escrever config.php (preservando EXTENSION_TOKEN) =="
$SSH "$SRV" "bash -s" <<EOF
set -e
CFG=/var/www/nvcloud/config.php
cp -a "\$CFG" "\$CFG.bak.\$(date +%Y%m%d%H%M%S)"
TOKEN=\$(grep -oP "EXTENSION_TOKEN'\s*,\s*'\K[^']*" "\$CFG" 2>/dev/null || true)
[ -z "\$TOKEN" ] && TOKEN='troca-isto-por-uma-string-aleatoria-longa'
cat > "\$CFG" <<PHP
<?php
// CONFIG DE PRODUCAO — NAO vai para git (gitignore) nem para deploy (rsync exclui)
define('DB_HOST',    'localhost');
define('DB_NAME',    'stocks_db');
define('DB_USER',    '$WORKS');
define('DB_PASS',    '$PASS');
define('DB_CHARSET', 'utf8mb4');
define('EXTENSION_TOKEN', '\$TOKEN');
define('PDFTOTEXT_BIN', '/usr/bin/pdftotext');
define('PDFTOPPM_BIN',  '/usr/bin/pdftoppm');
define('TESSERACT_BIN', '/usr/bin/tesseract');
define('TESSERACT_LANG','por+eng');
PHP
chown stockvision:stockvision "\$CFG" 2>/dev/null || true
chmod 640 "\$CFG"
php -l "\$CFG"
EOF

echo "== verificar site =="
curl -s -o /dev/null -w 'login_HTTP=%{http_code}\n' --max-time 15 https://stockvision.pt/login.php
curl -s --max-time 15 https://stockvision.pt/login.php | grep -iq 'Erro de liga\|indispon' && echo 'AINDA COM ERRO DE BD' || echo 'BD OK (sem erro)'
rm -f /home/josee/projects/nvcloud/_fixprod.sh
