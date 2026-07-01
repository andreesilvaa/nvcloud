// ══════════════════════════════════════════════════════════
// popup.js — Lógica do popup da extensão StockVision
// Corrige: dados/estado da WO anterior a aparecerem quando se
// abre o popup rapidamente em WOs diferentes seguidas.
// ══════════════════════════════════════════════════════════

var dadosWO     = null;
var svUrlPadrao = 'https://www.stockvision.pt/app.php';

// Token da extensão — pré-configurado (igual ao EXTENSION_TOKEN em config.php).
// Não é pedido ao utilizador; vive apenas dentro do código da extensão instalada.
var EXTENSION_TOKEN = 'sv_ext_k7G9mPxQ2wR4nL8jF5vB3hY6tA1cD0eS9uI2oW4rT7yU';

// Token de sessão do popup: incrementado sempre que iniciar() corre.
// Qualquer resposta assíncrona (mensagens ao content script) que chegue
// depois de o popup ter iniciado um novo pedido é ignorada — isto evita
// que dados de uma Work Order anterior apareçam "atrasados" por cima
// da Work Order atual.
var sessaoAtual = 0;

// ── Ao abrir o popup ───────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {

  chrome.storage.local.get(['svUrl'], function (res) {
    document.getElementById('svUrl').value = res.svUrl || svUrlPadrao;
  });

  document.getElementById('svUrl').addEventListener('change', function () {
    chrome.storage.local.set({ svUrl: this.value.trim() });
  });

  document.getElementById('btnEnviar').addEventListener('click', enviarParaStockVision);
  document.getElementById('btnCancelar').addEventListener('click', function () {
    mostrarEstado('stateNotWO');
  });
  document.getElementById('btnTentarNovamente').addEventListener('click', iniciar);

  iniciar();
});

// ── Iniciar: verificar página e ler WO ────────────────────
function iniciar() {
  // Nova sessão: qualquer resposta pendente da sessão anterior fica
  // automaticamente invalidada (ver checks "if (minhaSessao !== sessaoAtual)").
  var minhaSessao = ++sessaoAtual;

  // Reset total do estado visível e dos dados em memória — garante que
  // nunca se vê, ainda que por uma fração de segundo, a WO anterior.
  dadosWO = null;
  limparPreview();
  badge('—', '#555');
  mostrarEstado('stateLoading');

  chrome.tabs.query({ active: true, currentWindow: true }, function (tabs) {
    if (minhaSessao !== sessaoAtual) return;
    if (!tabs[0]) { mostrarErro('Não foi possível aceder à aba ativa.', minhaSessao); return; }

    var tabId = tabs[0].id;

    chrome.tabs.sendMessage(tabId, { tipo: 'verificar_pagina' }, function (resp) {
      if (minhaSessao !== sessaoAtual) return;

      if (chrome.runtime.lastError) {
        // O content script ainda não está injetado nesta aba: injeta e volta a verificar
        chrome.scripting.executeScript(
          { target: { tabId: tabId }, files: ['content.js'] },
          function () {
            if (minhaSessao !== sessaoAtual) return;
            setTimeout(function () { verificarELer(tabId, minhaSessao); }, 300);
          }
        );
        return;
      }
      if (!resp || !resp.eWorkOrder) {
        badge('—', '#555');
        mostrarEstado('stateNotWO');
        return;
      }
      lerDados(tabId, minhaSessao);
    });
  });
}

function verificarELer(tabId, minhaSessao) {
  chrome.tabs.sendMessage(tabId, { tipo: 'verificar_pagina' }, function (resp) {
    if (minhaSessao !== sessaoAtual) return;
    if (chrome.runtime.lastError) {
      mostrarErro('Falha ao comunicar com a página: ' + chrome.runtime.lastError.message, minhaSessao);
      return;
    }
    if (!resp || !resp.eWorkOrder) {
      badge('—', '#555');
      mostrarEstado('stateNotWO');
      return;
    }
    lerDados(tabId, minhaSessao);
  });
}

function lerDados(tabId, minhaSessao) {
  chrome.tabs.sendMessage(tabId, { tipo: 'ler_workorder' }, function (resp) {
    // Se entretanto o popup reiniciou (ex.: reaberto noutra WO antes desta
    // resposta chegar), esta resposta é descartada — é exatamente isto que
    // antes causava o "aparecem os dados da Workorder anterior".
    if (minhaSessao !== sessaoAtual) return;

    if (chrome.runtime.lastError) {
      mostrarErro('Falha ao comunicar com a página: ' + chrome.runtime.lastError.message, minhaSessao);
      return;
    }
    if (!resp) {
      mostrarErro('Sem resposta da página. Tenta novamente.', minhaSessao);
      return;
    }
    if (resp.cancelado) {
      // O content script cancelou por si próprio um pedido obsoleto —
      // não é um erro para mostrar ao utilizador, apenas ignorar.
      return;
    }
    if (!resp.ok) { mostrarErro(resp.erro, minhaSessao); return; }

    dadosWO = resp.dados;
    preencherPreview(dadosWO);
    mostrarEstado('stateDados');
    badge('WO: ' + dadosWO.numero_wo, '#c9a14a');
  });
}

// ── Preencher / limpar preview ────────────────────────────
function limparPreview() {
  ['dWO', 'dEntidade', 'dDescricao', 'dDataLim'].forEach(function (id) {
    setText(id, '—');
    document.getElementById(id).classList.add('vazio');
  });
  var badgePrio = document.getElementById('dPrio');
  badgePrio.textContent = 'Normal';
  badgePrio.className   = 'badge-prio normal';
  var erroBox = document.getElementById('erroEnvio');
  if (erroBox) erroBox.innerHTML = '';
  var btn = document.getElementById('btnEnviar');
  btn.disabled    = false;
  btn.textContent = 'Copiar para StockVision';
}

function preencherPreview(d) {
  setText('dWO',       d.numero_wo   || '—');
  setText('dEntidade', d.entidade    || '—');
  setText('dDescricao', d.descricao
    ? d.descricao.substring(0, 150) + (d.descricao.length > 150 ? '…' : '')
    : '—');
  setText('dDataLim',  d.data_limite ? formatarData(d.data_limite) : '—');

  var badgePrio = document.getElementById('dPrio');
  if (d.prioridade === 'Urgente') {
    badgePrio.textContent = 'Urgente';
    badgePrio.className   = 'badge-prio';
  } else {
    badgePrio.textContent = 'Normal';
    badgePrio.className   = 'badge-prio normal';
  }

  ['dWO','dEntidade','dDescricao','dDataLim'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el.textContent === '—') el.classList.add('vazio');
    else el.classList.remove('vazio');
  });
}

// ── Enviar para StockVision (POST + X-NV-Token) ───────────
function enviarParaStockVision() {
  if (!dadosWO) return;
  var minhaSessao = sessaoAtual;

  var svUrl  = document.getElementById('svUrl').value.trim()  || svUrlPadrao;
  chrome.storage.local.set({ svUrl: svUrl });

  var erroBox = document.getElementById('erroEnvio');
  if (erroBox) erroBox.innerHTML = '';

  var svToken = EXTENSION_TOKEN;

  var btn = document.getElementById('btnEnviar');
  btn.disabled    = true;
  btn.textContent = 'A enviar…';

  var params = new URLSearchParams({
    action:        'importar_workorder',
    numero_wo:     dadosWO.numero_wo      || '',
    entidade:      dadosWO.entidade       || '',
    local_cliente: dadosWO.local_cliente  || '',
    contacto:      dadosWO.contacto       || '',
    tecnico:       dadosWO.tecnico        || '',
    morada:        dadosWO.morada         || '',
    descricao:     (dadosWO.descricao     || '').substring(0, 2000),
    data_recepcao: dadosWO.data_recepcao  || '',
    data_limite:   dadosWO.data_limite    || '',
    prioridade:    dadosWO.prioridade     || 'Normal',
  });

  fetch(svUrl, {
    method:  'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'X-NV-Token':   svToken
    },
    body: params.toString()
  })
  .then(function (resp) {
    return resp.json().catch(function () {
      throw new Error('Resposta inesperada (HTTP ' + resp.status + '). Confirma o URL e o token.');
    });
  })
  .then(function (data) {
    if (minhaSessao !== sessaoAtual) return; // popup mudou de WO entretanto
    if (!data || !data.ok) {
      throw new Error((data && data.erro) ? data.erro : 'Falha ao criar o PAT.');
    }
    var sub = document.getElementById('msgSuccessSub');
    if (sub) sub.textContent = data.duplicado ? 'Este WO já estava importado.' : ('WO ' + (dadosWO.numero_wo || ''));
    var link = document.getElementById('linkPat');
    if (link) link.href = svUrl + '?page=pats&ver=' + (data.pat_id || '');
    mostrarEstado('stateSuccess');
    badge('PAT #' + (data.pat_id || ''), '#16a34a');
  })
  .catch(function (err) {
    if (minhaSessao !== sessaoAtual) return;
    btn.disabled    = false;
    btn.textContent = 'Copiar para StockVision';
    if (erroBox) erroBox.innerHTML = '<div class="erro">' + (err.message || 'Erro ao enviar.') + '</div>';
  });
}

// ── Auxiliares ─────────────────────────────────────────────
function mostrarEstado(id) {
  document.querySelectorAll('.state').forEach(function (s) {
    s.classList.remove('active');
  });
  document.getElementById(id).classList.add('active');
}

function mostrarErro(msg, minhaSessao) {
  if (minhaSessao !== undefined && minhaSessao !== sessaoAtual) return;
  document.getElementById('msgErro').textContent = msg;
  mostrarEstado('stateErro');
  badge('Erro', '#dc2626');
}

function badge(texto, cor) {
  var el = document.getElementById('badgeStatus');
  el.textContent           = texto;
  el.style.color           = cor;
  el.style.background      = cor + '22';
  el.style.borderColor     = cor + '55';
}

function setText(id, txt) {
  document.getElementById(id).textContent = txt;
}

function formatarData(str) {
  if (!str) return '—';
  var d = new Date(str);
  if (isNaN(d)) return str;
  return d.toLocaleDateString('pt-PT') + ' ' + d.toLocaleTimeString('pt-PT', {
    hour: '2-digit', minute: '2-digit'
  });
}
