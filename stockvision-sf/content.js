// ══════════════════════════════════════════════════════════
// content.js v3 — Leitura cirúrgica do DOM Salesforce
// ══════════════════════════════════════════════════════════

chrome.runtime.onMessage.addListener(function (msg, sender, sendResponse) {
  if (msg.tipo === 'verificar_pagina') {
    sendResponse(verificarPagina());
  }
  if (msg.tipo === 'ler_workorder') {
    lerWorkOrder().then(sendResponse).catch(function (err) {
      sendResponse({ ok: false, erro: err.message });
    });
    return true;
  }
});

// ── 1. Verificar URL ───────────────────────────────────────
function verificarPagina() {
  var match = window.location.href.match(
    /\/lightning\/r\/WorkOrder\/([a-zA-Z0-9]{15,18})\/view/
  );
  if (!match) return { eWorkOrder: false };
  return { eWorkOrder: true, recordId: match[1] };
}

// ── 2. Coordenador principal ───────────────────────────────
async function lerWorkOrder() {
  var info = verificarPagina();
  if (!info.eWorkOrder) throw new Error('Não estás numa página de Work Order.');

  // Tentativa 1: API com sessão
  var sessionId = obterSessionId();
  if (sessionId) {
    try {
      var api = await lerViaApi(info.recordId, sessionId);
      if (api.ok) return api;
    } catch (e) { /* continua */ }
  }

  // Tentativa 2: DOM cirúrgico
  return lerViaDomCirurgico();
}

// ── 3. Sessão ──────────────────────────────────────────────
function obterSessionId() {
  var cookies = document.cookie.split(';');
  for (var i = 0; i < cookies.length; i++) {
    var p = cookies[i].trim().split('=');
    var n = p[0].trim(), v = p.slice(1).join('=').trim();
    if (n === 'sid' || n.endsWith('!sid')) return decodeURIComponent(v);
  }
  try {
    if (window.sforce && window.sforce.connection && window.sforce.connection.sessionId)
      return window.sforce.connection.sessionId;
  } catch(e) {}
  return null;
}

// ── 4. API Salesforce ──────────────────────────────────────
async function lerViaApi(recordId, sessionId) {
  var v    = await determinarVersao(sessionId);
  var soql = "SELECT WorkOrderNumber,Description,Status,Priority,CreatedDate," +
             "StartDate,Account.Name,Account.BillingStreet," +
             "Account.BillingCity,Account.BillingPostalCode," +
             "Account.BillingCountry,Account.Phone,Contact.Name" +
             " FROM WorkOrder WHERE Id='" + recordId + "'";
  var resp = await fetch('/services/data/' + v + '/query?q=' + encodeURIComponent(soql), {
    headers: { 'Accept': 'application/json', 'Authorization': 'Bearer ' + sessionId }
  });
  if (resp.status === 403) throw new Error('403');
  if (!resp.ok) throw new Error('HTTP ' + resp.status);
  var d  = await resp.json();
  if (!d.records || !d.records.length) throw new Error('Sem registos');
  var wo = d.records[0], c = wo.Account||{}, ct = wo.Contact||{};
  return { ok:true, fonte:'api', dados:{
    numero_wo:     wo.WorkOrderNumber||'',
    entidade:      c.Name||'',
    local_cliente: c.Name||'',
    contacto:      ct.Name||c.Phone||'',
    morada:        [c.BillingStreet,c.BillingCity,c.BillingPostalCode,c.BillingCountry].filter(Boolean).join(', '),
    descricao:     wo.Description||'',          // apenas a linha "Description"
    tecnico:       '',                          // "Support Partner" lido via DOM (não fiável na API)
    data_recepcao: wo.StartDate||'',
    data_limite:   wo.CreatedDate||'',          // Data Limite = data de criação da WO
    prioridade:    mapPrio(wo.Priority),
    status_sf:     wo.Status||'',
  }};
}

// ── 5. DOM cirúrgico ───────────────────────────────────────
// Lê cada campo individualmente pelo seu label exato,
// extraindo apenas o valor e ignorando botões de ação.
function lerViaDomCirurgico() {
  var d = {
    numero_wo:'', entidade:'', local_cliente:'', contacto:'', tecnico:'',
    morada:'', descricao:'', data_recepcao:'', data_limite:'',
    prioridade:'', status_sf:''   // '' para o campo poder ser lido; default 'Normal' no fim
  };

  // Mapeamento: label (em minúsculas) → campo interno
  var mapa = {
    'work order number': 'numero_wo',
    'account name':      'entidade',
    'account':           'entidade',
    'description':       'descricao',   // só a linha "Description" (não "Subject")
    'support partner':   'tecnico',     // técnico responsável
    'priority':          'prioridade',
    'status':            'status_sf',
    'start date':        'data_recepcao',
    'created date':      'data_limite', // Data Limite = data de criação da WO
    'contact':           'contacto',
    // Português
    'conta':             'entidade',
    'prioridade':        'prioridade',
    'data de criação':   'data_limite',
    'data de criacao':   'data_limite',
    'data de início':    'data_recepcao',
  };

  // Percorrer todos os "blocos de campo" do Salesforce Lightning
  var blocos = document.querySelectorAll([
    'force-record-field',
    'lightning-output-field',
    '.slds-form-element',
    '[data-field-id]',
  ].join(', '));

  blocos.forEach(function(bloco) {
    // --- Encontrar label ---
    var labelEl = bloco.querySelector([
      'span.slds-form-element__label',
      'label.slds-form-element__label',
      'span.label',
      '[class*="label"]',
    ].join(', '));
    if (!labelEl) return;

    var labelTxt = labelEl.textContent.trim().toLowerCase();
    var campo = mapa[labelTxt];
    if (!campo || d[campo]) return; // já preenchido ou label desconhecida

    // --- Extrair valor (sem botões) ---
    var valor = extrairValorDoCampo(bloco);
    if (!valor) return;

    // Campo vazio no Salesforce: o que se lê é muitas vezes a própria label
    // (ex.: "Contact", "Start Date", "End Date") — nesse caso, ignorar.
    if (valor.trim().toLowerCase() === labelTxt) return;

    if (campo === 'prioridade') {
      // Só aceita valores que sejam mesmo uma prioridade reconhecida
      // (ignora label/texto errado lido de outro elemento).
      var pr = tokensPrioridade(valor);
      if (pr) d.prioridade = pr;
    } else {
      d[campo] = valor;
    }
  });

  // Se a prioridade não foi lida de lado nenhum, assume Normal.
  if (!d.prioridade) d.prioridade = 'Normal';

  // Fallback para número WO — ler do URL ou do header do registo
  if (!d.numero_wo) {
    d.numero_wo = lerNumerWoFallback();
  }

  // Local = entidade se não preenchido
  if (d.entidade && !d.local_cliente) d.local_cliente = d.entidade;

  if (!d.numero_wo && !d.entidade) {
    throw new Error(
      'Não foi possível ler dados desta página.\n' +
      'Certifica-te que estás na vista de detalhe de uma Work Order.'
    );
  }

  return { ok:true, fonte:'dom', dados:d };
}

// ── 6. Extrair valor limpo de um bloco de campo ────────────
function extrairValorDoCampo(bloco) {
  // Prioridade de selectors, do mais específico ao mais genérico.
  // Todos excluem botões e labels.
  var candidatos = [
    // Componentes Lightning específicos para valores
    'lightning-formatted-text',
    'lightning-formatted-date-time',
    'lightning-formatted-number',
    'lightning-formatted-phone',
    'lightning-formatted-email',
    // Link de lookup (ex: nome da conta)
    '.slds-form-element__static a',
    // Valor estático genérico
    '.slds-form-element__static',
    // Texto de output
    '[class*="output"] span',
    '[class*="fieldValue"]',
  ];

  for (var i = 0; i < candidatos.length; i++) {
    var el = bloco.querySelector(candidatos[i]);
    if (!el) continue;

    // Clonar e remover botões/ícones para obter só o texto
    var clone = el.cloneNode(true);
    clone.querySelectorAll('button, [role="button"], svg, .slds-assistive-text').forEach(function(b) {
      b.remove();
    });

    var txt = clone.textContent.trim();
    if (txt && txt.length > 0 && txt.length < 500) return txt;
  }

  return '';
}

// ── 7. Fallback para número WO ─────────────────────────────
function lerNumerWoFallback() {
  // Tentar padrão "WO-XXXXXXXX" visível em qualquer parte do texto
  var textoCompleto = document.body.innerText;
  var match = textoCompleto.match(/\bWO-\d{6,}\b/);
  if (match) return match[0];

  // Tentar o título do separador do browser
  var matchTitle = document.title.match(/\bWO-[\w-]+\b/);
  if (matchTitle) return matchTitle[0];

  // Tentar o header do registo (evita "Service Console")
  var headerCandidatos = [
    '.slds-page-header__name-title h1 span',
    '.slds-page-header__title',
    'h1.slds-page-header__title',
  ];
  for (var i = 0; i < headerCandidatos.length; i++) {
    var el = document.querySelector(headerCandidatos[i]);
    if (el) {
      var txt = el.textContent.trim();
      // Ignorar se parecer nome de app (contém "Console", "Service", etc.)
      if (txt && !/console|service|home|salesforce/i.test(txt)) return txt;
    }
  }

  return '';
}

// ── 8. Auxiliares ──────────────────────────────────────────
// Reconhece a prioridade a partir de qualquer texto. Devolve 'Urgente',
// 'Normal' ou null (não reconhecido). Tolerante a espaços e texto extra
// (ex.: "High", "P1 - Critical", "Alta").
function tokensPrioridade(p) {
  if (!p) return null;
  var l = ' ' + String(p).toLowerCase() + ' ';
  if (/(critical|cr[ií]tic|high|alta|urgent)/.test(l)) return 'Urgente';
  if (/(medium|m[eé]di|low|baixa|normal)/.test(l))     return 'Normal';
  return null;
}

function mapPrio(p) {
  return tokensPrioridade(p) || 'Normal';
}

async function determinarVersao(sessionId) {
  try {
    var r = await fetch('/services/data/', {
      headers: { 'Authorization': 'Bearer ' + sessionId }
    });
    var lista = await r.json();
    if (Array.isArray(lista) && lista.length) return 'v' + lista[lista.length-1].version;
  } catch(e) {}
  return 'v59.0';
}
