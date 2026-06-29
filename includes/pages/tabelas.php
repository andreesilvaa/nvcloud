<?php
/* Tabelas - Database tables management */
if ($page !== 'tabelas') return;
?>
<h1 class="section-title"><i class="bi bi-table"></i> Tabelas</h1>

<div class="panel">
  <div class="panel-header">
    <h2>Gestão de Tabelas do Sistema</h2>
  </div>
  <div class="panel-body">
    <p style="color: #6b7280; margin-bottom: 20px;">Selecione uma tabela para gerir:</p>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
      <a href="app.php?page=categorias" class="btn btn-teal" style="text-align: center; text-decoration: none;">
        <i class="bi bi-tag"></i> Categorias
      </a>
      <a href="app.php?page=estados" class="btn btn-teal" style="text-align: center; text-decoration: none;">
        <i class="bi bi-circle-fill"></i> Estados
      </a>
      <a href="app.php?page=produtos" class="btn btn-teal" style="text-align: center; text-decoration: none;">
        <i class="bi bi-box-seam"></i> Produtos
      </a>
      <a href="app.php?page=parceiros" class="btn btn-teal" style="text-align: center; text-decoration: none;">
        <i class="bi bi-people"></i> Parceiros
      </a>
      <a href="app.php?page=fabricantes" class="btn btn-teal" style="text-align: center; text-decoration: none;">
        <i class="bi bi-building"></i> Fabricantes
      </a>
      <a href="app.php?page=contas" class="btn btn-teal" style="text-align: center; text-decoration: none;">
        <i class="bi bi-person-lines-fill"></i> Contas
      </a>
    </div>
  </div>
</div>
