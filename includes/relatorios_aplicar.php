<?php
// includes/relatorios_aplicar.php — aplica o plano aprovado (transação)

/**
 * $decisoes: array opcional por linha de peça vinda do ecrã "Rever":
 *   [ relatorios_pecas.id => ['acao'=>'modificar|criar|ignorar', 'cliente'=>'...'] ]
 * Para aprovação direta (sem rever), passar [].
 */
function nvAplicarRelatorio(PDO $pdo, int $relId, array $decisoes, string $utilizador): array
{
	$st = $pdo->prepare("SELECT * FROM relatorios WHERE id = ? LIMIT 1");
	$st->execute([$relId]);
	$rel = $st->fetch();
	if (!$rel) return ['ok'=>false, 'msg'=>'Relatório não encontrado.'];
	if ($rel['estado'] === 'aprovado') return ['ok'=>false, 'msg'=>'Já aprovado.'];

	$pdo->beginTransaction();
	try {
		$logs = [];

		// 1) PAT: mudar estado + preencher resolução (se PAT existe)
		if ($rel['pat_id']) {
			$pdo->prepare("UPDATE pats SET estado = 'Resolvido', resolucao = ? WHERE id = ?")
				->execute([$rel['resolucao_texto'], $rel['pat_id']]);
			$pdo->prepare("INSERT INTO relatorios_log (relatorio_id,tipo,alvo_id,detalhe) VALUES (?,?,?,?)")
				->execute([$relId,'pat_estado',$rel['pat_id'],'estado=Resolvido']);
			$pdo->prepare("INSERT INTO relatorios_log (relatorio_id,tipo,alvo_id,detalhe) VALUES (?,?,?,?)")
				->execute([$relId,'pat_resolucao',$rel['pat_id'],'resolução preenchida']);
		}

		// 2) Peças
		$linhas = $pdo->prepare("SELECT * FROM relatorios_pecas WHERE relatorio_id = ?");
		$linhas->execute([$relId]);

		$logHist = $pdo->prepare("INSERT INTO historico (peca_id,campo,antes,depois,utilizador,data_alteracao) VALUES (?,?,?,?,?,NOW())");

		foreach ($linhas as $lp) {
			$d = $decisoes[$lp['id']] ?? [];
			$acao = $d['acao'] ?? $lp['acao'];
			$destino = $lp['estado_destino'];
			$sn = $lp['sn'];

			// cliente (só relevante quando destino = "Cliente")
			$clienteId = null; $clientePendente = 0;
			if ($destino === 'Cliente') {
				$nomeCliente = $d['cliente'] ?? ($rel['cliente_detect'] ?? '');
				if (trim($nomeCliente) !== '') {
					$clienteId = nvObterOuCriarCliente($pdo, $nomeCliente);
				} else {
					$clientePendente = 1; // "Cliente — por identificar"
				}
			}

			if ($acao === 'modificar' && $lp['match_peca_id']) {
				$stAntes = $pdo->prepare("SELECT estado FROM pecas WHERE id = ?");
				$stAntes->execute([$lp['match_peca_id']]);
				$antes = $stAntes->fetchColumn();
				$pdo->prepare("UPDATE pecas SET estado = ?, cliente_id = ?, cliente_pendente = ? WHERE id = ?")
					->execute([$destino, $clienteId, $clientePendente, $lp['match_peca_id']]);
				$logHist->execute([$lp['match_peca_id'],'estado',$antes,$destino,$utilizador]);
				$pdo->prepare("INSERT INTO relatorios_log (relatorio_id,tipo,alvo_id,detalhe) VALUES (?,?,?,?)")
					->execute([$relId,'peca_modificada',$lp['match_peca_id'],"estado=$destino"]);

			} elseif ($acao === 'criar' && $sn) {
				$pdo->prepare("INSERT INTO pecas (categoria,produto,sn,cod_barras,parceiro,estado,cliente_id,cliente_pendente,created_at)
                               VALUES ('','',?, '', '', ?, ?, ?, NOW())")
					->execute([$sn,$destino,$clienteId,$clientePendente]);
				$novo = (int)$pdo->lastInsertId();
				$logHist->execute([$novo,'criação','','Peça criada via relatório',$utilizador]);
				$pdo->prepare("INSERT INTO relatorios_log (relatorio_id,tipo,alvo_id,detalhe) VALUES (?,?,?,?)")
					->execute([$relId,'peca_criada',$novo,"SN=$sn estado=$destino"]);
			}
			// 'ignorar' / 'rever' não aplicados aqui
		}

		$pdo->prepare("UPDATE relatorios SET estado='aprovado', aprovado_por=?, aprovado_em=NOW() WHERE id=?")
			->execute([$utilizador, $relId]);

		$pdo->commit();
		return ['ok'=>true, 'msg'=>'Relatório aplicado com sucesso.'];
	} catch (Throwable $e) {
		$pdo->rollBack();
		return ['ok'=>false, 'msg'=>'Erro ao aplicar: ' . $e->getMessage()];
	}
}
