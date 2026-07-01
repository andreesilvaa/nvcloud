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
        <a class="btn btn-yellow" href="<?= tabUrl() ?>" onclick="nvVoltar(event)">← Voltar à lista</a>
      </form>
    </div>
  <?php else: ?>
    <div class="panel">
      <?php if (!$tabHubMode): ?>
      <div class="panel-header-row">
        <div class="panel-header-left">
          <span class="panel-count-badge"><?= count($tabListas) ?></span>
        </div>
        <div class="panel-header-actions">
          <div class="quick-search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" class="quick-search-input" data-table="#tabelaCategorias" data-empty="#tabelaCategoriasVazia" placeholder="Pesquisar categoria…">
          </div>
          <a class="btn btn-teal" href="<?= tabUrl('&nova=1') ?>"><i class="bi bi-plus-lg"></i> Nova Categoria</a>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!$tabListas): ?>
        <div class="table-empty-state" id="tabelaCategoriasVazia"><i class="bi bi-inbox"></i>Sem registos.</div>
      <?php else: ?>
      <div class="tbl-cards-wrap" id="tabelaCategorias">
        <?php foreach ($tabListas as $row): ?>
          <div class="tbl-card">
            <div class="tbl-card-top">
              <div class="tbl-card-nome"><?= htmlspecialchars($row['nome']) ?></div>
              <div class="tbl-card-actions">
                <a class="btn btn-yellow" href="<?= tabUrl('&edit=' . (int)$row['id']) ?>" title="Editar" aria-label="Editar"><i class="bi bi-pencil"></i></a>
                <form method="post" style="display:inline-block;" onsubmit="return nvConfirmar(this, 'Eliminar esta categoria? Esta ação é irreversível.');">
                  <input type="hidden" name="form_type" value="eliminar_categoria">
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <button type="submit" class="btn btn-red" title="Eliminar" aria-label="Eliminar"><i class="bi bi-trash3"></i></button>
                </form>
              </div>
            </div>
            <div class="tbl-card-meta">ID #<?= (int)$row['id'] ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php paginacaoTabela('categorias', $tabPaginas, $tabPag); ?>
  <?php endif; ?>
  <?php endif; /* fim do guard de admin (categorias) */ ?>

