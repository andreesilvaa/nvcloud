<?php
// Página de Política de Privacidade — StockVision Extensão Chrome
$titulo = 'Política de Privacidade — Extensão StockVision';
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($titulo) ?></title>
<style>
  body {
    font-family: 'Segoe UI', system-ui, sans-serif;
    max-width: 760px;
    margin: 60px auto;
    padding: 0 24px;
    color: #1c1f24;
    line-height: 1.7;
    font-size: 15px;
  }
  h1 { font-size: 24px; margin-bottom: 6px; color: #1a1d21; }
  h2 { font-size: 16px; margin-top: 32px; margin-bottom: 8px; color: #1a1d21; }
  .meta { color: #6b7280; font-size: 13px; margin-bottom: 40px; }
  p { margin-bottom: 14px; }
  ul { margin: 0 0 14px 20px; }
  li { margin-bottom: 6px; }
  a { color: #c9a14a; }
  hr { border: none; border-top: 1px solid #e2e5ea; margin: 40px 0; }
  .logo { font-weight: 800; letter-spacing: .04em; }
  .logo span { color: #c9a14a; }
</style>
</head>
<body>

<p class="logo">STOCK<span>VISION</span></p>

<h1>Política de Privacidade</h1>
<p class="meta">Extensão Chrome "StockVision — Salesforce" · Última atualização: <?= date('d/m/Y') ?></p>

<h2>1. O que é esta extensão</h2>
<p>
  A extensão <strong>StockVision — Salesforce</strong> é uma ferramenta de produtividade
  que permite copiar Work Orders do Salesforce diretamente para o sistema StockVision,
  eliminando a introdução manual de dados.
</p>

<h2>2. Dados acedidos</h2>
<p>A extensão acede exclusivamente aos seguintes dados quando o utilizador está numa página de Work Order no Salesforce:</p>
<ul>
  <li>Número da Work Order</li>
  <li>Entidade / cliente associado</li>
  <li>Descrição da Work Order</li>
  <li>Datas (receção e limite)</li>
  <li>Prioridade</li>
  <li>Técnico responsável</li>
  <li>Morada e contacto do cliente</li>
</ul>

<h2>3. Como os dados são usados</h2>
<p>
  Os dados lidos da página do Salesforce são enviados <strong>exclusivamente</strong> para o
  servidor StockVision configurado pelo utilizador (URL definida nas definições da extensão).
  <strong>Nenhum dado é enviado para terceiros ou servidores externos.</strong>
</p>

<h2>4. Armazenamento local</h2>
<p>A extensão guarda localmente no browser (via <code>chrome.storage.local</code>) apenas:</p>
<ul>
  <li>O URL do servidor StockVision (configurado pelo utilizador)</li>
  <li>O token de autenticação da extensão (configurado pelo utilizador)</li>
</ul>
<p>Estes dados não saem do dispositivo do utilizador exceto para comunicação com o servidor StockVision configurado.</p>

<h2>5. Permissões utilizadas</h2>
<ul>
  <li><strong>activeTab</strong> — para aceder à aba ativa do Salesforce onde o utilizador está</li>
  <li><strong>scripting</strong> — para ler o conteúdo da página do Salesforce</li>
  <li><strong>storage</strong> — para guardar as preferências do utilizador localmente</li>
</ul>

<h2>6. Partilha de dados</h2>
<p>
  A NewVision / StockVision <strong>não recolhe, não armazena e não partilha</strong> qualquer
  dado pessoal ou de utilização da extensão. A extensão comunica apenas com o servidor
  StockVision da organização do próprio utilizador.
</p>

<h2>7. Contacto</h2>
<p>
  Para questões sobre privacidade, contacte:<br>
  <a href="mailto:geral@stockvision.pt">geral@stockvision.pt</a><br>
  <a href="https://www.stockvision.pt">www.stockvision.pt</a>
</p>

<hr>
<p style="color:#6b7280; font-size:12px;">
  © <?= date('Y') ?> NewVision · StockVision · Todos os direitos reservados
</p>

</body>
</html>
