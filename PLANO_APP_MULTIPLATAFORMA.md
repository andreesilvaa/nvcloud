# Plano — NVCloud: de site a app Windows, Android e iOS (com Rider)

Diagnóstico atual: PHP puro (sem framework), renderização no servidor, sessões PHP, MySQL, OCR/PDF via Poppler+Tesseract (binários atualmente configurados para caminhos do Windows), sem build JS/CSS. Hoje o site só corre em `localhost` via Laragon.

Abordagem escolhida: wrapper nativo com **.NET MAUI** — uma solução no Rider que gera Windows, Android e iOS, cada um a mostrar o site atual dentro de uma WebView. Mínimas alterações ao PHP.

---

## Fase 1 — Pôr o site acessível fora do localhost (obrigatório)

- [ ] Escolher hosting (VPS ou hosting partilhado) com PHP 8+, MySQL e acesso SSH/terminal
- [ ] Registar/configurar domínio (ex.: `app.nvcloud.pt`)
- [ ] Ativar HTTPS (Let's Encrypt/Certbot, ou automático se o hosting já incluir)
- [ ] Migrar a base de dados MySQL local para o servidor
- [ ] Instalar no servidor: `poppler-utils` e `tesseract-ocr` (`apt install poppler-utils tesseract-ocr -y`) — o `bootstrap.php` já trata o caminho automaticamente em Linux
- [ ] Atualizar `config.php` com as credenciais de produção
- [ ] Testar `login.php` e `app.php` num browser normal, fora da rede local, antes de avançar para a fase 2

## Fase 2 — Ajustar o site para "modo app"

- [ ] Rever CSS de `app.php`, `login.php`, `envios.php`, `workorder.php` em ecrã pequeno (não há framework CSS, por isso testar manualmente em mobile)
- [ ] Garantir que todos os recursos (CSS/JS/imagens) carregam por HTTPS, sem mixed content
- [ ] Confirmar que os cookies de sessão funcionam dentro de uma WebView (atributos `Secure`/`SameSite`)
- [ ] Opcional: adicionar manifest PWA (ícone + theme-color) — útil mesmo sem lojas de apps

## Fase 3 — Preparar o ambiente no Rider

- [ ] Instalar .NET SDK 8 ou superior
- [ ] `dotnet workload install maui`
- [ ] No Rider, confirmar suporte .NET MAUI ativo (Settings → Languages & Frameworks)
- [ ] Instalar Android SDK + um emulador (via Android Studio ou só as command-line tools) e apontar o Rider para esse SDK
- [ ] Para iOS: garantir acesso a um Mac com Xcode (local ou remoto via "pair to Mac" do Rider, ou serviço de build na cloud como Codemagic/MacinCloud) — compilar e assinar iOS exige sempre um Mac

## Fase 4 — Criar o projeto MAUI

- [ ] `File > New Solution > .NET MAUI App` (ex.: `NVCloudApp`)
- [ ] `MainPage` com um `WebView` a apontar para `https://app.nvcloud.pt`
- [ ] Página de fallback para quando não há ligação à internet
- [ ] Ícone e splash screen (reaproveitar `newvision-logo.png` / `stockvisionAI.png`)
- [ ] Confirmar permissões: Android já inclui `INTERNET` por padrão; iOS só precisa de configuração extra no `Info.plist` se usares HTTP (evitar — usar HTTPS)

## Fase 5 — Testar em cada plataforma

- [ ] Windows: correr diretamente no Rider (Debug → Windows Machine)
- [ ] Android: testar em emulador e depois em telemóvel físico (USB debugging)
- [ ] iOS: testar em simulador (no Mac) e depois em iPhone físico (Apple ID de desenvolvimento grátis permite testar por 7 dias; conta paga remove esse limite)

## Fase 6 — Distribuição

- [ ] **Windows**: gerar pacote MSIX/instalador `.exe`; distribuir por download direto ou via Microsoft Store
- [ ] **Android**: gerar APK/AAB assinado; distribuir por download direto (sideload) ou Google Play (conta única de 25 USD)
- [ ] **iOS**: gerar IPA assinado; só é instalável fora da App Store via TestFlight, e isso já exige conta Apple Developer (99 USD/ano) — a mesma conta serve para publicar na App Store

## Fase 7 — Funcionalidades nativas (opcional, mais tarde)

Notificações push, câmara para digitalizar documentos, modo offline, etc. exigem reescrever partes da interface (Blazor Hybrid dentro do mesmo MAUI) e expor o backend PHP como API JSON em vez de páginas HTML completas. Não é necessário para o objetivo atual ("igual ao site, mas como app").

---

### Custos a prever
- Hosting/domínio/HTTPS: variável (pode ser baixo custo)
- Google Play: 25 USD (pagamento único)
- Apple Developer: 99 USD/ano (obrigatório mesmo só para TestFlight)
- Mac (se não tiveres um): comprar, pedir emprestado, ou serviço de build na cloud
