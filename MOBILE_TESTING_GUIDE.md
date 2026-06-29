# ?? Guia de Testes - NVCloud Mobile

## Como Testar em Mobile

### 1. Usando Browser DevTools (Recomendado para Testes Rápidos)

**Chrome/Edge:**
- Abrir DevTools: F12 ou Ctrl+Shift+I
- Clicar no ícone de dispositivo móvel (Toggle device toolbar)
- Selecionar dispositivos pré-configurados:
  - iPhone 12: 390×844px
  - iPhone SE: 375×667px
  - Galaxy S21: 360×800px
  - iPad: 768×1024px

**Firefox:**
- F12 ? Responsive Design Mode (Ctrl+Shift+M)
- Testar em 375px, 480px, 768px

### 2. Testando as Funcionalidades

#### Dashboard (Página Principal)
- [ ] Sidebar colapsado em 768px (50px width)
- [ ] Sidebar deslizante em 480px (menu overlay)
- [ ] KPI cards em 2 colunas em 768px
- [ ] KPI cards em 1 coluna em 480px
- [ ] Gráfico visível e responsivo
- [ ] Legenda em 1 coluna em 480px
- [ ] Tabelas scrolláveis horizontalmente

#### Login
- [ ] Formulário centrizado
- [ ] Inputs com touch targets (44px mín)
- [ ] Botão 100% width
- [ ] Sem background grande em mobile

#### Outras Páginas (Inventário, Envios, Análises, etc)
- [ ] Layout responsivo
- [ ] Tabelas scrolláveis
- [ ] Formulários usáveis
- [ ] Modais adaptados

### 3. Testando em Telemóvel Real

#### iOS (iPhone)
1. Abrir Safari
2. Ir para app.php
3. Toque Home ? Adicionar à Tela Inicial
4. Verificar:
   - [ ] Sem URL bar em fullscreen
   - [ ] Ícone correto (manifest.json)
   - [ ] Tema de cores correto
   - [ ] Notch respeitado (safe areas)

#### Android
1. Abrir Chrome
2. Ir para app.php
3. Menu (3 pontos) ? Instalar app
4. Verificar:
   - [ ] App abre em fullscreen
   - [ ] Ícone na home screen
   - [ ] Funcionalidades acessíveis
   - [ ] Sem url bar

### 4. Checklist de Responsividade

**Em 320px (Very Small):**
- [ ] Conteúdo não sai do ecr?(sem horizontal scroll)
- [ ] Texto legível (font-size >= 14px)
- [ ] Botões clicáveis (44x44px mín)
- [ ] Sidebar oculta (overlay)

**Em 480px (Small Phone):**
- [ ] Título da página visível
- [ ] Todos os elementos acessíveis
- [ ] Menu funciona (hamburger)
- [ ] Tabelas scrolláveis

**Em 768px (Tablet):**
- [ ] Sidebar colapsado (50px)
- [ ] Cards em 2 colunas
- [ ] Layout equilibrado
- [ ] Fácil de usar com dedo

**Em 1024px+ (Desktop):**
- [ ] Layout normal (180px sidebar)
- [ ] Sem mudanças visíveis
- [ ] Mouse/trackpad funciona

### 5. Testes de Performance

- [ ] Página carrega em < 3s (mobile 3G)
- [ ] Sem lag ao scroll
- [ ] Menu abre/fecha suave
- [ ] Animações fluidas (60fps)

### 6. Problemas Conhecidos a Evitar

? **Não fazer:**
- Input type=text com font-size < 16px (causa zoom automático em iOS)
- Touch targets < 44x44px
- Overflow horizontal sem scroll container
- Modals fixed sem max-height

? **Fazer:**
- Usar media queries para cada breakpoint
- Testar em device real quando possível
- Verificar scroll behavior em iOS
- Garantir contraste de cores

---

## ?? Debugging Mobile

### Ver logs em telemóvel:
- **iOS**: Safari DevTools (conectar Mac)
- **Android**: Chrome DevTools (USB debugging)

### Ferramentas Online:
- responsivedesignchecker.com
- mobiletest.me
- browserstack.com (pago)

---

## ?? Resultado Esperado

Após essas otimizações, o app deve:
? Funcionar perfeitamente em qualquer dispositivo
? Todas as páginas responsivas (dashboard, inventário, envios, etc)
? Menu mobile acessível
? Tabelas scrolláveis em mobile
? Formulários usáveis
? Design limpo e moderno
? Sem perda de funcionalidades

