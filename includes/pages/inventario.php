<?php
// ============================================================
// 10. PROCESSAMENTO POST: ELIMINAR PEÇA
// ============================================================

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'eliminar_peca') {
    $deleteId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  
    if ($deleteId <= 0) {
      $_SESSION['mensagem_erro'] = 'ID inválido para eliminar.';
      header('Location: app.php?page=inventario');
      exit;
    }

    $stmtPeca = $pdo->prepare("SELECT * FROM pecas WHERE id = ?");
    $stmtPeca->execute([$deleteId]);
    $peca = $stmtPeca->fetch();

    if (!$peca) {
        $_SESSION['mensagem_erro'] = 'Peça não encontrada para eliminar.';
        header('Location: app.php?page=inventario');
        exit;
    }

    $utilizador = $_SESSION['user_nome'] ?? 'Sistema';

    $stmtHistorico = $pdo->prepare("
        INSERT INTO historico (peca_id, campo, antes, depois, utilizador, data_alteracao)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");

    $stmtHistorico->execute([$deleteId, 'eliminação', 'Peça existente', 'Peça eliminada', $utilizador]);

    $campos = ['categoria', 'produto', 'sn', 'cod_barras', 'parceiro', 'estado'];

    foreach ($campos as $campo){
      $stmtHistorico->execute([
        $deleteId,
        $campo,
        $peca[$campo] ?? '',
        '',
        $utilizador
      ]);
    }

    $stmtDelete = $pdo->prepare("DELETE FROM pecas WHERE id = ?");
    $stmtDelete->execute([$deleteId]);

    $_SESSION['mensagem_sucesso'] = 'Peça eliminada com sucesso.';
    header('Location: app.php?page=inventario');
    exit;
  }



$filters = [
    'categoria' => $_GET['categoria'] ?? '',
    'estado' => $_GET['estado'] ?? '',
    'parceiro' => $_GET['parceiro'] ?? '',
    'sn' => $_GET['sn'] ?? '',
    'produto' => $_GET['produto'] ?? ''
];

$where = [];
$params = [];
if ($filters['categoria']) { $where[] = 'categoria = ?'; $params[] = $filters['categoria']; }
if ($filters['estado']) { $where[] = 'estado = ?'; $params[] = $filters['estado']; }
if ($filters['parceiro']) { $where[] = 'parceiro = ?'; $params[] = $filters['parceiro']; }
if ($filters['sn']) { $where[] = 'sn LIKE ?'; $params[] = '%' . $filters['sn'] . '%'; }
if ($filters['produto']) { 
    $where[] = 'produto = ?'; 
    $params[] = $filters['produto']; 
    }

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// total para calcular páginas
$stmtCntInv = $pdo->prepare("SELECT COUNT(*) FROM pecas" . $whereSql);
$stmtCntInv->execute($params);
$invTotal   = (int)$stmtCntInv->fetchColumn();

$invPorPag = 50;
$invPag    = max(1, (int)($_GET['p'] ?? 1));
$invPaginas = max(1, (int)ceil($invTotal / $invPorPag));
if ($invPag > $invPaginas) { $invPag = $invPaginas; }
$invOffset = ($invPag - 1) * $invPorPag;

$sql = "SELECT * FROM pecas" . $whereSql . " ORDER BY id DESC LIMIT $invPorPag OFFSET $invOffset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pecas = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'lote_estado') {
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) { exit('Ação inválida.'); }
    $ids = array_map('intval', $_POST['ids'] ?? []);
    $novo = trim($_POST['novo_estado'] ?? '');
    $utilizador = $_SESSION['user_nome'] ?? 'Sistema';

    if ($ids && in_array($novo, $estados, true)) {
        $upd  = $pdo->prepare("UPDATE pecas SET estado = ?, estado_desde = NOW() WHERE id = ?");
        $hist = $pdo->prepare("INSERT INTO historico (peca_id,campo,antes,depois,utilizador,data_alteracao) VALUES (?, 'estado', ?, ?, ?, NOW())");
        $sel  = $pdo->prepare("SELECT estado FROM pecas WHERE id = ?");
        $pdo->beginTransaction();
        foreach ($ids as $id) {
            $sel->execute([$id]); $ant = (string)$sel->fetchColumn();
            if ($ant !== $novo) { $upd->execute([$novo, $id]); $hist->execute([$id, $ant, $novo, $utilizador]); }
        }
        $pdo->commit();
        $_SESSION['mensagem_sucesso'] = count($ids) . ' peça(s) atualizada(s).';
    }
    header('Location: app.php?page=inventario');
    exit;
}
?>
  <div style="margin-bottom:18px; display:flex; align-items:center; gap:8px;">
    <a class="btn btn-teal" href="app.php?page=nova_peca">Adicionar Peça</a>
    <a class="btn btn-green" href="app.php?page=qrs">Ler</a>
    <a href="exportar_inventario_csv.php" class="btn btn-green" style="padding:12px 16px; margin-left:auto;">
        <i class="bi bi-download"></i> Exportar CSV
    </a>
  </div>

  <?php if (!empty($_SESSION['mensagem_erro'])) {
      echo '<div class="alerta-erro">' . htmlspecialchars($_SESSION['mensagem_erro']) . '</div>';
      unset($_SESSION['mensagem_erro']);
  }
  ?>

  <?php if (!empty($_SESSION['mensagem_sucesso'])): ?> 
    <div class="alerta-sucesso"><?= htmlspecialchars($_SESSION['mensagem_sucesso']) ?></div>
    <?php unset($_SESSION['mensagem_sucesso']); ?>
    <?php endif; ?>

  <form method="get">
    <input type="hidden" name="page" value="inventario">
      <div class="filters">
        <div><label>Tipo:</label><label>
                <select name="categoria">
                  <option value="">-- Todos --</option>
                    <?php foreach($categorias as $cat): ?>
                    <option value="<?=$cat?>" <?= $filters['categoria']===$cat?'selected':'' ?>><?=$cat?></option><?php endforeach; ?></select>
            </label>
        </div>

      <div><label>Estado:</label><label>
              <select name="estado">
                <option value="">-- Todos --</option>
                  <?php foreach($estados as $estado): ?>
                  <option value="<?=$estado?>" <?= $filters['estado']===$estado?'selected':'' ?>><?=$estado?></option><?php endforeach; ?></select>
          </label>
      </div>

      <div><label>Parceiro:</label><label>
              <select name="parceiro">
                <option value="">-- Todos --</option>
                  <?php foreach($parceiros as $parceiro): ?>
                  <option value="<?=$parceiro?>" <?= $filters['parceiro']===$parceiro?'selected':'' ?>><?=$parceiro?></option>
                  <?php endforeach; ?></select>
          </label>
      </div>

      <div><button class="btn btn-blue" type="submit"><i class="bi bi-search"></i> Filtrar</button></div>
    </div>

    <div class="filters2">
      <div><label>SN (N.º de série):</label>
          <label>
              <input type="text" name="sn" value="<?=htmlspecialchars($filters['sn'])?>" placeholder="ex.: ABC12345">
          </label>
      </div>

<div>
  <label>Nome da peça:</label>
    <label>
        <select name="produto">
          <option value="">-- Todos --</option>
          <?php foreach ($catalogoProdutos as $categoriaCatalogo => $produtos): ?>
            <optgroup label="<?= htmlspecialchars($categoriaCatalogo) ?>">
              <?php foreach ($produtos as $produto): ?>
                <option value="<?= htmlspecialchars($produto) ?>" <?= $filters['produto'] === $produto ? 'selected' : '' ?>>
                  <?= htmlspecialchars($produto) ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
    </label>
</div>


      <div><a class="btn btn-blue" href="app.php?page=inventario">Limpar / Mostrar tudo</a>
      </div>
    </div>
  </form>

  <div class="table-responsive">
  <table class="table">
    <thead><tr>
      <th>ID</th>
      <th>Categoria</th>
      <th>Produto</th>
      <th>SN</th>
      <th>PAT</th>
      <th>Parceiro</th>
      <th>Estado</th>
      <th>Ações</th>
    </tr>
    </thead>

  <tbody>
    <?php foreach($pecas as $p): ?>
      <tr>
        <td><?=$p['id']?></td>
        <td><?=htmlspecialchars($p['categoria'])?></td>
        <td><?=htmlspecialchars($p['produto'])?></td>
        <td><?=htmlspecialchars($p['sn'])?></td>
        <td>N/A</td>
        <td><?=htmlspecialchars($p['parceiro'])?></td>
        <td><?= estadoBolha($p['estado']) ?></td>
        <td class="actions">
          <a class="btn btn-yellow" href="app.php?page=nova_peca&edit=<?=$p['id']?>">Editar</a>
          <form method="post" style="display:inline-block;" onsubmit="return nvConfirmar(this, 'Eliminar esta peça? Esta ação é irreversível.');">
            <input type="hidden" name="form_type" value="eliminar_peca">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button type="submit" class="btn btn-red">Eliminar</button>
          </form>
          <a class="btn btn-grey" href="app.php?page=historico&id=<?=$p['id']?>">Histórico</a>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
  </table>
  </div>

    <?php if ($invPaginas > 1):
        // preserva os filtros atuais na navegação
        $qs = $_GET; unset($qs['p']);
        $base = 'app.php?' . http_build_query(array_merge($qs, ['page'=>'inventario']));
        ?>
        <div class="paginacao" style="margin-top:14px;display:flex;gap:8px;align-items:center;">
            <?php if ($invPag > 1): ?>
                <a class="btn btn-grey" href="<?= e($base) ?>&p=<?= $invPag-1 ?>">‹ Anterior</a>
            <?php endif; ?>
            <span>Página <?= $invPag ?> de <?= $invPaginas ?> (<?= $invTotal ?> peças)</span>
            <?php if ($invPag < $invPaginas): ?>
                <a class="btn btn-grey" href="<?= e($base) ?>&p=<?= $invPag+1 ?>">Seguinte ›</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>


