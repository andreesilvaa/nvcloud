<?php require_once __DIR__ . '/tabelas_logic.php'; ?>

  <?php if (!utilizadorEhAdmin()): ?>
    <div class="alerta-erro">Não tens permissão para gerir estados. Contacta um administrador.</div>
  <?php else: ?>

  <?php if (isset($_GET['nova']) || $tabEdit): ?>
    <h1 class="section-title"><?= $tabEdit ? 'Editar Estado' : 'Novo Estado' ?></h1>
    <div class="panel">
      <form method="post" autocomplete="off">
        <input type="hidden" name="form_type" value="guardar_estado">
        <?php if ($tabEdit): ?><input type="hidden" name="id" value="<?= (int)$tabEdit['id'] ?>"><?php endif; ?>
        <div style="margin-bottom:14px;">
          <label>Estado</label>
          <label><input type="text" name="nome" required value="<?= htmlspecialchars($tabEdit['nome'] ?? '') ?>"></label>
        </div>
        <div style="margin-bottom:14px;">
          <label>Descrição</label>
          <label><input type="text" name="descricao" value="<?= htmlspecialchars($tabEdit['descricao'] ?? '') ?>"></label>
        </div>
        <button type="submit" class="btn btn-teal"><?= $tabEdit ? 'Atualizar' : 'Guardar' ?></button>
        <a class="btn btn-yellow" href="app.php?page=estados" onclick="nvVoltar(event)">← Voltar à lista</a>
      </form>
    </div>
  <?php else: ?>
    <h1 class="section-title"><i class="bi bi-toggles" style="color:#c9a14a; margin-right:8px;"></i>Lista dos Estados</h1>
    <div class="panel">
      <div class="panel-header-row">
        <div class="panel-header-left">
          <span class="panel-count-badge"><?= count($tabListas) ?></span>
        </div>
        <div class="panel-header-actions">
          <div class="quick-search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" class="quick-search-input" data-table="#tabelaEstados" data-empty="#tabelaEstadosVazia" placeholder="Pesquisar estado…">
          </div>
          <a class="btn btn-teal" href="app.php?page=estados&nova=1"><i class="bi bi-plus-lg"></i> Novo Estado</a>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table" id="tabelaEstados">
          <thead><tr><th style="width:90px;">ID</th><th>Estado</th><th>Descrição</th><th class="actions" style="width:70px;">Ações</th></tr></thead>
          <tbody>
            <?php foreach ($tabListas as $row): ?>
              <tr>
                <td>#<?= (int)$row['id'] ?></td>
                <td><?= estadoBolha($row['nome']) ?></td>
                <td style="color:#6b7280;"><?= htmlspecialchars($row['descricao'] ?? '') ?: '—' ?></td>
                <td class="actions">
                  <a class="btn btn-yellow" href="app.php?page=estados&edit=<?= (int)$row['id'] ?>" title="Editar" aria-label="Editar"><i class="bi bi-pencil"></i></a>
                  <form method="post" style="display:inline-block;" onsubmit="return nvConfirmar(this, 'Eliminar este estado? Esta ação é irreversível.');">
                    <input type="hidden" name="form_type" value="eliminar_estado">
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <button type="submit" class="btn btn-red" title="Eliminar" aria-label="Eliminar"><i class="bi bi-trash3"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$tabListas): ?><tr id="tabelaEstadosVazia" data-no-filter><td colspan="4" class="table-empty-state"><i class="bi bi-inbox"></i>Sem registos.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php paginacaoTabela('estados', $tabPaginas, $tabPag); ?>
  <?php endif; ?>
  <?php endif; /* fim do guard de admin (estados) */ ?>

