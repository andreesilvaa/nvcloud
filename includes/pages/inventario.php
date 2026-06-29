<?php
$invSelfUrl = $_SERVER["REQUEST_URI"] ?? "app.php?page=inventario";

// ============================================================
// 10. PROCESSAMENTO POST: ELIMINAR PEÇA
// ============================================================

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["form_type"] ?? "") === "eliminar_peca"
) {
    $deleteId = isset($_POST["id"]) ? (int) $_POST["id"] : 0;

    if ($deleteId <= 0) {
        $_SESSION["mensagem_erro"] = "ID inválido para eliminar.";
        $voltarDestino = (isset($_POST["voltar"]) && str_starts_with($_POST["voltar"], "app.php?page=inventario"))
            ? $_POST["voltar"]
            : $invSelfUrl;
        header("Location: " . $voltarDestino);
        exit();
    }

    $stmtPeca = $pdo->prepare("SELECT * FROM pecas WHERE id = ?");
    $stmtPeca->execute([$deleteId]);
    $peca = $stmtPeca->fetch();

    if (!$peca) {
        $_SESSION["mensagem_erro"] = "Peça não encontrada para eliminar.";
        $voltarDestino = (isset($_POST["voltar"]) && str_starts_with($_POST["voltar"], "app.php?page=inventario"))
            ? $_POST["voltar"]
            : $invSelfUrl;
        header("Location: " . $voltarDestino);
        exit();
    }

    $utilizador = $_SESSION["user_nome"] ?? "Sistema";

    $stmtHistorico = $pdo->prepare("
        INSERT INTO historico (peca_id, campo, antes, depois, utilizador, data_alteracao)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");

    $stmtHistorico->execute([
        $deleteId,
        "eliminação",
        "Peça existente",
        "Peça eliminada",
        $utilizador,
    ]);

    $campos = [
        "categoria",
        "produto",
        "sn",
        "cod_barras",
        "parceiro",
        "estado",
    ];

    foreach ($campos as $campo) {
        $stmtHistorico->execute([
            $deleteId,
            $campo,
            $peca[$campo] ?? "",
            "",
            $utilizador,
        ]);
    }

    $stmtDelete = $pdo->prepare("DELETE FROM pecas WHERE id = ?");
    $stmtDelete->execute([$deleteId]);

    $_SESSION["mensagem_sucesso"] = "Peça eliminada com sucesso.";
    $voltarDestino = (isset($_POST["voltar"]) && str_starts_with($_POST["voltar"], "app.php?page=inventario"))
        ? $_POST["voltar"]
        : $invSelfUrl;
    header("Location: " . $voltarDestino);
    exit();
}

$filters = [
    "categoria" => $_GET["categoria"] ?? "",
    "estado" => $_GET["estado"] ?? "",
    "parceiro" => $_GET["parceiro"] ?? "",
    "sn" => $_GET["sn"] ?? "",
    "produto" => $_GET["produto"] ?? "",
];

$where = [];
$params = [];
if ($filters["categoria"]) {
    $where[] = "categoria = ?";
    $params[] = $filters["categoria"];
}
if ($filters["estado"]) {
    $where[] = "estado = ?";
    $params[] = $filters["estado"];
}
if ($filters["parceiro"]) {
    $where[] = "parceiro = ?";
    $params[] = $filters["parceiro"];
}
if ($filters["sn"]) {
    $where[] = "sn LIKE ?";
    $params[] = "%" . $filters["sn"] . "%";
}
if ($filters["produto"]) {
    $where[] = "produto = ?";
    $params[] = $filters["produto"];
}

$whereSql = $where ? " WHERE " . implode(" AND ", $where) : "";

// total para calcular páginas
$stmtCntInv = $pdo->prepare("SELECT COUNT(*) FROM pecas" . $whereSql);
$stmtCntInv->execute($params);
$invTotal = (int) $stmtCntInv->fetchColumn();

$invPorPag = 50;
$invPag = max(1, (int) ($_GET["p"] ?? 1));
$invPaginas = max(1, (int) ceil($invTotal / $invPorPag));
if ($invPag > $invPaginas) {
    $invPag = $invPaginas;
}
$invOffset = ($invPag - 1) * $invPorPag;

$sql =
    "SELECT * FROM pecas" .
    $whereSql .
    " ORDER BY id DESC LIMIT $invPorPag OFFSET $invOffset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pecas = $stmt->fetchAll();

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["form_type"] ?? "") === "lote_estado"
) {
    if (($_POST["csrf"] ?? "") !== ($_SESSION["csrf_token"] ?? "")) {
        exit("Ação inválida.");
    }
    $ids = array_map("intval", $_POST["ids"] ?? []);
    $novo = trim($_POST["novo_estado"] ?? "");
    $utilizador = $_SESSION["user_nome"] ?? "Sistema";

    if ($ids && in_array($novo, $estados, true)) {
        $upd = $pdo->prepare(
            "UPDATE pecas SET estado = ?, estado_desde = NOW() WHERE id = ?",
        );
        $hist = $pdo->prepare(
            "INSERT INTO historico (peca_id,campo,antes,depois,utilizador,data_alteracao) VALUES (?, 'estado', ?, ?, ?, NOW())",
        );
        $sel = $pdo->prepare("SELECT estado FROM pecas WHERE id = ?");
        $pdo->beginTransaction();
        foreach ($ids as $id) {
            $sel->execute([$id]);
            $ant = (string) $sel->fetchColumn();
            if ($ant !== $novo) {
                $upd->execute([$novo, $id]);
                $hist->execute([$id, $ant, $novo, $utilizador]);
            }
        }
        $pdo->commit();
        $_SESSION["mensagem_sucesso"] = count($ids) . " peça(s) atualizada(s).";
    }
    header("Location: " . $invSelfUrl);
    exit();
}
?>
  <style>
    .inv-toolbar{
      background:#fff;
      border:1px solid #e5e9ef;
      border-radius:12px;
      padding:12px 14px;
      margin-bottom:16px;
    }
    .inv-toolbar-form{
      display:flex;
      flex-direction:column;
      gap:10px;
    }
    .inv-toolbar-filtros{
      display:flex;
      flex-wrap:wrap;
      align-items:flex-end;
      gap:10px;
    }
    .inv-filtro{
      flex:1 1 0;
      min-width:118px;
      display:flex;
      flex-direction:column;
    }
    .inv-filtro label{
      font-size:10.5px;
      font-weight:700;
      text-transform:uppercase;
      letter-spacing:.05em;
      color:#9ca3af;
      margin:0 0 5px;
    }
    .inv-filtro input,
    .inv-filtro select{
      height:38px;
      border:1px solid #e5e9ef;
      background:#f8fafc;
      border-radius:9px;
      font-size:13.5px;
      padding:0 10px;
      box-sizing:border-box;
      width:100%;
    }
    .inv-filtro input:focus,
    .inv-filtro select:focus{
      background:#fff;
      border-color:#c9a14a;
      outline:none;
    }
    .inv-toolbar-bar{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      flex-wrap:wrap;
    }
    .inv-toolbar-bar-right{
      display:flex;
      align-items:center;
      gap:8px;
      margin-left:auto;
    }
    .inv-toolbar .btn,
    .inv-toolbar .actions-dd > summary{
      height:38px;
      padding:0 13px;
      font-size:13px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:5px;
      box-sizing:border-box;
      white-space:nowrap;
    }
    @media (max-width:900px){
      .inv-filtro{ flex:1 1 calc(50% - 10px); min-width:140px; }
    }
    @media (max-width:560px){
      .inv-toolbar-bar{ flex-direction:column; align-items:stretch; }
      .inv-toolbar-bar-right{ margin-left:0; justify-content:flex-end; }
    }
  </style>
  <div class="inv-toolbar">
    <form method="get" class="inv-toolbar-form">
      <input type="hidden" name="page" value="inventario">

      <div class="inv-toolbar-filtros">
        <div class="inv-filtro">
          <label>Tipo</label>
          <select name="categoria" id="inv-categoria">
            <option value="">-- Todos --</option>
            <?php foreach ($categorias as $cat): ?>
              <option value="<?= $cat ?>" <?= $filters["categoria"] === $cat
    ? "selected"
    : "" ?>><?= $cat ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="inv-filtro">
          <label>Nome da peça</label>
          <select name="produto" id="inv-produto">
            <option value="">-- Todos --</option>
          </select>
        </div>

        <div class="inv-filtro">
          <label>Estado</label>
          <select name="estado">
            <option value="">-- Todos --</option>
            <?php foreach ($estados as $estado): ?>
              <option value="<?= $estado ?>" <?= $filters["estado"] === $estado
    ? "selected"
    : "" ?>><?= $estado ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="inv-filtro">
          <label>Parceiro</label>
          <select name="parceiro">
            <option value="">-- Todos --</option>
            <?php foreach ($parceiros as $parceiro): ?>
              <option value="<?= $parceiro ?>" <?= $filters["parceiro"] ===
$parceiro
    ? "selected"
    : "" ?>><?= $parceiro ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="inv-filtro">
          <label>SN (N.º de série)</label>
          <input type="text" name="sn" value="<?= htmlspecialchars(
              $filters["sn"],
          ) ?>" placeholder="ex.: ABC12345">
        </div>
      </div>

      <script>
      document.addEventListener('DOMContentLoaded', function () {
        const catSel = document.getElementById('inv-categoria');
        const prodSel = document.getElementById('inv-produto');
        if (!catSel || !prodSel) return;

        const catalogo = <?= json_encode(
            $catalogoProdutos,
            JSON_UNESCAPED_UNICODE,
        ) ?>;
        const produtoAtual = <?= json_encode(
            $filters["produto"],
            JSON_UNESCAPED_UNICODE,
        ) ?>;

        function atualizarProdutosFiltro() {
          const categoria = catSel.value;
          const lista = catalogo[categoria] || [];
          const valorSelecionado = prodSel.value || produtoAtual;

          prodSel.innerHTML = '<option value="">-- Todos --</option>';
          lista.forEach(function (produto) {
            const opt = document.createElement('option');
            opt.value = produto;
            opt.textContent = produto;
            if (produto === valorSelecionado) opt.selected = true;
            prodSel.appendChild(opt);
          });
        }

        catSel.addEventListener('change', atualizarProdutosFiltro);
        atualizarProdutosFiltro();
      });
      </script>

      <div class="inv-toolbar-bar">
        <details class="actions-dd">
          <summary class="btn btn-teal"><i class="bi bi-lightning-charge"></i> Ações <i class="bi bi-chevron-down"></i></summary>
          <div class="actions-dd-menu">
            <a class="is-primary" href="app.php?page=nova_peca"><i class="bi bi-plus-lg"></i> Adicionar Peça</a>
            <a href="app.php?page=qrs"><i class="bi bi-upc-scan"></i> Ler (QR / código)</a>
            <div class="dd-sep"></div>
            <a href="exportar_inventario_csv.php"><i class="bi bi-download"></i> Exportar CSV</a>
          </div>
        </details>
        <div class="inv-toolbar-bar-right">
          <a class="btn btn-grey" href="app.php?page=inventario">Limpar</a>
          <button class="btn btn-blue" type="submit"><i class="bi bi-search"></i> Filtrar</button>
        </div>
      </div>
    </form>
  </div>

  <div class="table-responsive mv-table-wrap">
  <table class="table">
    <thead><tr>
      <th>ID</th>
      <th>Categoria</th>
      <th>Produto</th>
      <th>SN</th>
      <th>PAT</th>
      <th>Parceiro</th>
      <th>Estado</th>
      <th class="actions">Ações</th>
    </tr>
    </thead>

  <tbody>
    <?php foreach ($pecas as $p): ?>
      <tr>
        <td><?= $p["id"] ?></td>
        <td><?= htmlspecialchars($p["categoria"]) ?></td>
        <td><?= htmlspecialchars($p["produto"]) ?></td>
        <td><?= htmlspecialchars($p["sn"]) ?></td>
        <td>N/A</td>
        <td><?= htmlspecialchars($p["parceiro"]) ?></td>
        <td><?= estadoBolha($p["estado"]) ?></td>
        <td class="actions">
          <a class="btn btn-yellow" href="app.php?page=nova_peca&edit=<?= $p[
              "id"
          ] ?>&voltar=<?= urlencode($invSelfUrl) ?>" onclick="nvGuardarScrollInv()" title="Editar" aria-label="Editar"><i class="bi bi-pencil"></i></a>
          <form method="post" style="display:inline-block;" onsubmit="nvGuardarScrollInv(); return nvConfirmar(this, 'Eliminar esta peça? Esta ação é irreversível.');">
            <input type="hidden" name="form_type" value="eliminar_peca">
            <input type="hidden" name="id" value="<?= (int) $p["id"] ?>">
            <input type="hidden" name="voltar" value="<?= htmlspecialchars($invSelfUrl) ?>">
            <button type="submit" class="btn btn-red" title="Eliminar" aria-label="Eliminar"><i class="bi bi-trash3"></i></button>
          </form>
          <a class="btn btn-grey" href="app.php?page=historico&id=<?= $p[
              "id"
          ] ?>&voltar=<?= urlencode($invSelfUrl) ?>" onclick="nvGuardarScrollInv()" title="Histórico" aria-label="Histórico"><i class="bi bi-clock-history"></i></a>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
  </table>
  </div><!-- /.mv-table-wrap -->

<!-- ── Inventário · Cards mobile (≤640px) ── -->
<div class="mv-cards">
<?php foreach ($pecas as $p): ?>
    <div class="mv-card">
        <div class="mv-card-header">
            <div>
                <div class="mv-card-title"><?= htmlspecialchars(
                    $p["produto"],
                ) ?:
                    '<span style="color:#9ca3af;">Sem nome</span>' ?></div>
                <?php if (
                    $p["sn"]
                ): ?><div class="mv-card-sub">SN: <?= htmlspecialchars(
    $p["sn"],
) ?></div><?php endif; ?>
            </div>
            <?= estadoBolha($p["estado"]) ?>
        </div>
        <?php if ($p["categoria"]): ?>
        <div class="mv-card-row">
            <span class="mv-card-row-label">Categoria</span>
            <span class="mv-card-row-val"><?= htmlspecialchars(
                $p["categoria"],
            ) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($p["parceiro"]): ?>
        <div class="mv-card-row">
            <span class="mv-card-row-label">Parceiro</span>
            <span class="mv-card-row-val"><?= htmlspecialchars(
                $p["parceiro"],
            ) ?></span>
        </div>
        <?php endif; ?>
        <div class="mv-card-row">
            <span class="mv-card-row-label">ID</span>
            <span class="mv-card-row-val" style="color:#9ca3af;"><?= (int) $p[
                "id"
            ] ?></span>
        </div>
        <div class="mv-card-footer">
            <a class="btn btn-yellow" href="app.php?page=nova_peca&edit=<?= $p[
                "id"
            ] ?>&voltar=<?= urlencode($invSelfUrl) ?>" onclick="nvGuardarScrollInv()" aria-label="Editar"><i class="bi bi-pencil"></i></a>
            <form method="post" style="display:inline;" onsubmit="nvGuardarScrollInv(); return nvConfirmar(this, 'Eliminar esta peça? Esta ação é irreversível.');"><input type="hidden" name="form_type" value="eliminar_peca"><input type="hidden" name="id" value="<?= (int) $p[
                "id"
            ] ?>"><input type="hidden" name="voltar" value="<?= htmlspecialchars($invSelfUrl) ?>"><button type="submit" class="btn btn-red" aria-label="Eliminar"><i class="bi bi-trash3"></i></button></form>
            <a class="btn btn-grey" href="app.php?page=historico&id=<?= $p[
                "id"
            ] ?>&voltar=<?= urlencode($invSelfUrl) ?>" onclick="nvGuardarScrollInv()" aria-label="Histórico"><i class="bi bi-clock-history"></i></a>
        </div>
    </div>
<?php endforeach; ?>
<?php if (empty($pecas)): ?>
    <div class="mv-cards-empty"><i class="bi bi-inbox"></i>Nenhuma peça encontrada.</div>
<?php endif; ?>
</div>

    <?php if ($invPaginas > 1):
        // preserva os filtros atuais na navegação

        $qs = $_GET;
        unset($qs["p"]);
        $base =
            "app.php?" .
            http_build_query(array_merge($qs, ["page" => "inventario"]));
        ?>
        <style>
        .inv-pagination{
          margin-top:18px;
          display:flex;
          align-items:center;
          justify-content:center;
          gap:14px;
          flex-wrap:wrap;
        }
        .inv-pagination .pg-btn{
          display:inline-flex;
          align-items:center;
          gap:6px;
          height:40px;
          padding:0 18px;
          border-radius:10px;
          border:1px solid #e5e9ef;
          background:#fff;
          color:#374151;
          font-size:13.5px;
          font-weight:600;
          text-decoration:none;
          transition:background .15s,border-color .15s,color .15s;
        }
        .inv-pagination .pg-btn:hover{
          background:#fdf8ee;
          border-color:#c9a14a;
          color:#c9a14a;
        }
        .inv-pagination .pg-btn.is-disabled{
          opacity:.4;
          pointer-events:none;
        }
        .inv-pagination .pg-info{
          font-size:13px;
          color:#6b7280;
          background:#f8fafc;
          border:1px solid #e5e9ef;
          border-radius:10px;
          padding:9px 16px;
          font-weight:600;
        }
        .inv-pagination .pg-info strong{ color:#1f2937; }
        @media (max-width:560px){
          .inv-pagination{ flex-direction:column; }
        }
        </style>
        <div class="inv-pagination">
            <?php if ($invPag > 1): ?>
                <a class="pg-btn" href="<?= e($base) ?>&p=<?= $invPag -
    1 ?>"><i class="bi bi-chevron-left"></i> Anterior</a>
            <?php else: ?>
                <span class="pg-btn is-disabled"><i class="bi bi-chevron-left"></i> Anterior</span>
            <?php endif; ?>
            <span class="pg-info">Página <strong><?= $invPag ?></strong> de <strong><?= $invPaginas ?></strong> · <strong><?= $invTotal ?></strong> peças</span>
            <?php if ($invPag < $invPaginas): ?>
                <a class="pg-btn" href="<?= e($base) ?>&p=<?= $invPag +
    1 ?>">Seguinte <i class="bi bi-chevron-right"></i></a>
            <?php else: ?>
                <span class="pg-btn is-disabled">Seguinte <i class="bi bi-chevron-right"></i></span>
            <?php endif; ?>
        </div>
    <?php
    endif; ?>

    <script>
    function nvGuardarScrollInv(){
      try { sessionStorage.setItem('invScrollY', String(window.scrollY)); } catch (e) {}
    }
    document.addEventListener('DOMContentLoaded', function () {
      try {
        var y = sessionStorage.getItem('invScrollY');
        if (y !== null) {
          window.scrollTo(0, parseInt(y, 10) || 0);
          sessionStorage.removeItem('invScrollY');
        }
      } catch (e) {}
    });
    </script>
