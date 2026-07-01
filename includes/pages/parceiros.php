<?php require_once __DIR__ . "/tabelas_logic.php"; ?>

  <?php if (!utilizadorEhAdmin()): ?>
    <div class="alerta-erro">Não tens permissão para gerir parceiros. Contacta um administrador.</div>
  <?php
      // Célula compacta com nome + email + telefone de um contacto, sempre visível
      // (substitui o antigo botão "Mostrar detalhes" que obrigava a scroll lateral).
      // Célula compacta com nome + email + telefone de um contacto, sempre visível
      // (substitui o antigo botão "Mostrar detalhes" que obrigava a scroll lateral).
      else: ?>

  <?php if ($parceiroVer): ?>
    <h1 class="section-title">Visualizar informação do Parceiro</h1>
    <div class="panel" style="max-width:520px;">
      <div style="margin-bottom:14px;"><label><strong>Empresa:</strong></label>
        <label><input type="text" value="<?= htmlspecialchars(
            $parceiroVer["empresa"],
        ) ?>" readonly></label></div>
      <div style="margin-bottom:14px;"><label><strong>Morada:</strong></label>
        <label><input type="text" value="<?= htmlspecialchars(
            $parceiroVer["morada"] ?? "",
        ) ?>" readonly></label></div>
      <div style="margin-bottom:14px;"><label><strong>Nome do 1º Contato:</strong></label>
        <label><input type="text" value="<?= htmlspecialchars(
            $parceiroVer["contato1_nome"] ?? "",
        ) ?>" readonly></label></div>
      <div style="margin-bottom:14px;"><label><strong>Email do 1º Contato:</strong></label>
        <label><input type="text" value="<?= htmlspecialchars(
            $parceiroVer["contato1_email"] ?? "",
        ) ?>" readonly></label></div>
      <div style="margin-bottom:14px;"><label><strong>Telefone do 1º Contato:</strong></label>
        <label><input type="text" value="<?= htmlspecialchars(
            $parceiroVer["contato1_telefone"] ?? "",
        ) ?>" readonly></label></div>
      <div style="margin-bottom:14px;"><label><strong>Nome do 2º Contato:</strong></label>
        <label><input type="text" value="<?= htmlspecialchars(
            $parceiroVer["contato2_nome"] ?? "",
        ) ?>" readonly></label></div>
      <div style="margin-bottom:14px;"><label><strong>Email do 2º Contato:</strong></label>
        <label><input type="text" value="<?= htmlspecialchars(
            $parceiroVer["contato2_email"] ?? "",
        ) ?>" readonly></label></div>
      <div style="margin-bottom:18px;"><label><strong>Telefone do 2º Contato:</strong></label>
        <label><input type="text" value="<?= htmlspecialchars(
            $parceiroVer["contato2_telefone"] ?? "",
        ) ?>" readonly></label></div>
      <a class="btn btn-yellow" href="<?= tabUrl() ?>" onclick="nvVoltar(event)">← Voltar à lista de parceiros</a>
    </div>

  <?php elseif (isset($_GET["nova"]) || $tabEdit): ?>
    <h1 class="section-title"><i class="bi bi-people" style="color:#c9a14a; margin-right:8px;"></i><?= $tabEdit
        ? "Editar Parceiro"
        : "Novo Parceiro" ?></h1>
    <div class="panel" style="max-width:760px;">
      <form method="post" autocomplete="off">
        <input type="hidden" name="form_type" value="guardar_parceiro">
        <?php if (
            $tabEdit
        ): ?><input type="hidden" name="id" value="<?= (int) $tabEdit[
    "id"
] ?>"><?php endif; ?>

        <div style="margin-bottom:18px;"><label>Empresa</label>
          <label><input type="text" name="empresa" required value="<?= htmlspecialchars(
              $tabEdit["empresa"] ?? "",
          ) ?>"></label></div>
        <div style="margin-bottom:24px;"><label>Morada</label>
          <label><input type="text" name="morada" value="<?= htmlspecialchars(
              $tabEdit["morada"] ?? "",
          ) ?>"></label></div>

        <div class="form-grid" style="gap:28px;">
          <div class="parceiro-contacto-col">
            <h4 style="margin:0 0 14px; font-size:14px; color:#c9a14a; text-transform:uppercase; letter-spacing:.04em;">
              <i class="bi bi-person-circle" style="margin-right:6px;"></i>1º Contacto
            </h4>
            <div style="margin-bottom:14px;"><label>Nome</label>
              <label><input type="text" name="contato1_nome" value="<?= htmlspecialchars(
                  $tabEdit["contato1_nome"] ?? "",
              ) ?>"></label></div>
            <div style="margin-bottom:14px;"><label>Email</label>
              <label><input type="email" name="contato1_email" value="<?= htmlspecialchars(
                  $tabEdit["contato1_email"] ?? "",
              ) ?>"></label></div>
            <div style="margin-bottom:0;"><label>Telefone</label>
              <label><input type="text" name="contato1_telefone" value="<?= htmlspecialchars(
                  $tabEdit["contato1_telefone"] ?? "",
              ) ?>"></label></div>
          </div>

          <div class="parceiro-contacto-col">
            <h4 style="margin:0 0 14px; font-size:14px; color:#c9a14a; text-transform:uppercase; letter-spacing:.04em;">
              <i class="bi bi-person-circle" style="margin-right:6px;"></i>2º Contacto
            </h4>
            <div style="margin-bottom:14px;"><label>Nome</label>
              <label><input type="text" name="contato2_nome" value="<?= htmlspecialchars(
                  $tabEdit["contato2_nome"] ?? "",
              ) ?>"></label></div>
            <div style="margin-bottom:14px;"><label>Email</label>
              <label><input type="email" name="contato2_email" value="<?= htmlspecialchars(
                  $tabEdit["contato2_email"] ?? "",
              ) ?>"></label></div>
            <div style="margin-bottom:0;"><label>Telefone</label>
              <label><input type="text" name="contato2_telefone" value="<?= htmlspecialchars(
                  $tabEdit["contato2_telefone"] ?? "",
              ) ?>"></label></div>
          </div>
        </div>

        <div style="margin-top:26px; display:flex; gap:10px;">
          <button type="submit" class="btn btn-teal"><?= $tabEdit
              ? "Atualizar"
              : "Guardar" ?></button>
          <a class="btn btn-yellow" href="<?= tabUrl() ?>" onclick="nvVoltar(event)">← Voltar à lista</a>
        </div>
      </form>
    </div>

  <?php else: ?>
    <?php $contactoParceiroCelula = function (array $row): string {
        $partes = [];
        $email1 = trim((string) ($row["contato1_email"] ?? ""));
        $email2 = trim((string) ($row["contato2_email"] ?? ""));
        $tel1 = trim((string) ($row["contato1_telefone"] ?? ""));
        $tel2 = trim((string) ($row["contato2_telefone"] ?? ""));
        $morada = trim((string) ($row["morada"] ?? ""));

        $emails = implode(", ", array_filter([$email1, $email2]));
        $tels = implode(", ", array_filter([$tel1, $tel2]));

        if ($emails === "" && $tels === "" && $morada === "") {
            return '<div class="tbl-card-semcontacto">Sem contacto / sem morada</div>';
        }
        if ($emails !== "") {
            $partes[] =
                '<span><i class="bi bi-envelope"></i> ' .
                htmlspecialchars($emails) .
                "</span>";
        }
        if ($tels !== "") {
            $partes[] =
                '<span><i class="bi bi-telephone"></i> ' .
                htmlspecialchars($tels) .
                "</span>";
        }
        if ($morada !== "") {
            $partes[] =
                '<span><i class="bi bi-geo-alt"></i> ' .
                htmlspecialchars($morada) .
                "</span>";
        }
        return '<div class="tbl-card-contactos">' .
            implode("", $partes) .
            "</div>";
    }; ?>

    <div class="panel">
      <?php if (!$tabHubMode): ?>
      <div class="panel-header-row">
        <div class="panel-header-left">
          <span class="panel-count-badge"><?= count($tabListas) ?></span>
        </div>
        <div class="panel-header-actions">
          <div class="quick-search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" class="quick-search-input" data-table="#tabelaParceiros" data-empty="#tabelaParceirosVazia" placeholder="Pesquisar parceiro ou contacto…">
          </div>
          <a class="btn btn-teal" href="<?= tabUrl('&nova=1') ?>"><i class="bi bi-plus-lg"></i> Novo Parceiro</a>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!$tabListas): ?>
        <div class="table-empty-state" id="tabelaParceirosVazia"><i class="bi bi-inbox"></i>Sem registos.</div>
      <?php else: ?>
      <div class="tbl-cards-wrap" id="tabelaParceiros">
        <?php foreach ($tabListas as $row): ?>
          <?php
            $nContactos = count(array_filter([
                trim((string)($row["contato1_nome"] ?? "")),
                trim((string)($row["contato2_nome"] ?? "")),
            ], fn($v) => $v !== ""));
          ?>
          <div class="tbl-card">
            <div class="tbl-card-top">
              <div class="tbl-card-nome"><?= htmlspecialchars($row["empresa"]) ?></div>
              <div class="tbl-card-actions">
                <a class="btn btn-yellow" href="<?= tabUrl('&edit=' . (int)$row['id']) ?>" title="Editar" aria-label="Editar"><i class="bi bi-pencil"></i></a>
                <form method="post" style="display:inline-block;" onsubmit="return nvConfirmar(this, 'Eliminar este Parceiro? Esta ação é irreversível.');">
                  <input type="hidden" name="form_type" value="eliminar_parceiro">
                  <input type="hidden" name="id" value="<?= (int) $row["id"] ?>">
                  <button type="submit" class="btn btn-red" title="Eliminar" aria-label="Eliminar"><i class="bi bi-trash3"></i></button>
                </form>
              </div>
            </div>
            <div class="tbl-card-meta">
              ID #<?= (int) $row["id"] ?>
              <?php if ($nContactos > 0): ?>
                <span class="tbl-card-badge"><?= $nContactos ?> contacto<?= $nContactos > 1 ? "s" : "" ?></span>
              <?php endif; ?>
            </div>
            <?= $contactoParceiroCelula($row) ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php paginacaoTabela("parceiros", $tabPaginas, $tabPag); ?>
  <?php endif; ?>
  <?php endif; /* fim do guard de admin (parceiros) */ ?>
