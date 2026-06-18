<?php require_once __DIR__ . '/tabelas_logic.php'; ?>

  <?php if (!utilizadorEhAdmin()): ?>
    <div class="alerta-erro">Não tens permissão para gerir estados. Contacta um administrador.</div>
  <?php else: ?>

  <?php if (!empty($_SESSION['mensagem_erro'])): ?>
    <div class="alerta-erro"><?= htmlspecialchars($_SESSION['mensagem_erro']) ?></div>
  <?php unset($_SESSION['mensagem_erro']); endif; ?>
  <?php if (!empty($_SESSION['mensagem_sucesso'])): ?>
    <div class="alerta-sucesso"><?= htmlspecialchars($_SESSION['mensagem_sucesso']) ?></div>
  <?php unset($_SESSION['mensagem_sucesso']); endif; ?>

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
        <a class="btn btn-yellow" href="app.php?page=estados">← Voltar à lista</a>
      </form>
    </div>
  <?php else: ?>
    <h1 class="section-title">Lista dos Estados</h1>
    <a class="btn btn-teal" href="app.php?page=estados&nova=1" style="margin-bottom:18px;display:inline-block;">Novo Estado</a>
    <table class="table">
      <thead><tr><th>ID</th><th>Estado</th><th>Descrição</th><th>Ações</th></tr></thead>
      <tbody>
        <?php foreach ($tabListas as $row): ?>
          <tr>
            <td>#<?= (int)$row['id'] ?></td>
            <td><?= htmlspecialchars($row['nome']) ?></td>
            <td><?= htmlspecialchars($row['descricao'] ?? '') ?></td>
            <td class="actions">
              <a class="btn btn-yellow" href="app.php?page=estados&edit=<?= (int)$row['id'] ?>">Editar</a>
              <form method="post" style="display:inline-block;" onsubmit="return nvConfirmar(this, 'Eliminar esta estado? Esta ação é irreversível.');">
                <input type="hidden" name="form_type" value="eliminar_estado">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button type="submit" class="btn btn-red">Eliminar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$tabListas): ?><tr><td colspan="4">Sem registos.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?php paginacaoTabela('estados', $tabPaginas, $tabPag); ?>
  <?php endif; ?>
  <?php endif; /* fim do guard de admin (estados) */ ?>

