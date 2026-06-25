<?php
require __DIR__ . '/auth.php';            // exige sessão
if (($_SESSION['user_role'] ?? '') !== 'admin') { http_response_code(403); exit('Acesso negado.'); }
// includes/relatorios_reconciliar.php — gera o plano de alterações (não aplica)

// Regra de negócio fechada:
//   SN_Recolha (peça retirada do cliente)  => estado destino "Devolução"
//   SN_Novo    (peça instalada no cliente) => estado destino "Cliente"
function nvEstadoDestino(string $papel): string
{
	return $papel === 'recolha' ? EstadoPeca::DEVOLUCAO : EstadoPeca::CLIENTE;
}

/**
 * Persiste o relatório + linhas de peça já com o plano calculado.
 * Devolve ['relatorio_id'=>int, 'resumo'=>[...], 'estado'=>'por_confirmar|revisao_manual'].
 */
function nvReconciliarRelatorio(PDO $pdo, array $parse, array $meta): array
{
	// meta: origem, ficheiro_nome, ficheiro_path, hash_unico
	$estado = 'por_confirmar';
	$avisos = [];

	// 1) PAT existe?
	// Nota: na tabela `pats`, numero_pat já guarda o número COM a revisão
	// incluída (ex: "PAT-00102731/3") — não é preciso (nem correto) voltar
	// a concatenar a revisão. A tentativa antiga comparava sempre contra
	// valores que nunca podiam corresponder (número sem revisão, ou número
	// com a revisão duplicada), por isso nenhum PAT era encontrado mesmo
	// quando existia. Comparamos primeiro pelo valor completo e, só se
	// falhar, tentamos ignorar a revisão como rede de segurança.
	$patId = null;
	if (!empty($parse['pat_numero'])) {
		$st = $pdo->prepare("SELECT id FROM pats WHERE numero_pat = ? LIMIT 1");
		$st->execute([$parse['pat_numero']]);
		$patId = $st->fetchColumn() ?: null;

		if (!$patId) {
			$semRev = preg_replace('#/\d+$#', '', $parse['pat_numero']);
			$st2 = $pdo->prepare("SELECT id FROM pats WHERE numero_pat = ? OR numero_pat LIKE ? LIMIT 1");
			$st2->execute([$semRev, $semRev . '/%']);
			$patId = $st2->fetchColumn() ?: null;
		}
	}
	if (!$patId) {
		$estado = 'revisao_manual';
		$avisos[] = 'PAT ' . ($parse['pat_numero'] ?? '(não detetado)') . ' não encontrado — sinalizado para revisão manual.';
	}

	if (!empty($parse['forcar_revisao'])) {
		$estado = 'revisao_manual';
		$avisos[] = 'Relatório digitalizado (Field Service) — revisão manual obrigatória.';
	}
	// Salvaguarda de qualidade: se faltam os campos essenciais (PAT e cliente)
	// o parsing provavelmente correu mal (layout novo, OCR fraco). Em vez de
	// criar um plano possivelmente errado, força revisão manual.
	$clienteVazio = trim((string)($parse['cliente'] ?? '')) === '';
	$patVazio     = empty($parse['pat_numero']);
	if ($patVazio && $clienteVazio) {
		$estado = 'revisao_manual';
		$avisos[] = 'Não foi possível extrair PAT nem cliente — revisão manual obrigatória.';
	}

	// 2) Inserir relatório
	$ins = $pdo->prepare("
        INSERT INTO relatorios
          (origem, fonte, ficheiro_nome, ficheiro_path, hash_unico, ref_documento,
           pat_numero, pat_id, cliente_detect, resolucao_texto, data_intervencao, estado, payload_json)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
	$ins->execute([
		$meta['origem'], $parse['fonte'], $meta['ficheiro_nome'], $meta['ficheiro_path'],
		$meta['hash_unico'], $parse['ref_documento'] ?? null,
		$parse['pat_numero'] ?? null, $patId,
		$parse['cliente'] ?? '', $parse['resolucao'] ?? '', $parse['data_intervencao'] ?? null,
		$estado, json_encode($parse, JSON_UNESCAPED_UNICODE),
	]);
	$relId = (int)$pdo->lastInsertId();

	// 3) Linhas de peça + plano
	$nModif = 0; $nCriar = 0; $nRever = 0;
	$stPeca = $pdo->prepare("SELECT id, estado FROM pecas WHERE sn = ? LIMIT 1");
	$insRP  = $pdo->prepare("
        INSERT INTO relatorios_pecas
          (relatorio_id, papel, componente, sn, equip_ref, estado_destino, match_peca_id, acao)
        VALUES (?,?,?,?,?,?,?,?)
    ");

	foreach ($parse['pecas'] as $p) {
		$sn = trim((string)($p['sn'] ?? ''));
		$destino = nvEstadoDestino($p['papel']);
		$matchId = null; $acao = 'rever';

		if ($sn !== '') {
			$stPeca->execute([$sn]);
			$row = $stPeca->fetch();
			if ($row) {
				$matchId = (int)$row['id'];
				// Conflito de estado? (peça em estado "final" como Abater)
				if (in_array($row['estado'], EstadoPeca::FINAIS, true)) {
					$acao = 'rever';
					$avisos[] = "SN $sn está em '{$row['estado']}' e o relatório quer '$destino' — conflito, requer decisão.";
					$nRever++;
				} else {
					$acao = 'modificar';
					$nModif++;
				}
			} else {
				// SN não existe -> perguntar se cria
				$acao = 'rever';
				$avisos[] = "SN $sn não existe no inventário — perguntar se criar peça nova.";
				$nRever++;
			}
		} else {
			$acao = 'ignorar'; // sem SN não há peça a tratar (ex.: intervenção de config)
		}

		$insRP->execute([
			$relId, $p['papel'], $p['componente'] ?? null, $sn ?: null,
			$p['equip_ref'] ?? null, $destino, $matchId, $acao,
		]);
	}

	return [
		'relatorio_id' => $relId,
		'estado' => $estado,
		'resumo' => [
			'pat' => $parse['pat_numero'] ?? null,
			'pat_existe' => (bool)$patId,
			'cliente' => $parse['cliente'] ?? '',
			'modificar' => $nModif,
			'criar' => $nCriar,
			'rever' => $nRever,
			'avisos' => $avisos,
		],
	];
}
