<?php

/** Devolve as peças que ultrapassaram o SLA aplicável (regra específica vence a global). */
function nvSlaQuebras(PDO $pdo): array
{
	$sql = "
        SELECT p.id, p.produto, p.sn, p.parceiro, p.estado, c.account_name AS cliente_nome,
               DATEDIFF(NOW(), COALESCE(p.estado_desde, p.created_at)) AS dias,
               r.dias_limite, r.alvo_tipo, r.alvo_nome
        FROM pecas p
        LEFT JOIN clientes c ON c.id = p.cliente_id
        JOIN sla_regras r
          ON r.ativo = 1 AND r.estado COLLATE utf8mb4_general_ci = p.estado COLLATE utf8mb4_general_ci
         AND (
               r.alvo_tipo = 'global'
            OR (r.alvo_tipo = 'parceiro' AND r.alvo_nome COLLATE utf8mb4_general_ci = p.parceiro COLLATE utf8mb4_general_ci)
            OR (r.alvo_tipo = 'cliente'  AND r.alvo_nome COLLATE utf8mb4_general_ci = c.account_name COLLATE utf8mb4_general_ci)
         )
        WHERE DATEDIFF(NOW(), COALESCE(p.estado_desde, p.created_at)) >= r.dias_limite
        ORDER BY (r.alvo_tipo = 'global'), dias DESC
    ";
	// a ordenação põe as regras específicas primeiro; depois removemos duplicados por peça
	$linhas = $pdo->query($sql)->fetchAll();
	$vistas = [];
	$out = [];
	foreach ($linhas as $l) {
		if (isset($vistas[$l['id']])) continue;   // já contámos esta peça por uma regra mais específica
		$vistas[$l['id']] = true;
		$out[] = $l;
	}
	return $out;
}