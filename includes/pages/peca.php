<?php
/** @var PDO $pdo */

$pecaId = (int)($_GET['id'] ?? 0);
$pecaDetalhe = null;

if ($pecaId > 0) {
    $stmtPecaDet = $pdo->prepare("SELECT * FROM pecas WHERE id = ?");
    $stmtPecaDet->execute([$pecaId]);
    $pecaDetalhe = $stmtPecaDet->fetch();
}
?>

<h1 class="section-title">
  Peça <?= $pecaDetalhe ? '#' . (int)$pecaDetalhe['id'] : '' ?>
  <?php if ($pecaDetalhe && !empty($pecaDetalhe['produto'])): ?>
    — <?= e($pecaDetalhe['produto']) ?>
  <?php endif; ?>
</h1>

<?php if (!$pecaDetalhe): ?>

  <div class="panel">
    <p style="color:#6b7280;">Não foi encontrada nenhuma peça com o ID indicado.</p>
    <div style="margin-top:14px;">
      <a class="btn btn-yellow" href="app.php?page=inventario">← Voltar à lista de peças</a>
    </div>
  </div>

<?php else: ?>

  <div class="card">
    <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:14px; flex-wrap:wrap; margin-bottom:18px;">
      <div>
        <div style="font-weight:700; font-size:18px; color:#1e293b;"><?= e($pecaDetalhe['produto']) ?></div>
        <?php if (!empty($pecaDetalhe['sn'])): ?>
          <div style="font-family:monospace; font-size:13px; color:#6b7280; margin-top:4px;">
            SN: <?= e($pecaDetalhe['sn']) ?>
          </div>
        <?php endif; ?>
      </div>
      <div><?= estadoBolha($pecaDetalhe['estado'] ?? '') ?></div>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:20px;">
      <div style="background:#f8f9fa; border-radius:8px; padding:10px 14px;">
        <div style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px;">ID</div>
        <div style="font-weight:600;">#<?= (int)$pecaDetalhe['id'] ?></div>
      </div>
      <div style="background:#f8f9fa; border-radius:8px; padding:10px 14px;">
        <div style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px;">Categoria</div>
        <div style="font-weight:600;"><?= e($pecaDetalhe['categoria'] ?: '—') ?></div>
      </div>
      <div style="background:#f8f9fa; border-radius:8px; padding:10px 14px;">
        <div style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px;">Parceiro</div>
        <div style="font-weight:600;"><?= e($pecaDetalhe['parceiro'] ?: '—') ?></div>
      </div>
      <div style="background:#f8f9fa; border-radius:8px; padding:10px 14px;">
        <div style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px;">Código de Barras</div>
        <div style="font-weight:600; font-family:monospace;"><?= e($pecaDetalhe['cod_barras'] ?: '—') ?></div>
      </div>
      <?php if (!empty($pecaDetalhe['estado_desde'])): ?>
      <div style="background:#f8f9fa; border-radius:8px; padding:10px 14px;">
        <div style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px;">Estado desde</div>
        <div style="font-weight:600;"><?= e(date('d/m/Y H:i', strtotime($pecaDetalhe['estado_desde']))) ?></div>
      </div>
      <?php endif; ?>
      <?php if (!empty($pecaDetalhe['created_at'])): ?>
      <div style="background:#f8f9fa; border-radius:8px; padding:10px 14px;">
        <div style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px;">Adicionada em</div>
        <div style="font-weight:600;"><?= e(date('d/m/Y H:i', strtotime($pecaDetalhe['created_at']))) ?></div>
      </div>
      <?php endif; ?>
    </div>

    <div style="display:flex; gap:10px; flex-wrap:wrap;">
      <a class="btn btn-yellow" href="app.php?page=nova_peca&edit=<?= (int)$pecaDetalhe['id'] ?>">
        <i class="bi bi-pencil"></i> Editar
      </a>
      <a class="btn btn-grey" href="app.php?page=historico&id=<?= (int)$pecaDetalhe['id'] ?>">
        <i class="bi bi-clock-history"></i> Histórico
      </a>
      <a class="btn btn-green" href="app.php?page=etiqueta&id=<?= (int)$pecaDetalhe['id'] ?>">
        <i class="bi bi-upc-scan"></i> Imprimir Etiqueta
      </a>
      <a class="btn" style="background:#f1f5f9; color:#374151; margin-left:auto;" href="app.php?page=inventario">
        ← Voltar à lista de peças
      </a>
    </div>
  </div>

<?php endif; ?>
