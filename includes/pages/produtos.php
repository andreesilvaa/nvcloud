<?php require_once __DIR__ . '/tabelas_logic.php'; ?>

  <?php if (!utilizadorEhAdmin()): ?>
    <div class="alerta-erro">Não tens permissão para gerir produtos. Contacta um administrador.</div>
  <?php else: ?>

  <?php if (!empty($_SESSION['mensagem_erro'])): ?>
    <div class="alerta-erro"><?= htmlspecialchars($_SESSION['mensagem_erro']) ?></div>
  <?php unset($_SESSION['mensagem_erro']); endif; ?>
  <?php if (!empty($_SESSION['mensagem_sucesso'])): ?>
    <div class="alerta-sucesso"><?= htmlspecialchars($_SESSION['mensagem_sucesso']) ?></div>
  <?php unset($_SESSION['mensagem_sucesso']); endif; ?>

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
        <div style="margin-bottom:14px;">
          <label>Fabricante</label>
          <label><select name="fabricante_id">
            <option value="">— Sem fabricante —</option>
            <?php foreach ($listaFabricantes as $f): ?>
              <option value="<?= (int)$f['id'] ?>" <?= ((int)($tabEdit['fabricante_id'] ?? 0) === (int)$f['id']) ? 'selected' : '' ?>><?= htmlspecialchars($f['nome']) ?></option>
            <?php endforeach; ?>
          </select></label>
        </div>
        <button type="submit" class="btn btn-teal"><?= $tabEdit ? 'Atualizar' : 'Guardar' ?></button>
        <a class="btn btn-yellow" href="app.php?page=produtos">← Voltar à lista</a>
      </form>
    </div>
  <?php else: ?>
    <h1 class="section-title">Lista de Produtos</h1>
    <a class="btn btn-teal" href="app.php?page=produtos&nova=1" style="margin-bottom:18px;display:inline-block;">Novo Produto</a>
    <table class="table">
      <thead><tr><th>ID</th><th>Produto</th><th>Categoria</th><th>Fabricante</th><th>Ações</th></tr></thead>
      <tbody>
        <?php foreach ($tabListas as $row): ?>
          <tr>
            <td>#<?= (int)$row['id'] ?></td>
            <td><?= htmlspecialchars($row['nome']) ?></td>
            <td><?= htmlspecialchars($row['categoria_nome'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['fabricante_nome'] ?? '') ?></td>
            <td class="actions">
              <a class="btn btn-yellow" href="app.php?page=produtos&edit=<?= (int)$row['id'] ?>">Editar</a>
              <form method="post" style="display:inline-block;" onsubmit="return nvConfirmar(this, 'Eliminar este produto? Esta ação é irreversível.');">
                <input type="hidden" name="form_type" value="eliminar_produto">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button type="submit" class="btn btn-red">Eliminar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$tabListas): ?><tr><td colspan="5">Sem registos.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?php paginacaoTabela('produtos', $tabPaginas, $tabPag); ?>
  <?php endif; ?>
  <?php endif; /* fim do guard de admin (produtos) */ ?>

