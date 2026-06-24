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
  --exclude='node_modules' \
  --exclude='.env' \
  --exclude='*.bak*' \
  --exclude='docker/' \
  --exclude='docs/' \
  -e "ssh -i $KEY -o StrictHostKeyChecking=no" \
  "$LOCAL/" "$SERVER:$REMOTE/"

echo "✅ Deploy concluído!"
