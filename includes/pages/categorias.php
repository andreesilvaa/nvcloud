<?php require_once __DIR__ . '/tabelas_logic.php'; ?>

  <?php if (!utilizadorEhAdmin()): ?>
    <div class="alerta-erro">Não tens permissão para gerir categorias. Contacta um administrador.</div>
  <?php else: ?>

  <?php if (isset($_GET['nova']) || $tabEdit): ?>
    <h1 class="section-title"><?= $tabEdit ? 'Editar Categoria' : 'Nova Categoria' ?></h1>
    <div class="panel">
      <form method="post" autocomplete="off">
        <input type="hidden" name="form_type" value="guardar_categoria">
        <?php if ($tabEdit): ?><input type="hidden" name="id" value="<?= (int)$tabEdit['id'] ?>"><?php endif; ?>
        <div style="margin-bottom:14px;">
          <label>Categoria</label>
          <label><input type="text" name="nome" required value="<?= htmlspecialchars($tabEdit['nome'] ?? '') ?>"></label>
        </div>
        <button type="submit" class="btn btn-teal"><?= $tabEdit ? 'Atualizar' : 'Guardar' ?></button>
        <a class="btn btn-yellow" href="app.php?page=categorias" onclick="nvVoltar(event)">← Voltar à lista</a>
      </form>
    </div>
  <?php else: ?>
    <div class="panel">
      <div class="panel-header-row">
        <div class="panel-header-left">
          <span class="panel-count-badge"><?= count($tabListas) ?></span>
        </div>
        <div class="panel-header-actions">
          <div class="quick-search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" class="quick-search-input" data-table="#tabelaCategorias" data-empty="#tabelaCategoriasVazia" placeholder="Pesquisar categoria…">
          </div>
          <a class="btn btn-teal" href="app.php?page=categorias&nova=1"><i class="bi bi-plus-lg"></i> Nova Categoria</a>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-card-stack tcs-actions-right" id="tabelaCategorias">
          <thead><tr><th style="width:90px;">ID</th><th>Categoria</th><th class="actions" style="width:70px;">Ações</th></tr></thead>
          <tbody>
            <?php foreach ($tabListas as $row): ?>
              <tr>
                <td class="tcs-content">
                  <div class="tcs-field" data-label="ID">#<?= (int)$row['id'] ?></div>
                  <div class="tcs-field" data-label="Categoria"><?= htmlspecialchars($row['nome']) ?></div>
                </td>
                <td class="actions">
                  <a class="btn btn-yellow" href="app.php?page=categorias&edit=<?= (int)$row['id'] ?>" title="Editar" aria-label="Editar"><i class="bi bi-pencil"></i></a>
                  <form method="post" style="display:inline-block;" onsubmit="return nvConfirmar(this, 'Eliminar esta categoria? Esta ação é irreversível.');">
                    <input type="hidden" name="form_type" value="eliminar_categoria">
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <button type="submit" class="btn btn-red" title="Eliminar" aria-label="Eliminar"><i class="bi bi-trash3"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$tabListas): ?><tr id="tabelaCategoriasVazia" data-no-filter><td colspan="3" class="table-empty-state"><i class="bi bi-inbox"></i>Sem registos.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php paginacaoTabela('categorias', $tabPaginas, $tabPag); ?>
  <?php endif; ?>
  <?php endif; /* fim do guard de admin (categorias) */ ?>

