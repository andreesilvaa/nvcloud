<?php // includes/pages/movimentos.php
/** @var PDO $pdo */
$entradasMes = $pdo->query("
  SELECT DATE_FORMAT(created_at,'%Y-%m') AS mes, COUNT(*) total
  FROM pecas
  WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
  GROUP BY mes ORDER BY mes
")->fetchAll();

$saidasMes = $pdo->query("
  SELECT DATE_FORMAT(data_alteracao,'%Y-%m') AS mes, COUNT(*) total
  FROM historico
  WHERE campo='estado' AND depois IN ('Cliente','Parceiro','Fornecedor (Reparação)')
    AND data_alteracao >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
  GROUP BY mes ORDER BY mes
")->fetchAll();
?>
<div class="card">
	<h2>Movimentos de stock (12 meses)</h2>
	<table class="table">
		<thead><tr><th>Mês</th><th>Entradas</th><th>Saídas (p/ cliente/parceiro/fornecedor)</th></tr></thead>
		<tbody>
		<?php
		$idx = [];
		foreach ($entradasMes as $e) $idx[$e['mes']]['ent'] = $e['total'];
		foreach ($saidasMes   as $s) $idx[$s['mes']]['sai'] = $s['total'];
		ksort($idx);
		foreach ($idx as $mes => $v): ?>
			<tr><td><?= e($mes) ?></td><td><?= (int)($v['ent'] ?? 0) ?></td><td><?= (int)($v['sai'] ?? 0) ?></td></tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
