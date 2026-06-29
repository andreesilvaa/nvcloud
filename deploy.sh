#!/bin/bash
# deploy.sh — Envia os ficheiros alterados para o servidor
# Uso: ./deploy.sh

SERVER="root@137.184.97.191"
REMOTE="/var/www/nvcloud"
LOCAL="/home/josee/projects/nvcloud"
KEY="$HOME/.ssh/id_ed25519"

echo "🚀 A fazer deploy..."

rsync -avz --progress \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='.idea' \
  --exclude='.vscode' \
  --exclude='.idx' \
  --exclude='.scannerwork' \
  --exclude='.claude' \
  --exclude='.kiro' \
  --exclude='node_modules' \
  --exclude='.env' \
  --exclude='config.php' \
  --exclude='*.bak*' \
  --exclude='*.patch' \
  --exclude='*.bat' \
  --exclude='*.sh' \
  --exclude='*.py' \
  --exclude='*.ps1' \
  --exclude='*.tmp' \
  --exclude='Jenkinsfile' \
  --exclude='qodana.yaml' \
  --exclude='sonar-project.properties' \
  --exclude='composer.lock' \
  --exclude='_old_icons_backup' \
  --exclude='tools/' \
  --exclude='docker/' \
  --exclude='docs/' \
  --exclude='docker-compose.yml' \
  -e "ssh -i $KEY -o StrictHostKeyChecking=no" \
  "$LOCAL/" "$SERVER:$REMOTE/"

echo "✅ Deploy concluído!"
