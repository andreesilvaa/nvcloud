<?php
/* Configurações - Settings page */
if ($page !== 'configuracoes') return;
?>
<div class="config-geral-page">
<div class="panel">
  <div class="panel-header">
    <h2>Preferências do Sistema</h2>
  </div>
  <div class="panel-body">
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
      <!-- Tema -->
      <div style="padding: 15px; border: 1px solid #e5e9ef; border-radius: 8px;">
        <h3 style="margin: 0 0 12px 0; font-size: 14px; color: #6b7280;">Tema</h3>
        <select id="theme-select" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
          <option value="light">Claro</option>
          <option value="dark">Escuro</option>
          <option value="auto">Automático</option>
        </select>
      </div>

      <!-- Idioma -->
      <div style="padding: 15px; border: 1px solid #e5e9ef; border-radius: 8px;">
        <h3 style="margin: 0 0 12px 0; font-size: 14px; color: #6b7280;">Idioma</h3>
        <select id="language-select" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
          <option value="pt">Português</option>
          <option value="en">English</option>
          <option value="es">Español</option>
        </select>
      </div>

      <!-- Notificações -->
      <div style="padding: 15px; border: 1px solid #e5e9ef; border-radius: 8px;">
        <h3 style="margin: 0 0 12px 0; font-size: 14px; color: #6b7280;">Notificações</h3>
        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
          <input type="checkbox" id="notif-enable" checked style="cursor: pointer;">
          <span style="font-size: 13px;">Ativar notificações</span>
        </label>
      </div>

      <!-- Densidade -->
      <div style="padding: 15px; border: 1px solid #e5e9ef; border-radius: 8px;">
        <h3 style="margin: 0 0 12px 0; font-size: 14px; color: #6b7280;">Densidade da Interface</h3>
        <select id="density-select" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
          <option value="compact">Compacto</option>
          <option value="normal" selected>Normal</option>
          <option value="spacious">Espaçoso</option>
        </select>
      </div>
    </div>
  </div>
</div>
</div>

<script>
document.getElementById('theme-select')?.addEventListener('change', function(){
  localStorage.setItem('theme', this.value);
  location.reload();
});
document.getElementById('language-select')?.addEventListener('change', function(){
  localStorage.setItem('language', this.value);
  location.reload();
});
document.getElementById('density-select')?.addEventListener('change', function(){
  localStorage.setItem('density', this.value);
  document.body.classList.remove('density-compact', 'density-normal', 'density-spacious');
  document.body.classList.add('density-' + this.value);
});
function clearCache(){
  if (confirm('Tem certeza que quer limpar o cache?')) {
    localStorage.clear();
    sessionStorage.clear();
    alert('Cache limpo com sucesso!');
  }
}
function logout(){
  if (confirm('Tem certeza que quer terminar a sessão?')) {
    window.location.href = 'logout.php';
  }
}
</script>
