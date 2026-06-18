<?php
require_once __DIR__ . '/pecas_suspeitas.php';

/** GARANTE QUE EXISTEM LINHAS 'PENDENTES' PARA O PERÍODO (MÊS) ATUAL. */
function nvGerarRevisoesDoMes(PDO $pdo, int $dias = 30): string {
		$periodo = date('Y-m');
		$suspeitas = nvPecasSuspeitas($pdo, ['dias' => $dias]);
		$ins = $pdo->prepare("
			INSERT IGNORE INTO revisoes_peca
					(peca_id, periodo, estado_no_momento, dias_parada, decisao)
			VALUES (?, ?, ?, ?, 'pendente')
		");
		foreach ($suspeitas as $s) {
				$ins->execute([$s['id'], $periodo, $s['estado'], (int)$s['dias_parada']]);
		}
		return $periodo;
}

/** Quantas revisões estão pendentes no mês atual. */
function nvRevisoesPendentes(PDO $pdo): int {
		$st = $pdo->prepare("SELECT COUNT(*) FROM revisoes_peca WHERE periodo = ? AND decisao = 'pendente'");
		$st->execute([date('Y-m')]);
		return (int)$st->fetchColumn();
}