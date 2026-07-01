<?php
/* Configurações - Settings page */
if ($page !== 'configuracoes') return;
?>
<div class="config-geral-page">

<!-- Faixa de chips de estado (resumo rápido das preferências atuais) -->
<div class="cfg-chips" id="cfgChips">
  <div class="cfg-chip"><i class="bi bi-palette"></i> Tema: <strong id="chipTema">Claro</strong></div>
  <div class="cfg-chip"><i class="bi bi-translate"></i> Idioma: <strong id="chipIdioma">PT</strong></div>
  <div class="cfg-chip"><i class="bi bi-bell"></i> Notificações: <strong id="chipNotif">On</strong></div>
  <div class="cfg-chip"><i class="bi bi-arrows-collapse"></i> Densidade: <strong id="chipDensidade">Normal</strong></div>
</div>

<!-- Aparência | Preferências regionais (lado a lado) -->
<div class="cfg-grid-2">
  <div class="panel">
    <h4><i class="bi bi-palette" style="color:#c9a14a; margin-right:6px;"></i>Aparência</h4>
    <div class="cfg-field">
      <label>Tema</label>
      <select id="theme-select">
        <option value="light">Claro</option>
        <option value="dark">Escuro</option>
        <option value="auto">Automático</option>
      </select>
    </div>
    <div class="cfg-field">
      <label>Densidade da Interface</label>
      <select id="density-select">
        <option value="compact">Compacto</option>
        <option value="normal" selected>Normal</option>
        <option value="spacious">Espaçoso</option>
      </select>
    </div>
  </div>

  <div class="panel">
    <h4><i class="bi bi-globe2" style="color:#c9a14a; margin-right:6px;"></i>Preferências regionais</h4>
    <div class="cfg-field">
      <label>Idioma</label>
      <select id="language-select">
        <option value="pt">Português</option>
        <option value="en">English</option>
        <option value="es">Español</option>
      </select>
    </div>
  </div>
</div>

<!-- Notificações (largura total) -->
<div class="panel" style="margin-top:18px;">
  <h4><i class="bi bi-bell" style="color:#c9a14a; margin-right:6px;"></i>Notificações</h4>
  <label class="cfg-check">
    <input type="checkbox" id="notif-enable" checked>
    <span>Ativar notificações</span>
  </label>
  <p style="margin:10px 0 0; font-size:13px; color:#6b7280;">
    Recebe avisos de PATs urgentes, prazos a expirar, peças por rever e rascunhos por finalizar.
  </p>
</div>

<!-- Guardar -->
<div class="cfg-actions">
  <button type="button" class="btn btn-teal" id="cfgGuardar"><i class="bi bi-check2"></i> Guardar alterações</button>
</div>

</div>

<style>
.cfg-chips{
  display:flex; flex-wrap:wrap; gap:10px; margin-bottom:18px;
}
.cfg-chip{
  display:inline-flex; align-items:center; gap:8px;
  background:#fff; border:1px solid #e5e9ef; border-radius:999px;
  padding:8px 16px; font-size:13px; color:#6b7280;
  box-shadow:0 1px 4px rgba(0,0,0,.04);
}
.cfg-chip i{ color:#c9a14a; }
.cfg-chip strong{ color:#1f2937; }
.cfg-grid-2{
  display:grid; grid-template-columns:1fr 1fr; gap:18px;
}
.cfg-field{ margin-bottom:16px; }
.cfg-field:last-child{ margin-bottom:0; }
.cfg-field label{
  display:block; font-size:11px; font-weight:700; text-transform:uppercase;
  letter-spacing:.05em; color:#9ca3af; margin-bottom:7px;
}
.cfg-check{
  display:flex; align-items:center; gap:10px; cursor:pointer; font-size:14px; font-weight:500;
}
.cfg-actions{
  display:flex; justify-content:flex-end; margin-top:18px;
}
body.dark-mode .cfg-chip{ background:#1e2533; border-color:#374151; color:#9ca3af; }
body.dark-mode .cfg-chip strong{ color:#f3f4f6; }
@media (max-width:768px){
  .cfg-grid-2{ grid-template-columns:1fr; }
}
</style>

<script>
(function(){
  const temaSel    = document.getElementById('theme-select');
  const idiomaSel  = document.getElementById('language-select');
  const densSel    = document.getElementById('density-select');
  const notifChk   = document.getElementById('notif-enable');

  const chipTema      = document.getElementById('chipTema');
  const chipIdioma    = document.getElementById('chipIdioma');
  const chipNotif     = document.getElementById('chipNotif');
  const chipDensidade = document.getElementById('chipDensidade');

  const labelTema = { light:'Claro', dark:'Escuro', auto:'Automático' };
  const labelDens = { compact:'Compacto', normal:'Normal', spacious:'Espaçoso' };

  // Restaurar valores guardados
  if (temaSel   && localStorage.getItem('theme'))    temaSel.value   = localStorage.getItem('theme');
  if (idiomaSel && localStorage.getItem('language')) idiomaSel.value = localStorage.getItem('language');
  if (densSel   && localStorage.getItem('density'))  densSel.value   = localStorage.getItem('density');
  if (notifChk  && localStorage.getItem('notif') !== null) notifChk.checked = localStorage.getItem('notif') === '1';

  function atualizarChips(){
    if (chipTema)      chipTema.textContent      = labelTema[temaSel.value] || 'Claro';
    if (chipIdioma)    chipIdioma.textContent    = (idiomaSel.value || 'pt').toUpperCase();
    if (chipNotif)     chipNotif.textContent     = notifChk.checked ? 'On' : 'Off';
    if (chipDensidade) chipDensidade.textContent = labelDens[densSel.value] || 'Normal';
  }
  atualizarChips();

  // Densidade aplica-se em tempo real (como antes)
  densSel?.addEventListener('change', function(){
    document.body.classList.remove('density-compact','density-normal','density-spacious');
    document.body.classList.add('density-' + this.value);
    atualizarChips();
  });
  [temaSel, idiomaSel, notifChk].forEach(function(el){
    el?.addEventListener('change', atualizarChips);
  });

  // Guardar todas as preferências de uma vez
  document.getElementById('cfgGuardar')?.addEventListener('click', function(){
    if (temaSel)   localStorage.setItem('theme', temaSel.value);
    if (idiomaSel) localStorage.setItem('language', idiomaSel.value);
    if (densSel)   localStorage.setItem('density', densSel.value);
    if (notifChk)  localStorage.setItem('notif', notifChk.checked ? '1' : '0');
    this.innerHTML = '<i class="bi bi-check-circle-fill"></i> Guardado!';
    const btn = this;
    setTimeout(function(){ btn.innerHTML = '<i class="bi bi-check2"></i> Guardar alterações'; }, 1800);
  });
})();
</script>
