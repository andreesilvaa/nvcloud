// ══════════════════════════════════════════════════════════
// popup.js — Lógica do popup da extensão StockVision
// ══════════════════════════════════════════════════════════

var dadosWO     = null;
var svUrlPadrao = 'http://localhost/nvcloud/app.php';

// ── Ao abrir o popup ───────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {

  // Carregar URL + token guardados
  chrome.storage.local.get(['svUrl', 'svToken'], function (res) {
    document.getElementById('svUrl').value = res.svUrl || svUrlPadrao;
    document.getElementById('svToken').value = res.svToken || '';
  });

  // Guardar URL quando muda
  document.getElementById('svUrl').addEventListener('change', function () {
    chrome.storage.local.set({ svUrl: this.value.trim() });
  });

  // Guardar token quando muda
  document.getElementById('svToken').addEventListener('change', function () {
    chrome.storage.local.set({ svToken: this.value.trim() });
  });

  // Botões
  document.getElementById('btnEnviar').addEventListener('click', enviarParaStockVision);
  document.getElementById('btnCancelar').addEventListener('click', function () {
    mostrarEstado('stateNotWO');
  });
  document.getElementById('btnTentarNovamente').addEventListener('click', iniciar);

  iniciar();
});

// ── Iniciar: verificar página e ler WO ────────────────────
function iniciar() {
  mostrarEstado('stateLoading');

  chrome.tabs.query({ active: true, currentWindow: true }, function (tabs) {
    if (!tabs[0]) { mostrarErro('Não foi possível aceder à aba ativa.'); return; }

    var tabId = tabs[0].id;

    chrome.tabs.sendMessage(tabId, { tipo: 'verificar_pagina' }, function (resp) {
      if (chrome.runtime.lastError) {
        // O content script ainda não está injetado nesta aba: injeta e volta a verificar
        chrome.scripting.executeScript(
          { target: { tabId: tabId }, files: ['content.js'] },
          function () { setTimeout(function () { verificarELer(tabId); }, 500); }
        );
        return;
      }
      if (!resp || !resp.eWorkOrder) {
        badge('—', '#555');
        mostrarEstado('stateNotWO');
        return;
      }
      lerDados(tabId);
    });
  });
}

function verificarELer(tabId) {
  chrome.tabs.sendMessage(tabId, { tipo: 'verificar_pagina' }, function (resp) {
    if (chrome.runtime.lastError) {
        mostrarErro('Falha ao comunicar com a página: ' + chrome.runtime.lastError.message);
        return;
    }
    lerDados(tabId);
  });
}

function lerDados(tabId) {
  chrome.tabs.sendMessage(tabId, { tipo: 'ler_workorder' }, function (resp) {
    if (chrome.runtime.lastError) {
      mostrarErro('Falha ao comunicar com a página: ' + chrome.runtime.lastError.message);
      return;
    }
    if (!resp.ok) { mostrarErro(resp.erro); return; }
    dadosWO = resp.dados;
    preencherPreview(dadosWO);
    mostrarEstado('stateDados');
    badge('WO: ' + dadosWO.numero_wo, '#c9a14a');
  });
}

// ── Preencher preview ─────────────────────────────────────
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

  var svUrl  = document.getElementById('svUrl').value.trim()  || svUrlPadrao;
  var svToken = document.getElementById('svToken').value.trim();
  chrome.storage.local.set({ svUrl: svUrl, svToken: svToken });

  var erroBox = document.getElementById('erroEnvio');
  if (erroBox) erroBox.innerHTML = '';

  if (!svToken) {
    if (erroBox) erroBox.innerHTML = '<div class="erro">Define o "Token da extensão" (igual ao EXTENSION_TOKEN do config.php).</div>';
    return;
  }

  var btn = document.getElementById('btnEnviar');
  btn.disabled    = true;
  btn.textContent = 'A enviar…';

  // Dados como corpo POST (form-encoded)
  var params = new URLSearchParams({
    action:        'importar_workorder',
    numero_wo:     dadosWO.numero_wo      || '',
    entidade:      dadosWO.entidade       || '',
    local_cliente: dadosWO.local_cliente  || '',
    contacto:      dadosWO.contacto       || '',
    tecnico:       dadosWO.tecnico        || '',
    morada:        dadosWO.morada         || '',
    descricao:     (dadosWO.descricao     || '').substring(0, 800),
    data_recepcao: dadosWO.data_recepcao  || '',
    data_limite:   dadosWO.data_limite    || '',
    prioridade:    dadosWO.prioridade     || 'Normal',
  });

  // POST com cabeçalho X-NV-Token (autenticação da extensão, sem CSRF de sessão).
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
    if (!data || !data.ok) {
      throw new Error((data && data.erro) ? data.erro : 'Falha ao criar o PAT.');
    }
    // Sucesso: mostrar estado e link para o PAT criado.
    var sub = document.getElementById('msgSuccessSub');
    if (sub) sub.textContent = data.duplicado ? 'Este WO já estava importado.' : ('WO ' + (dadosWO.numero_wo || ''));
    var link = document.getElementById('linkPat');
    if (link) link.href = svUrl + '?page=pats&ver=' + (data.pat_id || '');
    mostrarEstado('stateSuccess');
    badge('PAT #' + (data.pat_id || ''), '#16a34a');
  })
  .catch(function (err) {
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

function mostrarErro(msg) {
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
