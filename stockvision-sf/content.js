// ══════════════════════════════════════════════════════════
// content.js v4 — Leitura cirúrgica do DOM Salesforce
// Corrige: bloqueio/dados da WO anterior ao navegar em SPA,
// e leitura incompleta/errada do campo Descrição.
// ══════════════════════════════════════════════════════════

// Guarda o token do pedido mais recente. Pedidos antigos que ainda
// estejam "em voo" quando chega um novo são descartados — nunca
// respondem com dados desatualizados de uma WO anterior.
var _svUltimoPedidoToken = 0;

chrome.runtime.onMessage.addListener(function (msg, sender, sendResponse) {
  if (msg.tipo === 'verificar_pagina') {
    sendResponse(verificarPagina());
    return;
  }
  if (msg.tipo === 'ler_workorder') {
    var meuToken = ++_svUltimoPedidoToken;
    lerWorkOrderComEstabilizacao(meuToken)
      .then(function (resultado) {
        if (meuToken !== _svUltimoPedidoToken) return;
        sendResponse(resultado);
      })
      .catch(function (err) {
        if (meuToken !== _svUltimoPedidoToken) return;
        sendResponse({ erro: err.message });
      });
    return true;
  }
});

function verificarPagina() {
  var match = window.location.href.match(
    /\/lightning\/r\/WorkOrder\/([a-zA-Z0-9]{15,18})\/view/
  );
  if (!match) return { eWorkOrder: false };
  return { eWorkOrder: true, recordId: match[1] };
}

async function lerWorkOrderComEstabilizacao(meuToken) {
  var info = verificarPagina();
  if (!info.eWorkOrder) throw new Error('Não estás numa página de Work Order.');

  await esperarDomEstavel(info.recordId, meuToken);

  if (meuToken !== _svUltimoPedidoToken) return { ok: false, cancelado: true };

  return lerWorkOrder(info.recordId, meuToken);
}

function esperarDomEstavel(recordId, meuToken) {
  return new Promise(function (resolve) {
    var tentativas = 0;
    var maxTentativas = 15;
    var ultimoSnapshot = null;
    var estaveis = 0;

    function passo() {
      if (meuToken !== _svUltimoPedidoToken) { resolve(); return; }

      var atual = verificarPagina();
      if (!atual.eWorkOrder || atual.recordId !== recordId) { resolve(); return; }

      var snapshot = snapshotCabecalho();

      if (snapshot && snapshot === ultimoSnapshot) {
        estaveis++;
        if (estaveis >= 2) { resolve(); return; }
      } else {
        estaveis = 0;
      }
      ultimoSnapshot = snapshot;

      tentativas++;
      if (tentativas >= maxTentativas) { resolve(); return; }

      setTimeout(passo, 200);
    }

    passo();
  });
}

function snapshotCabecalho() {
  var candidatos = [
    '.slds-page-header__name-title h1 span',
    '.slds-page-header__title',
    'h1.slds-page-header__title',
  ];
  for (var i = 0; i < candidatos.length; i++) {
    var el = document.querySelector(candidatos[i]);
    if (el && el.textContent.trim()) return el.textContent.trim();
  }
  return document.title || null;
}

async function lerWorkOrder(recordId, meuToken) {
  var sessionId = obterSessionId();
  if (sessionId) {
    try {
      var api = await lerViaApi(recordId, sessionId);
      if (meuToken !== _svUltimoPedidoToken) return { ok: false, cancelado: true };
      if (api.ok) {
        try {
          // Só usamos o DOM para complementar/melhorar a Descrição se o
          // DOM, NESTE preciso momento, ainda corresponder ao recordId que
          // pedimos. Sem esta verificação, se o Salesforce (SPA) ainda não
          // tiver acabado de trocar o conteúdo do ecrã ao navegar entre
          // Work Orders, o DOM "mais longo" podia pertencer à WO anterior
          // — e essa versão era usada em vez da Descrição correta vinda da
          // API, mostrando dados de uma Work Order diferente da atual.
          var aindaNaWoCerta = verificarPagina();
          if (aindaNaWoCerta.eWorkOrder && aindaNaWoCerta.recordId === recordId) {
            var dom = lerViaDomCirurgico();
            if (dom && dom.ok && dom.dados) {
              Object.keys(api.dados).forEach(function (k) {
                if ((!api.dados[k] || api.dados[k] === '') && dom.dados[k]) {
                  api.dados[k] = dom.dados[k];
                }
              });
              if (dom.dados.descricao && dom.dados.descricao.length > (api.dados.descricao || '').length) {
                api.dados.descricao = dom.dados.descricao;
              }
            }
          }
        } catch (e2) { /* DOM indisponível — usa só a API */ }
        return api;
      }
    } catch (e) { /* continua para o DOM */ }
  }

  if (meuToken !== _svUltimoPedidoToken) return { ok: false, cancelado: true };
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
    descricao:     limparDescricaoTexto(wo.Description||''),
    tecnico:       '',
    data_recepcao: wo.StartDate||'',
    data_limite:   wo.CreatedDate||'',          // Data Limite = data de criação da WO
    prioridade:    mapPrio(wo.Priority),
    status_sf:     wo.Status||'',
  }};
}

// ── 5. DOM cirúrgico ───────────────────────────────────────
function lerViaDomCirurgico() {
  var d = {
    numero_wo:'', entidade:'', local_cliente:'', contacto:'', tecnico:'',
    morada:'', descricao:'', data_recepcao:'', data_limite:'',
    prioridade:'', status_sf:''
  };

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
    'phone':             'contacto',
    'account phone':     'contacto',
    'address':           'morada',
    'billing address':   'morada',
    'conta':             'entidade',
    'morada':            'morada',
    'endereço':          'morada',
    'endereco':          'morada',
    'telefone':          'contacto',
    'contacto':          'contacto',
    'prioridade':        'prioridade',
    'data de criação':   'data_limite',
    'data de criacao':   'data_limite',
    'data de início':    'data_recepcao',
  };

  var blocos = document.querySelectorAll([
    'force-record-field',
    'lightning-output-field',
    '.slds-form-element',
    '[data-field-id]',
  ].join(', '));

  blocos.forEach(function(bloco) {
    var labelEl = bloco.querySelector([
      'span.slds-form-element__label',
      'label.slds-form-element__label',
      'span.label',
      '[class*="label"]',
    ].join(', '));
    if (!labelEl) return;

    var labelTxt = labelEl.textContent.trim().toLowerCase();
    var campo = mapa[labelTxt];
    if (!campo) return;
    if (d[campo] && campo !== 'descricao') return;

    var valor = campo === 'descricao'
      ? extrairDescricaoDoCampo(bloco)
      : extrairValorDoCampo(bloco);
    if (!valor) return;

    if (valor.trim().toLowerCase() === labelTxt) return;

    if (campo === 'prioridade') {
      var pr = tokensPrioridade(valor);
      if (pr) d.prioridade = pr;
    } else if (campo === 'descricao') {
      if (!d.descricao.includes(valor)) {
        d.descricao = d.descricao ? (d.descricao + '\n' + valor) : valor;
      }
    } else {
      d[campo] = valor;
    }
  });

  d.descricao = limparDescricaoTexto(d.descricao);

  if (!d.prioridade) d.prioridade = 'Normal';

  if (!d.numero_wo) {
    d.numero_wo = lerNumerWoFallback();
  }

  if (d.entidade && !d.local_cliente) d.local_cliente = d.entidade;

  if (!d.numero_wo && !d.entidade) {
    throw new Error(
      'Não foi possível ler dados desta página.\n' +
      'Certifica-te que estás na vista de detalhe de uma Work Order.'
    );
  }

  return { ok:true, fonte:'dom', dados:d };
}

function extrairDescricaoDoCampo(bloco) {
  var candidatosRico = [
    'lightning-formatted-rich-text',
    'lightning-formatted-text',
    '.slds-form-element__static',
    '[class*="output"] span',
  ];

  for (var i = 0; i < candidatosRico.length; i++) {
    var el = bloco.querySelector(candidatosRico[i]);
    if (!el) continue;

    var clone = el.cloneNode(true);
    clone.querySelectorAll('button, [role="button"], svg, .slds-assistive-text').forEach(function(b) {
      b.remove();
    });

    clone.querySelectorAll('br').forEach(function (br) {
      br.replaceWith('\n');
    });
    clone.querySelectorAll('p, div, li').forEach(function (blockEl) {
      blockEl.append('\n');
    });

    var txt = clone.textContent
      .replace(/\u00a0/g, ' ')
      .replace(/[ \t]+/g, ' ')
      .replace(/\n{3,}/g, '\n\n')
      .trim();

    if (txt) return txt;
  }

  return '';
}

function limparDescricaoTexto(txt) {
  if (!txt) return '';
  return String(txt)
    .replace(/\r\n/g, '\n')
    .replace(/\u00a0/g, ' ')
    .replace(/[ \t]+/g, ' ')
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}

// ── 6. Extrair valor limpo de um bloco de campo ────────────
function extrairValorDoCampo(bloco) {
  // Prioridade de selectors, do mais específico ao mais genérico.
  // Todos excluem botões e labels.
  var candidatos = [
    // Componentes Lightning específicos para valores
    'lightning-formatted-text',
    'lightning-formatted-rich-text',
    'lightning-formatted-address',
    'lightning-formatted-date-time',
    'lightning-formatted-number',
    'lightning-formatted-phone',
    'lightning-formatted-email',
    'lightning-formatted-url',
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

    var txt = clone.textContent.replace(/\s+/g, ' ').trim();
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
