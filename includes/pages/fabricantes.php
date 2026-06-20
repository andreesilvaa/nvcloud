<?php require_once __DIR__ . '/tabelas_logic.php'; ?>

  <?php if (!utilizadorEhAdmin()): ?>
    <div class="alerta-erro">Não tens permissão para gerir fabricantes. Contacta um administrador.</div>
  <?php else: ?>

  <?php if (isset($_GET['nova']) || $tabEdit): ?>
    <h1 class="section-title"><?= $tabEdit ? 'Editar Fabricante' : 'Novo Fabricante' ?></h1>
    <div class="panel">
      <form method="post" autocomplete="off">
        <input type="hidden" name="form_type" value="guardar_fabricante">
        <?php if ($tabEdit): ?><input type="hidden" name="id" value="<?= (int)$tabEdit['id'] ?>"><?php endif; ?>
        <div style="margin-bottom:14px;">
          <label>Fabricante</label>
          <label><input type="text" name="nome" required value="<?= htmlspecialchars($tabEdit['nome'] ?? '') ?>"></label>
        </div>
        <button type="submit" class="btn btn-teal"><?= $tabEdit ? 'Atualizar' : 'Guardar' ?></button>
        <a class="btn btn-yellow" href="app.php?page=fabricantes" onclick="nvVoltar(event)">← Voltar à lista</a>
      </form>
    </div>
  <?php else: ?>
    <h1 class="section-title">Lista de Fabricantes</h1>
    <a class="btn btn-teal" href="app.php?page=fabricantes&nova=1" style="margin-bottom:18px;display:inline-block;">Novo Fabricante</a>
    <table class="table">
      <thead><tr><th>ID</th><th>Fabricante</th><th>Ações</th></tr></thead>
      <tbody>
        <?php foreach ($tabListas as $row): ?>
          <tr>
            <td>#<?= (int)$row['id'] ?></td>
            <td><?= htmlspecialchars($row['nome']) ?></td>
            <td class="actions">
              <a class="btn btn-yellow" href="app.php?page=fabricantes&edit=<?= (int)$row['id'] ?>">Editar</a>
              <form method="post" style="display:inline-block;" onsubmit="return nvConfirmar(this, 'Eliminar este fabricante? Esta ação é irreversível.');">
                <input type="hidden" name="form_type" value="eliminar_fabricante">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button type="submit" class="btn btn-red">Eliminar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$tabListas): ?><tr><td colspan="3">Sem registos.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?php paginacaoTabela('fabricantes', $tabPaginas, $tabPag); ?>
  <?php endif; ?>
  <?php endif; /* fim do guard de admin (fabricantes) */ ?>

