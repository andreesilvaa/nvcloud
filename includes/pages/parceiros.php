<?php require_once __DIR__ . '/tabelas_logic.php'; ?>

  <?php if (!utilizadorEhAdmin()): ?>
    <div class="alerta-erro">Não tens permissão para gerir parceiros. Contacta um administrador.</div>
  <?php else: ?>

  <?php if (!empty($_SESSION['mensagem_erro'])): ?>
    <div class="alerta-erro"><?= htmlspecialchars($_SESSION['mensagem_erro']) ?></div>
  <?php unset($_SESSION['mensagem_erro']); endif; ?>
  <?php if (!empty($_SESSION['mensagem_sucesso'])): ?>
    <div class="alerta-sucesso"><?= htmlspecialchars($_SESSION['mensagem_sucesso']) ?></div>
  <?php unset($_SESSION['mensagem_sucesso']); endif; ?>

  <?php if ($parceiroVer): ?>
    <h1 class="section-title">Visualizar informação do Parceiro</h1>
    <div class="panel" style="max-width:520px;">
      <div style="margin-bottom:14px;"><label><strong>Empresa:</strong></label>
        <label><input type="text" value="<?= htmlspecialchars($parceiroVer['empresa']) ?>" readonly></label></div>
      <div style="margin-bottom:14px;"><label><strong>Morada:</strong></label>
        <label><input type="text" value="<?= htmlspecialchars($parceiroVer['morada'] ?? '') ?>" readonly></label></div>
      <div style="margin-bottom:14px;"><label><strong>Nome do 1º Contato:</strong></label>
        <label><input type="text" value="<?= htmlspecialchars($parceiroVer['contato1_nome'] ?? '') ?>" readonly></label></div>
      <div style="margin-bottom:14px;"><label><strong>Email do 1º Contato:</strong></label>
        <label><input type="text" value="<?= htmlspecialchars($parceiroVer['contato1_email'] ?? '') ?>" readonly></label></div>
      <div style="margin-bottom:14px;"><label><strong>Telefone do 1º Contato:</strong></label>
        <label><input type="text" value="<?= htmlspecialchars($parceiroVer['contato1_telefone'] ?? '') ?>" readonly></label></div>
      <div style="margin-bottom:14px;"><label><strong>Nome do 2º Contato:</strong></label>
        <label><input type="text" value="<?= htmlspecialchars($parceiroVer['contato2_nome'] ?? '') ?>" readonly></label></div>
      <div style="margin-bottom:14px;"><label><strong>Email do 2º Contato:</strong></label>
        <label><input type="text" value="<?= htmlspecialchars($parceiroVer['contato2_email'] ?? '') ?>" readonly></label></div>
      <div style="margin-bottom:18px;"><label><strong>Telefone do 2º Contato:</strong></label>
        <label><input type="text" value="<?= htmlspecialchars($parceiroVer['contato2_telefone'] ?? '') ?>" readonly></label></div>
      <a class="btn btn-yellow" href="app.php?page=parceiros">← Voltar à lista de parceiros</a>
    </div>

  <?php elseif (isset($_GET['nova']) || $tabEdit): ?>
    <h1 class="section-title"><?= $tabEdit ? 'Editar Parceiro' : 'Novo Parceiro' ?></h1>
    <div class="panel" style="max-width:520px;">
      <form method="post" autocomplete="off">
        <input type="hidden" name="form_type" value="guardar_parceiro">
        <?php if ($tabEdit): ?><input type="hidden" name="id" value="<?= (int)$tabEdit['id'] ?>"><?php endif; ?>
        <div style="margin-bottom:14px;"><label>Empresa</label>
          <label><input type="text" name="empresa" required value="<?= htmlspecialchars($tabEdit['empresa'] ?? '') ?>"></label></div>
        <div style="margin-bottom:14px;"><label>Morada</label>
          <label><input type="text" name="morada" value="<?= htmlspecialchars($tabEdit['morada'] ?? '') ?>"></label></div>
        <div style="margin-bottom:14px;"><label>Nome do 1º Contato</label>
          <label><input type="text" name="contato1_nome" value="<?= htmlspecialchars($tabEdit['contato1_nome'] ?? '') ?>"></label></div>
        <div style="margin-bottom:14px;"><label>Email do 1º Contato</label>
          <label><input type="email" name="contato1_email" value="<?= htmlspecialchars($tabEdit['contato1_email'] ?? '') ?>"></label></div>
        <div style="margin-bottom:14px;"><label>Telefone do 1º Contato</label>
          <label><input type="text" name="contato1_telefone" value="<?= htmlspecialchars($tabEdit['contato1_telefone'] ?? '') ?>"></label></div>
        <div style="margin-bottom:14px;"><label>Nome do 2º Contato</label>
          <label><input type="text" name="contato2_nome" value="<?= htmlspecialchars($tabEdit['contato2_nome'] ?? '') ?>"></label></div>
        <div style="margin-bottom:14px;"><label>Email do 2º Contato</label>
          <label><input type="email" name="contato2_email" value="<?= htmlspecialchars($tabEdit['contato2_email'] ?? '') ?>"></label></div>
        <div style="margin-bottom:18px;"><label>Telefone do 2º Contato</label>
          <label><input type="text" name="contato2_telefone" value="<?= htmlspecialchars($tabEdit['contato2_telefone'] ?? '') ?>"></label></div>
        <button type="submit" class="btn btn-teal"><?= $tabEdit ? 'Atualizar' : 'Guardar' ?></button>
        <a class="btn btn-yellow" href="app.php?page=parceiros">← Voltar à lista</a>
      </form>
    </div>

  <?php else: ?>
    <?php $det = isset($_GET['det']); ?>
    <h1 class="section-title">Lista de Parceiros</h1>
    <div style="margin-bottom:18px;">
      <a class="btn btn-teal" href="app.php?page=parceiros&nova=1">Novo Parceiro</a>
      <a class="btn btn-grey" href="app.php?page=parceiros<?= $det ? '' : '&det=1' ?>"><?= $det ? 'Ocultar detalhes' : 'Mostrar detalhes' ?></a>
    </div>
    <table class="table">
      <thead><tr>
        <th>ID</th><th>Parceiro</th>
        <th>1º Contato</th><?php if ($det): ?><th>Email</th><?php endif; ?><th>Telm.</th>
        <th>2º Contato</th><?php if ($det): ?><th>Email</th><?php endif; ?><th>Telm.</th>
        <th>Ações</th>
      </tr></thead>
      <tbody>
        <?php foreach ($tabListas as $row): ?>
          <tr>
            <td>#<?= (int)$row['id'] ?></td>
            <td><?= htmlspecialchars($row['empresa']) ?></td>
            <td><?= htmlspecialchars($row['contato1_nome'] ?? '') ?></td>
            <?php if ($det): ?><td><?= htmlspecialchars($row['contato1_email'] ?? '') ?></td><?php endif; ?>
            <td><?= htmlspecialchars($row['contato1_telefone'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['contato2_nome'] ?? '') ?></td>
            <?php if ($det): ?><td><?= htmlspecialchars($row['contato2_email'] ?? '') ?></td><?php endif; ?>
            <td><?= htmlspecialchars($row['contato2_telefone'] ?? '') ?></td>
            <td class="actions">
              <a class="btn btn-blue" href="app.php?page=parceiros&ver=<?= (int)$row['id'] ?>">Ver +</a>
              <a class="btn btn-yellow" href="app.php?page=parceiros&edit=<?= (int)$row['id'] ?>">Editar</a>
              <form method="post" style="display:inline-block;" onsubmit="return nvConfirmar(this, 'Eliminar este Parceiro? Esta ação é irreversível.');">
                <input type="hidden" name="form_type" value="eliminar_parceiro">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button type="submit" class="btn btn-red">Eliminar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$tabListas): ?><tr><td colspan="<?= $det ? 9 : 7 ?>">Sem registos.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?php paginacaoTabela('parceiros', $tabPaginas, $tabPag, $det ? '&det=1' : ''); ?>
  <?php endif; ?>
  <?php endif; /* fim do guard de admin (parceiros) */ ?>

