#!/usr/bin/env bash
KEY="$HOME/.ssh/id_ed25519"; SRV="root@137.184.97.191"
SSH="ssh -i $KEY -o StrictHostKeyChecking=no -o ConnectTimeout=15"
$SSH "$SRV" "bash -s" <<'EOF'
PASS='NVAdmin12345'
for U in nvcloud_app stockvision; do
  if mysql -u$U -p"$PASS" -e 'SELECT 1' stocks_db >/dev/null 2>&1; then echo "SOCKET_OK=$U"; fi
  if mysql -h127.0.0.1 -u$U -p"$PASS" -e 'SELECT 1' stocks_db >/dev/null 2>&1; then echo "TCP_OK=$U"; fi
done
echo "-- detalhe erro socket nvcloud_app --"
mysql -unvcloud_app -p"$PASS" -e 'SELECT 1' stocks_db 2>&1 | head -2
echo "-- detalhe erro socket stockvision --"
mysql -ustockvision -p"$PASS" -e 'SELECT 1' stocks_db 2>&1 | head -2
EOF
rm -f /home/josee/projects/nvcloud/_t.sh
