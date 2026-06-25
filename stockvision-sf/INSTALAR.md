# StockVision — Salesforce Extension
## Instruções de instalação

### Passo 1 — Adicionar o endpoint ao app.php
Copia o conteúdo de ENDPOINT_app.php.txt e cola no app.php
ANTES da linha:  $vista = $_GET['lista'] ?? '0';

### Passo 2 — Instalar a extensão no Chrome/Edge
1. Abre o Chrome e vai a:  chrome://extensions
2. Ativa o "Modo de programador" (canto superior direito)
3. Clica "Carregar sem compressão" (Load unpacked)
4. Seleciona a pasta  stockvision-sf/
5. A extensão aparece na barra do browser

### Passo 3 — Configurar o URL e o Token do StockVision
1. Vai ao Salesforce e abre qualquer Work Order
2. Clica no ícone da extensão
3. No campo "URL do StockVision" confirma ou corrige o URL
   Exemplo:  http://localhost/nvcloud/app.php  (ou https://stockvision.pt/app.php)
4. No campo "Token da extensão" cola o valor de EXTENSION_TOKEN definido no config.php
5. O URL e o token ficam guardados automaticamente (a extensão envia o token
   no cabeçalho X-NV-Token, via POST — sem depender da sessão do site)

### Passo 4 — Usar
1. Abre uma Work Order no Salesforce
2. Clica no ícone StockVision na barra do browser
3. Confirma os dados apresentados
4. Clica "Copiar para StockVision"
5. O PAT é criado — clica "Abrir no StockVision" para o ver

### Notas
- Funciona em Chrome e Edge (ambos suportam extensões Manifest V3)
- Não requer nenhuma instalação ou configuração no Salesforce
- O URL do StockVision é guardado localmente no browser
- Se o StockVision já tiver um PAT com o mesmo nº WO, abre o existente
