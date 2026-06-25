<?php
require __DIR__ . '/auth.php';            // exige sessão
if (($_SESSION['user_role'] ?? '') !== 'admin') { http_response_code(403); exit('Acesso negado.'); }
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
			$pdo->prepare("UPDATE pats SET estado = '" . EstadoPat::RESOLVIDO . "', resolucao = ? WHERE id = ?")
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
			// Na importação fazemos apenas MATCH contra a lista oficial — nunca criamos
			// contas novas a partir de parsing. Se não houver match, fica pendente.
			// Exceção: se o utilizador escreveu/confirmou o nome no ecrã "Rever"
			// ($d['cliente']), aí sim pode criar via nvObterOuCriarCliente.
			$clienteId = null; $clientePendente = 0;
			if ($destino === 'Cliente') {
				$nomeConfirmado = trim($d['cliente'] ?? '');
				$nomeDetetado   = trim($rel['cliente_detect'] ?? '');
				if ($nomeConfirmado !== '') {
					// utilizador confirmou explicitamente -> pode criar
					$clienteId = nvObterOuCriarCliente($pdo, $nomeConfirmado);
				} elseif ($nomeDetetado !== '') {
					// veio do relatório -> só match; se falhar, fica pendente
					$clienteId = nvMatchCliente($pdo, $nomeDetetado);
					if (!$clienteId) $clientePendente = 1;
				} else {
					$clientePendente = 1; // "Cliente — por identificar"
				}
			}

			if ($acao === 'modificar' && $lp['match_peca_id']) {
				$stAntes = $pdo->prepare("SELECT estado FROM pecas WHERE id = ?");
				$stAntes->execute([$lp['match_peca_id']]);
				$antes = $stAntes->fetchColumn();
				$pdo->prepare("UPDATE pecas SET estado = ?, estado_desde = NOW(), cliente_id = ?, cliente_pendente = ? WHERE id = ?")
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
