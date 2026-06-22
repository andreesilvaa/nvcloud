<?php require_once __DIR__ . '/tabelas_logic.php'; ?>

  <?php if (!utilizadorEhAdmin()): ?>
    <div class="alerta-erro">Não tens permissão para gerir parceiros. Contacta um administrador.</div>
  <?php else: ?>

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
      <a class="btn btn-yellow" href="app.php?page=parceiros" onclick="nvVoltar(event)">← Voltar à lista de parceiros</a>
    </div>

  <?php elseif (isset($_GET['nova']) || $tabEdit): ?>
    <h1 class="section-title"><i class="bi bi-people" style="color:#c9a14a; margin-right:8px;"></i><?= $tabEdit ? 'Editar Parceiro' : 'Novo Parceiro' ?></h1>
    <div class="panel" style="max-width:760px;">
      <form method="post" autocomplete="off">
        <input type="hidden" name="form_type" value="guardar_parceiro">
        <?php if ($tabEdit): ?><input type="hidden" name="id" value="<?= (int)$tabEdit['id'] ?>"><?php endif; ?>

        <div style="margin-bottom:18px;"><label>Empresa</label>
          <label><input type="text" name="empresa" required value="<?= htmlspecialchars($tabEdit['empresa'] ?? '') ?>"></label></div>
        <div style="margin-bottom:24px;"><label>Morada</label>
          <label><input type="text" name="morada" value="<?= htmlspecialchars($tabEdit['morada'] ?? '') ?>"></label></div>

        <div class="form-grid" style="gap:28px;">
          <div class="parceiro-contacto-col">
            <h4 style="margin:0 0 14px; font-size:14px; color:#c9a14a; text-transform:uppercase; letter-spacing:.04em;">
              <i class="bi bi-person-circle" style="margin-right:6px;"></i>1º Contacto
            </h4>
            <div style="margin-bottom:14px;"><label>Nome</label>
              <label><input type="text" name="contato1_nome" value="<?= htmlspecialchars($tabEdit['contato1_nome'] ?? '') ?>"></label></div>
            <div style="margin-bottom:14px;"><label>Email</label>
              <label><input type="email" name="contato1_email" value="<?= htmlspecialchars($tabEdit['contato1_email'] ?? '') ?>"></label></div>
            <div style="margin-bottom:0;"><label>Telefone</label>
              <label><input type="text" name="contato1_telefone" value="<?= htmlspecialchars($tabEdit['contato1_telefone'] ?? '') ?>"></label></div>
          </div>

          <div class="parceiro-contacto-col">
            <h4 style="margin:0 0 14px; font-size:14px; color:#c9a14a; text-transform:uppercase; letter-spacing:.04em;">
              <i class="bi bi-person-circle" style="margin-right:6px;"></i>2º Contacto
            </h4>
            <div style="margin-bottom:14px;"><label>Nome</label>
              <label><input type="text" name="contato2_nome" value="<?= htmlspecialchars($tabEdit['contato2_nome'] ?? '') ?>"></label></div>
            <div style="margin-bottom:14px;"><label>Email</label>
              <label><input type="email" name="contato2_email" value="<?= htmlspecialchars($tabEdit['contato2_email'] ?? '') ?>"></label></div>
            <div style="margin-bottom:0;"><label>Telefone</label>
              <label><input type="text" name="contato2_telefone" value="<?= htmlspecialchars($tabEdit['contato2_telefone'] ?? '') ?>"></label></div>
          </div>
        </div>

        <div style="margin-top:26px; display:flex; gap:10px;">
          <button type="submit" class="btn btn-teal"><?= $tabEdit ? 'Atualizar' : 'Guardar' ?></button>
          <a class="btn btn-yellow" href="app.php?page=parceiros" onclick="nvVoltar(event)">← Voltar à lista</a>
        </div>
      </form>
    </div>

  <?php else: ?>
    <?php
      // Célula compacta com nome + email + telefone de um contacto, sempre visível
      // (substitui o antigo botão "Mostrar detalhes" que obrigava a scroll lateral).
      $contactoParceiroCelula = function (?string $nome, ?string $email, ?string $tel): string {
          $nome = trim((string)$nome); $email = trim((string)$email); $tel = trim((string)$tel);
          if ($nome === '' && $email === '' && $tel === '') {
              return '<span style="color:#d1d5db;">—</span>';
          }
          $linhas = [];
          if ($nome !== '')  { $linhas[] = '<strong>' . htmlspecialchars($nome) . '</strong>'; }
          if ($email !== '') { $linhas[] = '<span style="color:#6b7280;"><i class="bi bi-envelope" style="margin-right:4px;"></i>' . htmlspecialchars($email) . '</span>'; }
          if ($tel !== '')   { $linhas[] = '<span style="color:#6b7280;"><i class="bi bi-telephone" style="margin-right:4px;"></i>' . htmlspecialchars($tel) . '</span>'; }
          return '<div style="font-size:12.5px; line-height:1.6;">' . implode('<br>', $linhas) . '</div>';
      };
    ?>
    <h1 class="section-title"><i class="bi bi-people" style="color:#c9a14a; margin-right:8px;"></i>Lista de Parceiros</h1>
    <div class="panel">
      <div class="panel-header-row">
        <div class="panel-header-left">
          <span class="panel-count-badge"><?= count($tabListas) ?></span>
        </div>
        <div class="panel-header-actions">
          <div class="quick-search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" class="quick-search-input" data-table="#tabelaParceiros" data-empty="#tabelaParceirosVazia" placeholder="Pesquisar parceiro ou contacto…">
          </div>
          <a class="btn btn-teal" href="app.php?page=parceiros&nova=1"><i class="bi bi-plus-lg"></i> Novo Parceiro</a>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table" id="tabelaParceiros">
          <thead><tr>
            <th style="width:90px;">ID</th>
            <th>Parceiro</th>
            <th>1º Contacto</th>
            <th>2º Contacto</th>
            <th style="width:70px;">Ações</th>
          </tr></thead>
          <tbody>
            <?php foreach ($tabListas as $row): ?>
              <tr>
                <td>#<?= (int)$row['id'] ?></td>
                <td><strong><?= htmlspecialchars($row['empresa']) ?></strong></td>
                <td><?= $contactoParceiroCelula($row['contato1_nome'] ?? '', $row['contato1_email'] ?? '', $row['contato1_telefone'] ?? '') ?></td>
                <td><?= $contactoParceiroCelula($row['contato2_nome'] ?? '', $row['contato2_email'] ?? '', $row['contato2_telefone'] ?? '') ?></td>
                <td class="actions">
                  <a class="btn btn-blue" href="app.php?page=parceiros&ver=<?= (int)$row['id'] ?>" title="Ver detalhes" aria-label="Ver detalhes"><i class="bi bi-eye"></i></a>
                  <a class="btn btn-yellow" href="app.php?page=parceiros&edit=<?= (int)$row['id'] ?>" title="Editar" aria-label="Editar"><i class="bi bi-pencil"></i></a>
                  <form method="post" style="display:inline-block;" onsubmit="return nvConfirmar(this, 'Eliminar este Parceiro? Esta ação é irreversível.');">
                    <input type="hidden" name="form_type" value="eliminar_parceiro">
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <button type="submit" class="btn btn-red" title="Eliminar" aria-label="Eliminar"><i class="bi bi-trash3"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$tabListas): ?><tr id="tabelaParceirosVazia" data-no-filter><td colspan="5" class="table-empty-state"><i class="bi bi-inbox"></i>Sem registos.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php paginacaoTabela('parceiros', $tabPaginas, $tabPag); ?>
  <?php endif; ?>
  <?php endif; /* fim do guard de admin (parceiros) */ ?>

