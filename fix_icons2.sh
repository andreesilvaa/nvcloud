#!/bin/bash
cd /home/josee/projects/nvcloud

# Ficheiros de logo/favicon na raiz do projeto
for f in favicon.png favicon-new.png stockvisionAI.png assets/newvision-logo.png assets/favicon.png assets/favicon-new.png assets/logo-stockvision-app.png assets/stock.vision.png; do
  if [ -f "$f" ]; then
    convert "$f" -fuzz 5% -transparent white "$f" && echo "OK: $f" || echo "ERRO: $f"
  else
    echo "NAO EXISTE: $f"
  fi
done

echo "Concluido."
