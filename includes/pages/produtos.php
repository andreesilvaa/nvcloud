<?php require_once __DIR__ . '/tabelas_logic.php'; ?>

  <?php if (!utilizadorEhAdmin()): ?>
    <div class="alerta-erro">Não tens permissão para gerir produtos. Contacta um administrador.</div>
  <?php else: ?>

  <?php if (isset($_GET['nova']) || $tabEdit): ?>
    <h1 class="section-title"><?= $tabEdit ? 'Editar Produto' : 'Novo Produto' ?></h1>
    <div class="panel">
      <form method="post" autocomplete="off">
        <input type="hidden" name="form_type" value="guardar_produto">
        <?php if ($tabEdit): ?><input type="hidden" name="id" value="<?= (int)$tabEdit['id'] ?>"><?php endif; ?>
        <div style="margin-bottom:14px;">
          <label>Produto</label>
          <label><input type="text" name="nome" required value="<?= htmlspecialchars($tabEdit['nome'] ?? '') ?>"></label>
        </div>
        <div style="margin-bottom:14px;">
          <label>Categoria</label>
          <label><select name="categoria_id">
            <option value="">— Sem categoria —</option>
            <?php foreach ($listaCategorias as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= ((int)($tabEdit['categoria_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['nome']) ?></option>
            <?php endforeach; ?>
          </select></label>
        </div>
        <button type="submit" class="btn btn-teal"><?= $tabEdit ? 'Atualizar' : 'Guardar' ?></button>
        <a class="btn btn-yellow" href="app.php?page=produtos" onclick="nvVoltar(event)">← Voltar à lista</a>
      </form>
    </div>
  <?php else: ?>
    <h1 class="section-title"><i class="bi bi-tag" style="color:#c9a14a; margin-right:8px;"></i>Lista de Produtos</h1>
    <div class="panel">
      <div class="panel-header-row">
        <div class="panel-header-left">
          <span class="panel-count-badge"><?= count($tabListas) ?></span>
        </div>
        <div class="panel-header-actions">
          <div class="quick-search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" class="quick-search-input" data-table="#tabelaProdutos" data-empty="#tabelaProdutosVazia" placeholder="Pesquisar produto ou categoria…">
          </div>
          <a class="btn btn-teal" href="app.php?page=produtos&nova=1"><i class="bi bi-plus-lg"></i> Novo Produto</a>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table" id="tabelaProdutos">
          <thead><tr><th style="width:90px;">ID</th><th>Produto</th><th>Categoria</th><th style="width:70px;">Ações</th></tr></thead>
          <tbody>
            <?php foreach ($tabListas as $row): ?>
              <tr>
                <td>#<?= (int)$row['id'] ?></td>
                <td><strong><?= htmlspecialchars($row['nome']) ?></strong></td>
                <td><?= htmlspecialchars($row['categoria_nome'] ?? '—') ?></td>
                <td class="actions">
                  <a class="btn btn-yellow" href="app.php?page=produtos&edit=<?= (int)$row['id'] ?>" title="Editar" aria-label="Editar"><i class="bi bi-pencil"></i></a>
                  <form method="post" style="display:inline-block;" onsubmit="return nvConfirmar(this, 'Eliminar este produto? Esta ação é irreversível.');">
                    <input type="hidden" name="form_type" value="eliminar_produto">
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <button type="submit" class="btn btn-red" title="Eliminar" aria-label="Eliminar"><i class="bi bi-trash3"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$tabListas): ?><tr id="tabelaProdutosVazia" data-no-filter><td colspan="4" class="table-empty-state"><i class="bi bi-inbox"></i>Sem registos.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php paginacaoTabela('produtos', $tabPaginas, $tabPag); ?>
  <?php endif; ?>
  <?php endif; /* fim do guard de admin (produtos) */ ?>

