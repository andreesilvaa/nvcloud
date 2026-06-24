#!/bin/bash
# watch.sh — Deploy automático ao guardar ficheiros PHP/JS/CSS
# Instalar (só uma vez): sudo apt install inotify-tools
# Uso: ./watch.sh

SERVER="root@137.184.97.191"
REMOTE="/var/www/nvcloud"
LOCAL="/home/josee/projects/nvcloud"
KEY="$HOME/.ssh/id_ed25519"

if ! command -v inotifywait &> /dev/null; then
    echo "A instalar inotify-tools..."
    sudo apt install -y inotify-tools
fi

echo "👀 A monitorizar alterações em $LOCAL"
echo "   Ctrl+C para parar."

inotifywait -m -r -e close_write,moved_to \
  --include='.*\.(php|js|css|html)$' \
  "$LOCAL" |
while read -r dir event file; do
    FILEPATH="$dir$file"
    RELPATH="${FILEPATH#$LOCAL/}"
    if [[ "$RELPATH" == .git/* ]] || [[ "$RELPATH" == docker/* ]] || [[ "$RELPATH" == docs/* ]]; then
        continue
    fi
    echo "📝 $RELPATH"
    scp -i "$KEY" -o StrictHostKeyChecking=no \
        "$FILEPATH" "$SERVER:$REMOTE/$RELPATH" \
        && echo "   ✅ Enviado!" \
        || echo "   ❌ Erro"
done
