<?php
// carrega a peça
$stE = $pdo->prepare("SELECT id, produto, sn, cod_barras, categoria FROM pecas WHERE id = ?");
$stE->execute([(int)($_GET['id'] ?? 0)]);
$pE = $stE->fetch();
if (!$pE) { echo 'Peça não encontrada.'; return; }
?>
<div class="card" style="max-width:380px;">
	<div id="etiqueta" style="padding:14px;border:1px solid #ddd;border-radius:8px;text-align:center;">
		<div style="font-weight:700;"><?= e($pE['produto']) ?></div>
		<div style="font-size:12px;color:#666;"><?= e($pE['categoria']) ?></div>
		<svg id="barcode"></svg>
		<div style="font-family:monospace;"><?= e($pE['sn']) ?></div>
	</div>
	<button class="btn btn-blue" onclick="window.print()">Imprimir</button>
</div>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
    JsBarcode("#barcode", <?= json_encode($pE['sn'] ?: $pE['cod_barras'] ?: (string)$pE['id']) ?>, {
        format: "CODE128", width: 2, height: 50, displayValue: false
    });
</script>

