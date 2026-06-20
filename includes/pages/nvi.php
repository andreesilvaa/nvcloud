<?php

// ============================================================
// N-Vi — assistente (linguagem natural -> SQL read-only, por regras)
// ============================================================
function nviSemAcento(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    return strtr($s, [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i',
        'ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c',
    ]);
}

/**
 * Interpreta a pergunta e devolve um SELECT (read-only) com parâmetros.
 * Devolve ['erro'=>'...'] se não reconhecer.
 */
function nviInterpretar(string $perguntaOriginal, array $estados): array {
    $q = nviSemAcento($perguntaOriginal);
    $tem = function (string ...$ks) use ($q): bool {
        foreach ($ks as $k) {
            if (str_contains($q, $k)) return true;
        }
        return false;
    };

    // estado mencionado: 1.º entre aspas (ex.: 'Trânsito'); 2.º por palavra inteira,
    // ignorando as expressões de agrupamento ("por parceiro/categoria/estado")
    $estadoMatch = null;
    if (preg_match("/['\"]([^'\"]+)['\"]/u", $perguntaOriginal, $mq)) {
        $alvo = nviSemAcento(trim($mq[1]));
        foreach ($estados as $e) {
            if (nviSemAcento((string)$e) === $alvo) { $estadoMatch = $e; break; }
        }
    }
    if ($estadoMatch === null) {
        $qScan = preg_replace('/\bpor\s+(parceiro|categoria|tipo|estado)\b/u', ' ', $q);
        foreach ($estados as $e) {
            $en = nviSemAcento((string)$e);
            if ($en !== '' && preg_match('/\b' . preg_quote($en, '/') . '\b/u', $qScan)) {
                $estadoMatch = $e;
                break;
            }
        }
    }

    // 1) Top N tipos/categorias (opcionalmente "disponíveis")
    if ($tem('top') && $tem('tipo', 'categoria')) {
        $lim = preg_match('/top\s*(\d+)/', $q, $m) ? max(1, (int)$m[1]) : 5;
        $disp = $tem('disponive');
        return [
            'erro'   => '',
            'titulo' => "Top $lim tipos" . ($disp ? ' disponíveis' : ''),
            'sql'    => "SELECT categoria AS tipo, COUNT(*) AS total FROM pecas"
                      . ($disp ? " WHERE estado = 'Disponível'" : "")
                      . " GROUP BY categoria ORDER BY total DESC LIMIT $lim",
            'params' => [],
        ];
    }
    // 2) Últimas N peças
    if ($tem('ultima', 'recente') && $tem('peca')) {
        $lim = preg_match('/(\d+)/', $q, $m) ? min(200, max(1, (int)$m[1])) : 20;
        return [
            'erro'   => '',
            'titulo' => "Últimas $lim peças",
            'sql'    => "SELECT id, categoria, produto, sn, parceiro, estado, created_at
                         FROM pecas ORDER BY created_at DESC, id DESC LIMIT $lim",
            'params' => [],
        ];
    }
    // 3) Autor da ordem/envio mais recente
    if ($tem('autor', 'quem criou', 'criado por', 'quem') && $tem('ordem', 'envio')) {
        return [
            'erro'   => '',
            'titulo' => 'Autor da ordem mais recente',
            'sql'    => "SELECT criado_por, documento, num_documento, data_documento, created_at
                         FROM envios ORDER BY created_at DESC, id DESC LIMIT 1",
            'params' => [],
        ];
    }
    // 4) Peças por parceiro (com estado opcional)
    if ($tem('peca') && $tem('parceiro')) {
        if ($estadoMatch !== null) {
            return [
                'erro'   => '',
                'titulo' => "Peças em '$estadoMatch' por parceiro",
                'sql'    => "SELECT parceiro, COUNT(*) AS total FROM pecas WHERE estado = ? GROUP BY parceiro ORDER BY total DESC",
                'params' => [$estadoMatch],
            ];
        }
        return [
            'erro'   => '',
            'titulo' => 'Peças por parceiro',
            'sql'    => "SELECT parceiro, COUNT(*) AS total FROM pecas GROUP BY parceiro ORDER BY total DESC",
            'params' => [],
        ];
    }
    // 5) Peças por categoria/tipo
    if ($tem('peca') && $tem('categoria', 'tipo')) {
        return [
            'erro'   => '',
            'titulo' => 'Peças por categoria',
            'sql'    => "SELECT categoria, COUNT(*) AS total FROM pecas GROUP BY categoria ORDER BY total DESC",
            'params' => [],
        ];
    }
    // 6) Peças por estado (visão geral)
    if ($tem('peca') && $tem('estado') && $estadoMatch === null) {
        return [
            'erro'   => '',
            'titulo' => 'Peças por estado',
            'sql'    => "SELECT estado, COUNT(*) AS total FROM pecas GROUP BY estado ORDER BY total DESC",
            'params' => [],
        ];
    }
    // 7) Quantas peças (total ou num estado)
    if ($tem('quant') && $tem('peca')) {
        if ($estadoMatch !== null) {
            return [
                'erro'   => '',
                'titulo' => "Nº de peças em '$estadoMatch'",
                'sql'    => "SELECT COUNT(*) AS total FROM pecas WHERE estado = ?",
                'params' => [$estadoMatch],
            ];
        }
        return ['erro'=>'', 'titulo'=>'Nº total de peças', 'sql'=>"SELECT COUNT(*) AS total FROM pecas", 'params'=>[]];
    }
    // 8) PATs
    if ($tem('pat')) {
        if ($tem('estado')) {
       return ['erro'=>'', 'titulo'=>'PATs por estado', 'sql'=>"SELECT estado, COUNT(*) AS total FROM pats GROUP BY estado ORDER BY total DESC", 'params'=>[]];
        }
        return ['erro'=>'', 'titulo'=>'Nº total de PATs', 'sql'=>"SELECT COUNT(*) AS total FROM pats", 'params'=>[]];
    }
    // 9) Contactos de um cliente específico
    if ($tem('email', 'contacto', 'telefone', 'telemovel', 'morada')) {
        if (preg_match('/(?:de|do|da|dos|das)\s+(.+)$/u', $perguntaOriginal, $m)) {
            $nome = trim($m[1], " ?.\t\n");
            if ($nome !== '') {
                return [
                    'erro'   => '',
                    'titulo' => "Contactos de \"$nome\"",
                    'sql'    => "SELECT account_name, email, phone, mobile, mailing_city, mailing_country
                                 FROM clientes_contactos WHERE account_name LIKE ? ORDER BY account_name LIMIT 50",
                    'params' => ['%' . $nome . '%'],
                ];
            }
        }
    }
    // 10) Clientes (visão geral, a partir da tabela `clientes`)
    if ($tem('cliente')) {
        if ($tem('tipo')) {
            return [
                'erro'   => '',
                'titulo' => 'Clientes por tipo',
                'sql'    => "SELECT CASE LOWER(TRIM(COALESCE(type,'')))
                                      WHEN 'customer'                THEN 'Cliente'
                                      WHEN 'end customer'            THEN 'Cliente Final'
                                      WHEN 'own shop'                THEN 'Loja Própria'
                                      WHEN 'prospect'                THEN 'Potencial Cliente'
                                      WHEN 'exclusive agent'         THEN 'Agente Exclusivo'
                                      WHEN 'partner'                 THEN 'Parceiro'
                                      WHEN 'partner - portugal'      THEN 'Parceiro - Portugal'
                                      WHEN 'partner - international'  THEN 'Parceiro - Internacional'
                                      WHEN 'partner - spain'         THEN 'Parceiro - Espanha'
                                      WHEN 'partner - latam'         THEN 'Parceiro - LATAM'
                                      ELSE 'Sem tipo'
                                    END AS tipo,
                                    COUNT(*) AS total
                             FROM clientes GROUP BY tipo ORDER BY total DESC",
                'params' => [],
            ];
        }
        return [
            'erro'   => '',
            'titulo' => 'Nº de clientes',
            'sql'    => "SELECT COUNT(*) AS total_clientes,
                                SUM(parent_account IS NOT NULL AND parent_account <> '') AS com_conta_mae
                         FROM clientes",
            'params' => [],
        ];
    }

    return ['erro' => 'Não consegui interpretar a pergunta. Experimenta um dos exemplos rápidos (ex.: "Top 5 tipos disponíveis", "Últimas 20 peças", "Autor da ordem mais recente"), ou pergunta por peças/PATs/clientes por estado, categoria ou parceiro.'];
}

// Processamento da pergunta ao N-Vi (só na página nvi).
$nvi = ['pergunta'=>'', 'sql'=>'', 'titulo'=>'', 'colunas'=>[], 'linhas'=>[], 'erro'=>'', 'executou'=>false];
if ($page === 'nvi') {
    $nvi['pergunta'] = trim($_POST['pergunta'] ?? ($_GET['q'] ?? ''));
    if ($nvi['pergunta'] !== '') {
        $interp = nviInterpretar($nvi['pergunta'], $estados);
        if (!empty($interp['erro'])) {
            $nvi['erro'] = $interp['erro'];
        } else {
            // Salvaguarda: só permitir SELECT (read-only)
            if (stripos(ltrim($interp['sql']), 'select') !== 0) {
                $nvi['erro'] = 'Apenas são permitidas consultas de leitura.';
            } else {
                $nvi['sql']    = $interp['sql'];
                $nvi['titulo'] = $interp['titulo'];
                try {
                    $st = $pdo->prepare($interp['sql']);
                    $st->execute($interp['params'] ?? []);
                    $nvi['linhas']  = $st->fetchAll();
                    $nvi['colunas'] = $nvi['linhas'] ? array_keys($nvi['linhas'][0]) : [];
                    $nvi['executou'] = true;
                } catch (Throwable $e) {
                    $nvi['erro'] = 'Erro ao executar a consulta: ' . $e->getMessage();
                }
            }
        }
    }
}
?>

  <h1 class="section-title">Olá sou o 🤖 N-Vi, o assistente AI virtual da Newvision. Como posso ajudar?</h1>

  <form method="post" action="app.php?page=nvi">
    <div style="margin-bottom:14px;">
      <label style="font-weight:600;">Pergunta:</label>
      <textarea id="nviPergunta" name="pergunta" rows="4"
                style="width:100%; padding:12px; border:1px solid #d6dbe1; border-radius:8px; resize:vertical;"
                placeholder="ex.: Quantas peças estão em 'Trânsito' por parceiro?"><?= htmlspecialchars($nvi['pergunta']) ?></textarea>
    </div>

    <p style="color:#6b7280; font-size:13px; margin:6px 0;">
      Dicas: pergunta em linguagem natural. O assistente gera uma <strong>RESPOSTA (read-only)</strong>, validada e executada.<br>
      Não utilizes as respostas como formais em nome da Newvision sem validares por outras fontes.
    </p>

    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:14px 0;">
      <span style="color:#6b7280; font-size:13px;">Exemplos rápidos:</span>
      <button type="button" class="btn btn-grey" onclick="nviExemplo('Top 5 tipos disponíveis')">Top 5 tipos disponíveis</button>
      <button type="button" class="btn btn-grey" onclick="nviExemplo('Últimas 20 peças')">Últimas 20 peças</button>
      <button type="button" class="btn btn-grey" onclick="nviExemplo('Autor da ordem mais recente')">Autor da ordem mais recente</button>
    </div>

    <div style="display:flex; gap:10px;">
      <button type="submit" class="btn btn-blue">Executar</button>
      <a class="btn btn-yellow" href="app.php?page=dashboard" onclick="nvVoltar(event)">← Voltar ao Dashboard</a>
    </div>
  </form>

  <?php if ($nvi['erro'] !== ''): ?>
    <div class="alerta-erro" style="margin-top:20px;"><?= htmlspecialchars($nvi['erro']) ?></div>
  <?php elseif ($nvi['executou']): ?>
    <div class="panel" style="margin-top:20px;">
      <h4 style="margin:0 0 10px;"><?= htmlspecialchars($nvi['titulo']) ?></h4>
      <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:8px 12px; font-family:monospace; font-size:12px; color:#334155; margin-bottom:14px; white-space:pre-wrap;">
        <?= htmlspecialchars(preg_replace('/\s+/', ' ', $nvi['sql'])) ?>
      </div>
      <?php if (empty($nvi['linhas'])): ?>
        <p style="color:#6b7280;">Sem resultados para esta pergunta.</p>
      <?php else: ?>
        <div style="overflow-x:auto;">
          <table class="table">
            <thead><tr>
              <?php foreach ($nvi['colunas'] as $col): ?><th><?= htmlspecialchars($col) ?></th><?php endforeach; ?>
            </tr></thead>
            <tbody>
              <?php foreach ($nvi['linhas'] as $linha): ?>
                <tr><?php foreach ($linha as $valor): ?><td><?= htmlspecialchars((string)$valor) ?></td><?php endforeach; ?></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p style="color:#9ca3af; font-size:12px; margin-top:10px;"><?= count($nvi['linhas']) ?> resultado(s).</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <script>
    function nviExemplo(t){ var ta=document.getElementById('nviPergunta'); if(ta){ ta.value=t; ta.form.submit(); } }
  </script>
