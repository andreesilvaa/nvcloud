<?php // includes/pages/resumo.php
$periodo = preg_match('/^\d{4}-\d{2}$/', $_GET['mes'] ?? '') ? $_GET['mes'] : date('Y-m');

$kpi = function(PDO $pdo, string $sql, array $p = []) {
	$s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn();
};
$entradas  = $kpi($pdo, "SELECT COUNT(*) FROM pecas WHERE DATE_FORMAT(created_at,'%Y-%m') = ?", [$periodo]);
$movEstado = $kpi($pdo, "SELECT COUNT(*) FROM historico WHERE campo='estado' AND DATE_FORMAT(data_alteracao,'%Y-%m') = ?", [$periodo]);
require_once __DIR__ . '/../revisoes.php';
$revFeitas = $kpi($pdo, "SELECT COUNT(*) FROM revisoes_peca WHERE periodo = ? AND decisao <> 'pendente'", [$periodo]);
$revPend   = $kpi($pdo, "SELECT COUNT(*) FROM revisoes_peca WHERE periodo = ? AND decisao = 'pendente'", [$periodo]);
?>
<div class="card">
	<h2>Resumo do mês <?= e($periodo) ?></h2>
	<form method="get" style="margin-bottom:14px;">
		<input type="hidden" name="page" value="resumo">
		<input type="month" name="mes" value="<?= e($periodo) ?>">
		<button class="btn btn-blue" type="submit">Ver</button>
	</form>
	<div class="kpi-row">
		<div class="kpi-card"><div class="kpi-num"><?= $entradas ?></div><div>Peças novas</div></div>
		<div class="kpi-card"><div class="kpi-num"><?= $movEstado ?></div><div>Mudanças de estado</div></div>
		<div class="kpi-card"><div class="kpi-num"><?= $revFeitas ?></div><div>Revisões feitas</div></div>
		<div class="kpi-card"><div class="kpi-num"><?= $revPend ?></div><div>Revisões pendentes</div></div>
	</div>
</div>
