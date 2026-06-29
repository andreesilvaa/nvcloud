<?php

// ============================================================
// N-Vi — assistente (linguagem natural -> SQL read-only, por regras)
// ============================================================
function nviSemAcento(string $s): string
{
    $s = mb_strtolower($s, "UTF-8");
    return strtr($s, [
        "á" => "a",
        "à" => "a",
        "â" => "a",
        "ã" => "a",
        "é" => "e",
        "ê" => "e",
        "í" => "i",
        "ó" => "o",
        "ô" => "o",
        "õ" => "o",
        "ú" => "u",
        "ç" => "c",
    ]);
}

/**
 * Interpreta a pergunta e devolve um SELECT (read-only) com parâmetros.
 * Devolve ['erro'=>'...'] se não reconhecer.
 */
function nviInterpretar(string $perguntaOriginal, array $estados): array
{
    $q = nviSemAcento($perguntaOriginal);
    $tem = function (string ...$ks) use ($q): bool {
        foreach ($ks as $k) {
            if (str_contains($q, $k)) {
                return true;
            }
        }
        return false;
    };

    // estado mencionado: 1.º entre aspas (ex.: 'Trânsito'); 2.º por palavra inteira,
    // ignorando as expressões de agrupamento ("por parceiro/categoria/estado")
    $estadoMatch = null;
    if (preg_match("/['\"]([^'\"]+)['\"]/u", $perguntaOriginal, $mq)) {
        $alvo = nviSemAcento(trim($mq[1]));
        foreach ($estados as $e) {
            if (nviSemAcento((string) $e) === $alvo) {
                $estadoMatch = $e;
                break;
            }
        }
    }
    if ($estadoMatch === null) {
        $qScan = preg_replace(
            "/\bpor\s+(parceiro|categoria|tipo|estado)\b/u",
            " ",
            $q,
        );
        foreach ($estados as $e) {
            $en = nviSemAcento((string) $e);
            if (
                $en !== "" &&
                preg_match("/\b" . preg_quote($en, "/") . "\b/u", $qScan)
            ) {
                $estadoMatch = $e;
                break;
            }
        }
    }

    // 1) Top N tipos/categorias (opcionalmente "disponíveis")
    if ($tem("top") && $tem("tipo", "categoria")) {
        $lim = preg_match("/top\s*(\d+)/", $q, $m) ? max(1, (int) $m[1]) : 5;
        $disp = $tem("disponive");
        return [
            "erro" => "",
            "titulo" => "Top $lim tipos" . ($disp ? " disponíveis" : ""),
            "sql" =>
                "SELECT categoria AS tipo, COUNT(*) AS total FROM pecas" .
                ($disp ? " WHERE estado = 'Disponível'" : "") .
                " GROUP BY categoria ORDER BY total DESC LIMIT $lim",
            "params" => [],
        ];
    }
    // 2) Últimas N peças
    if ($tem("ultima", "recente") && $tem("peca")) {
        $lim = preg_match("/(\d+)/", $q, $m)
            ? min(200, max(1, (int) $m[1]))
            : 20;
        return [
            "erro" => "",
            "titulo" => "Últimas $lim peças",
            "sql" => "SELECT id, categoria, produto, sn, parceiro, estado, created_at
                         FROM pecas ORDER BY created_at DESC, id DESC LIMIT $lim",
            "params" => [],
        ];
    }
    // 3) Autor da ordem/envio mais recente
    if (
        $tem("autor", "quem criou", "criado por", "quem") &&
        $tem("ordem", "envio")
    ) {
        return [
            "erro" => "",
            "titulo" => "Autor da ordem mais recente",
            "sql" => "SELECT criado_por, documento, num_documento, data_documento, created_at
                         FROM envios ORDER BY created_at DESC, id DESC LIMIT 1",
            "params" => [],
        ];
    }
    // 4) Peças por parceiro (com estado opcional)
    if ($tem("peca") && $tem("parceiro")) {
        if ($estadoMatch !== null) {
            return [
                "erro" => "",
                "titulo" => "Peças em '$estadoMatch' por parceiro",
                "sql" =>
                    "SELECT parceiro, COUNT(*) AS total FROM pecas WHERE estado = ? GROUP BY parceiro ORDER BY total DESC",
                "params" => [$estadoMatch],
            ];
        }
        return [
            "erro" => "",
            "titulo" => "Peças por parceiro",
            "sql" =>
                "SELECT parceiro, COUNT(*) AS total FROM pecas GROUP BY parceiro ORDER BY total DESC",
            "params" => [],
        ];
    }
    // 5) Peças por categoria/tipo
    if ($tem("peca") && $tem("categoria", "tipo")) {
        return [
            "erro" => "",
            "titulo" => "Peças por categoria",
            "sql" =>
                "SELECT categoria, COUNT(*) AS total FROM pecas GROUP BY categoria ORDER BY total DESC",
            "params" => [],
        ];
    }
    // 6) Peças por estado (visão geral)
    if ($tem("peca") && $tem("estado") && $estadoMatch === null) {
        return [
            "erro" => "",
            "titulo" => "Peças por estado",
            "sql" =>
                "SELECT estado, COUNT(*) AS total FROM pecas GROUP BY estado ORDER BY total DESC",
            "params" => [],
        ];
    }
    // 7) Quantas peças (total ou num estado)
    if ($tem("quant") && $tem("peca")) {
        if ($estadoMatch !== null) {
            return [
                "erro" => "",
                "titulo" => "Nº de peças em '$estadoMatch'",
                "sql" => "SELECT COUNT(*) AS total FROM pecas WHERE estado = ?",
                "params" => [$estadoMatch],
            ];
        }
        return [
            "erro" => "",
            "titulo" => "Nº total de peças",
            "sql" => "SELECT COUNT(*) AS total FROM pecas",
            "params" => [],
        ];
    }
    // 8) PATs
    if ($tem("pat")) {
        if ($tem("estado")) {
            return [
                "erro" => "",
                "titulo" => "PATs por estado",
                "sql" =>
                    "SELECT estado, COUNT(*) AS total FROM pats GROUP BY estado ORDER BY total DESC",
                "params" => [],
            ];
        }
        return [
            "erro" => "",
            "titulo" => "Nº total de PATs",
            "sql" => "SELECT COUNT(*) AS total FROM pats",
            "params" => [],
        ];
    }
    // 9) Contactos de um cliente específico
    if ($tem("email", "contacto", "telefone", "telemovel", "morada")) {
        if (
            preg_match('/(?:de|do|da|dos|das)\s+(.+)$/u', $perguntaOriginal, $m)
        ) {
            $nome = trim($m[1], " ?.\t\n");
            if ($nome !== "") {
                return [
                    "erro" => "",
                    "titulo" => "Contactos de \"$nome\"",
                    "sql" => "SELECT account_name, email, phone, mobile, mailing_city, mailing_country
                                 FROM clientes_contactos WHERE account_name LIKE ? ORDER BY account_name LIMIT 50",
                    "params" => ["%" . $nome . "%"],
                ];
            }
        }
    }
    // 10) Clientes (visão geral, a partir da tabela `clientes`)
    if ($tem("cliente")) {
        if ($tem("tipo")) {
            return [
                "erro" => "",
                "titulo" => "Clientes por tipo",
                "sql" => "SELECT CASE LOWER(TRIM(COALESCE(type,'')))
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
                "params" => [],
            ];
        }
        return [
            "erro" => "",
            "titulo" => "Nº de clientes",
            "sql" => "SELECT COUNT(*) AS total_clientes,
                                SUM(parent_account IS NOT NULL AND parent_account <> '') AS com_conta_mae
                         FROM clientes",
            "params" => [],
        ];
    }

    return [
        "erro" =>
            'Não consegui interpretar a pergunta. Experimenta um dos exemplos rápidos (ex.: "Top 5 tipos disponíveis", "Últimas 20 peças", "Autor da ordem mais recente"), ou pergunta por peças/PATs/clientes por estado, categoria ou parceiro.',
    ];
}

// Processamento da pergunta ao N-Vi (só na página nvi).
$nvi = [
    "pergunta" => "",
    "sql" => "",
    "titulo" => "",
    "colunas" => [],
    "linhas" => [],
    "erro" => "",
    "executou" => false,
];
if ($page === "nvi") {
    $nvi["pergunta"] = trim($_POST["pergunta"] ?? ($_GET["q"] ?? ""));
    if ($nvi["pergunta"] !== "") {
        $interp = nviInterpretar($nvi["pergunta"], $estados);
        if (!empty($interp["erro"])) {
            $nvi["erro"] = $interp["erro"];
        } else {
            // Salvaguarda: só permitir SELECT (read-only)
            if (stripos(ltrim($interp["sql"]), "select") !== 0) {
                $nvi["erro"] = "Apenas são permitidas consultas de leitura.";
            } else {
                $nvi["sql"] = $interp["sql"];
                $nvi["titulo"] = $interp["titulo"];
                try {
                    $st = $pdo->prepare($interp["sql"]);
                    $st->execute($interp["params"] ?? []);
                    $nvi["linhas"] = $st->fetchAll();
                    $nvi["colunas"] = $nvi["linhas"]
                        ? array_keys($nvi["linhas"][0])
                        : [];
                    $nvi["executou"] = true;
                } catch (Throwable $e) {
                    $nvi["erro"] =
                        "Erro ao executar a consulta: " . $e->getMessage();
                }
            }
        }
    }
}
?>

<style>
.nvi-panel{ max-width:760px; margin:0 auto; }
.nvi-header{ display:flex; align-items:center; gap:14px; margin-bottom:22px; padding-bottom:18px; border-bottom:1px solid #eef1f5; }
.nvi-avatar{ width:48px; height:48px; border-radius:50%; background:linear-gradient(135deg,#cba35c,#e0bd6e); display:flex; align-items:center; justify-content:center; font-size:24px; flex-shrink:0; }
.nvi-title{ margin:0; font-size:20px; font-weight:700; color:#1e293b; }
.nvi-subtitle{ margin:2px 0 0; font-size:13px; color:#6b7280; }
.nvi-field{ margin-bottom:14px; }
.nvi-field label{ font-weight:600; font-size:13px; color:#374151; margin-bottom:8px; display:block; }
.nvi-textarea{ width:100%; max-width:100%; box-sizing:border-box; padding:14px; border:1px solid #d6dbe1; border-radius:10px; resize:vertical; font-size:14px; font-family:inherit; }
.nvi-textarea:focus{ outline:none; border-color:#cba35c; box-shadow:0 0 0 4px rgba(203,163,92,.15); }
.nvi-tip{ font-size:12.5px; color:#6b7280; background:#f8fafc; border:1px solid #eef1f5; border-radius:10px; padding:10px 14px; margin:0 0 18px; line-height:1.6; }
.nvi-examples{ margin-bottom:20px; }
.nvi-examples-label{ display:block; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#9ca3af; margin-bottom:10px; }
.nvi-chips{ display:flex; flex-wrap:wrap; gap:8px; }
.nvi-chip{ border:1px solid #e5e9ef; background:#f8fafc; color:#374151; border-radius:999px; padding:9px 16px; font-size:13px; font-weight:600; cursor:pointer; transition:.15s; }
.nvi-chip:hover{ background:#fdf3df; border-color:#cba35c; color:#92400e; }
.nvi-actions{ display:flex; justify-content:space-between; gap:10px; padding-top:18px; border-top:1px solid #eef1f5; flex-wrap:wrap; }
.nvi-actions .btn{ flex:1; text-align:center; }
body.dark-mode .nvi-header{ border-color:#374151; }
body.dark-mode .nvi-tip{ background:#1f2937; border-color:#374151; color:#9ca3af; }
body.dark-mode .nvi-chip{ background:#374151; border-color:#4b5563; color:#e5e7eb; }
body.dark-mode .nvi-actions{ border-color:#374151; }
@media (max-width:560px){
  .nvi-actions{ flex-direction:column-reverse; }
  .nvi-actions .btn{ width:100%; text-align:center; }
  .nvi-header{ gap:12px; }
  .nvi-avatar{ width:42px; height:42px; font-size:20px; }
  .nvi-title{ font-size:17px; }
}
</style>

<div class="nvi-page">
  <div class="panel nvi-panel">
    <div class="nvi-header">
      <div class="nvi-avatar">🤖</div>
      <div>
        <h1 class="nvi-title">N-Vi</h1>
        <p class="nvi-subtitle">O teu assistente de consultas em linguagem natural</p>
      </div>
    </div>

    <form method="post" action="app.php?page=nvi">
      <div class="nvi-field">
        <label>Pergunta</label>
        <textarea id="nviPergunta" name="pergunta" rows="4" class="nvi-textarea"
                  placeholder="ex.: Quantas peças estão em 'Trânsito' por parceiro?"><?= htmlspecialchars(
                      $nvi["pergunta"],
                  ) ?></textarea>
      </div>

      <p class="nvi-tip">
        Pergunta em linguagem natural. O assistente gera uma <strong>consulta de leitura (read-only)</strong>, validada e executada.<br>
        Não utilizes as respostas como formais em nome da Newvision sem validares por outras fontes.
      </p>

      <div class="nvi-examples">
        <span class="nvi-examples-label">Exemplos rápidos</span>
        <div class="nvi-chips">
          <button type="button" class="nvi-chip" onclick="nviExemplo('Top 5 tipos disponíveis')">Top 5 tipos disponíveis</button>
          <button type="button" class="nvi-chip" onclick="nviExemplo('Últimas 20 peças')">Últimas 20 peças</button>
          <button type="button" class="nvi-chip" onclick="nviExemplo('Autor da ordem mais recente')">Autor da ordem mais recente</button>
        </div>
      </div>

      <div class="nvi-actions">
        <a class="btn btn-yellow" href="app.php?page=dashboard" onclick="nvVoltar(event)">← Voltar ao Dashboard</a>
        <button type="submit" class="btn btn-blue">Executar</button>
      </div>
    </form>
  </div>

  <?php if ($nvi["erro"] !== ""): ?>
    <div class="alerta-erro" style="margin-top:20px;"><?= htmlspecialchars(
        $nvi["erro"],
    ) ?></div>
  <?php elseif ($nvi["executou"]): ?>
    <div class="panel" style="margin-top:20px;">
      <div class="panel-header-row" style="margin-bottom:10px;">
        <div class="panel-header-left">
          <h4 style="margin:0;"><?= htmlspecialchars($nvi["titulo"]) ?></h4>
          <?php if (
              !empty($nvi["linhas"])
          ): ?><span class="panel-count-badge"><?= count(
    $nvi["linhas"],
) ?></span><?php endif; ?>
        </div>
        <div class="panel-header-actions">
          <button type="button" class="btn btn-grey" style="padding:8px 14px; font-size:13px;" id="nviCopySql">
            <i class="bi bi-clipboard"></i> Copiar SQL
          </button>
        </div>
      </div>
      <div id="nviSqlBox" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:8px 12px; font-family:monospace; font-size:12px; color:#334155; margin-bottom:14px; white-space:pre-wrap;">
        <?= htmlspecialchars(preg_replace("/\s+/", " ", $nvi["sql"])) ?>
      </div>
      <?php if (empty($nvi["linhas"])): ?>
        <p style="color:#6b7280;">Sem resultados para esta pergunta.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table" id="tabelaNvi">
            <thead><tr>
              <?php foreach (
                  $nvi["colunas"]
                  as $col
              ): ?><th><?= htmlspecialchars($col) ?></th><?php endforeach; ?>
            </tr></thead>
            <tbody>
              <?php foreach ($nvi["linhas"] as $linha): ?>
                <tr><?php foreach (
                    $linha
                    as $valor
                ): ?><td><?= htmlspecialchars(
    (string) $valor,
) ?></td><?php endforeach; ?></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
    <script>
      (function () {
        const btn = document.getElementById('nviCopySql');
        const box = document.getElementById('nviSqlBox');
        if (!btn || !box) return;
        btn.addEventListener('click', function () {
          navigator.clipboard.writeText(box.textContent.trim()).then(function () {
            const original = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check-lg"></i> Copiado!';
            setTimeout(function () { btn.innerHTML = original; }, 1500);
          });
        });
      })();
    </script>
  <?php endif; ?>

  <script>
    function nviExemplo(t){ var ta=document.getElementById('nviPergunta'); if(ta){ ta.value=t; ta.form.submit(); } }
  </script>
</div>
