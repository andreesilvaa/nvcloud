<?php
/**
 * DEVOLVE AS PEÇAS CUJO ESTADO "EM CURSO" ESTÁ PARADO HÁ DEMASIADO TEMPO.
 *
 * @param array $opcoes ['dias' => int, 'estados' => string[], 'apenas_estado' => string|null]
 *                        'apenas_estado' força um único estado (ex.: 'Laboratório' para a área Lab).
 */
function nvPecasSuspeitas(PDO $pdo, array $opcoes = []): array
{
	$estadosEmCurso = $opcoes['estados'] ?? [
		'Laboratório', 'Parceiro', 'Fornecedor (Reparação)',
		'OT', 'PAT', 'Trânsito', 'Devolução', 'Desconhecido',
	];
	if (!empty($opcoes['apenas_estado'])) {
			$estadosEmCurso = [$opcoes['apenas_estado']];
	}
	$diasLimite = (int)($opcoes['dias'] ?? 30);

	$place = implode(',', array_fill(0, count($estadosEmCurso), '?'));

	$sql = "
			SELECT
					p.id, p.categoria, p.produto, p.sn, p.parceiro, p.estado,
					COALESCE(h.ult, p.created_at) AS estado_desde,
					DATEDIFF(NOW(), COALESCE(h.ult, p.created_at)) AS dias_parada
			FROM pecas p
			LEFT JOIN (
			    SELECT peca_id, MAX(data_alteracao) AS ult
			    FROM historico WHERE campo = 'estado' GROUP BY peca_id
			) h ON h.peca_id = p.id
			WHERE p.estado IN ($place)
			HAVING dias_parada >= ?
			ORDER BY dias_parada DESC
	";
	$stmt = $pdo->prepare($sql);
	$stmt->execute(array_merge($estadosEmCurso, [$diasLimite]));
	return $stmt->fetchAll();
}