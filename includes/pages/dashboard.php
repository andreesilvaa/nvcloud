<?php
$totalPecas = countQuery($pdo, "SELECT COUNT(*) FROM pecas");
$pecasDisponiveis = countQuery(
    $pdo,
    "SELECT COUNT(*) FROM pecas WHERE estado='Disponível'",
);
$pecasLaboratorio = countQuery(
    $pdo,
    "SELECT COUNT(*) FROM pecas WHERE estado='Laboratório'",
);

// Peças por rever (revisões pendentes do mês atual)
require_once __DIR__ . "/../revisoes.php";
nvGerarRevisoesDoMes($pdo);
$pecasPorRever = nvRevisoesPendentes($pdo);

$patsAbertos = countQuery(
    $pdo,
    "SELECT COUNT(*) FROM pats WHERE estado='Aberto'",
);
$patsConcluidos = countQuery(
    $pdo,
    "SELECT COUNT(*) FROM pats WHERE estado='Concluído'",
);

// Tempo médio PAT -> Execução: da receção (data_recepcao) ao início dos trabalhos (data_inicio)
$mediaExecMin = $pdo
    ->query(
        "
    SELECT AVG(TIMESTAMPDIFF(MINUTE, data_recepcao, data_inicio))
    FROM pats
    WHERE data_recepcao IS NOT NULL
      AND data_inicio   IS NOT NULL
      AND data_inicio >= data_recepcao
",
    )
    ->fetchColumn();

if ($mediaExecMin === null || $mediaExecMin === false) {
    $execLabel = "—";
} else {
    $h = (float) $mediaExecMin / 60;
    $execLabel = $h < 24 ? round($h, 1) . "h" : round($h / 24, 1) . "d";
}

// ── Dashboard · Ranking de Parceiros por carga atual (Opção B) ──
// Leitura apenas: peças por parceiro, destacando as que estão "em curso" (carga ativa).
$rankingParceiros = $pdo
    ->query(
        "
    SELECT p.parceiro,
           COUNT(*) AS total,
           SUM(CASE WHEN p.estado NOT IN ('Disponível','Cliente','Abater') THEN 1 ELSE 0 END) AS em_curso
    FROM pecas p
    WHERE p.parceiro IS NOT NULL AND TRIM(p.parceiro) <> ''
    GROUP BY p.parceiro
    ORDER BY em_curso DESC, total DESC
",
    )
    ->fetchAll();
$rankingMax = 0;
foreach ($rankingParceiros as $rp) {
    $rankingMax = max($rankingMax, (int) $rp["em_curso"]);
}

// ── Dashboard · Peças Paradas (Opção G) ── reutiliza helper existente
require_once __DIR__ . "/../pecas_suspeitas.php";
$pecasParadas = array_slice(nvPecasSuspeitas($pdo, ["dias" => 7]), 0, 8);
?>

<!-- Dashboard-Quadrados -->
  <div class="kpi-row">
    <div class="kpi-card">
      <i class="bi bi-box"></i>
        <div class="num"> <?= $totalPecas ?></div>
          <div>Total Peças</div>
    </div>

    <div class="kpi-card">
      <i class="bi bi-check2-square" style="color:#28a745"></i>
        <div class="num"><?= $pecasDisponiveis ?></div>
          <div>Peças Disponíveis</div>
    </div>

    <div class="kpi-card kpi-laboratorio">
      <i class="bi bi-eyedropper" style="color:#2470dc"></i>
        <div class="num"><?= $pecasLaboratorio ?></div>
          <div>Peças em Laboratório</div>
    </div>

    <div class="kpi-card">
      <i class="bi bi-hourglass-split" style="color:#f59e0b"></i>
        <div class="num"><?= $pecasPorRever ?></div>
          <div>Peças por Rever</div>
    </div>

    <div class="kpi-card">
      <i class="bi bi-folder2-open" style="color:#3d82c4"></i>
        <div class="num"><?= $patsAbertos ?></div>
          <div>PAT's Abertos</div>
    </div>

    <div class="kpi-card">
      <i class="bi bi-stopwatch"></i>
        <div class="num" style="font-size:24px;line-height:1.1"><?= $execLabel ?></div>
          <div>PAT→Execução</div>
    </div>

    <div class="kpi-card">
      <i class="bi bi-check-circle" style="color:#2ca59a"></i>
        <div class="num"><?= $patsConcluidos ?></div>
          <div>PAT's Concluídos</div>
    </div>
  </div>




<!-- Dashboard-PIZZA -->
  <!-- Painel grande do gráfico circular -->
<div class="panel panel-estado">
  <h4>Estados das Peças</h4>

  <div class="estado-layout">
    <div class="estado-chart-box">
      <canvas id="estadoChart"></canvas>
    </div>

    <div class="legend-container">

      <div class="legend-text">
        <div class="legend-item">
          <div class="legend-color" style="background: #28a745;"></div>
          <span>Disponível</span>
        </div>

        <div class="legend-item">
          <div class="legend-color" style="background: #6f42c1;"></div>
          <span>PAT</span>
        </div>

        <div class="legend-item">
          <div class="legend-color" style="background: #2470dc;"></div>
          <span>Laboratório</span>
        </div>

        <div class="legend-item">
          <div class="legend-color" style="background: #dc3545;"></div>
          <span>Abater</span>
        </div>

        <div class="legend-item">
          <div class="legend-color" style="background: #20c997;"></div>
          <span>Cliente</span>
        </div>

        <div class="legend-item">
          <div class="legend-color" style="background: #ffc107;"></div>
          <span>Desconhecido</span>
        </div>

        <div class="legend-item">
          <div class="legend-color" style="background: #17a2b8;"></div>
          <span>Devolução</span>
        </div>

        <div class="legend-item">
          <div class="legend-color" style="background: #fd7e14;"></div>
          <span>Fornecedor<span class="forn-rep-full">(Reparação)</span><span class="forn-rep-short">(Rep)</span></span>
        </div>


        <div class="legend-item">
          <div class="legend-color" style="background: #8c564b;"></div>
          <span>Parceiro</span>
        </div>

        <div class="legend-item">
          <div class="legend-color" style="background: #47372A;"></div>
          <span>Spares</span>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="panel" style="margin-bottom:20px;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
        <h4 style="margin:0;">Atividade Recente — Inventário</h4>
        <a href="app.php?page=auditoria" style="font-size:13px; color:#cba35c; text-decoration:none;">Ver tudo →</a>
    </div>
    <?php if (empty($actividadeRecente)): ?>
        <div style="text-align:center; color:#9ca3af; padding:24px; font-size:14px;">
            <i class="bi bi-clock-history" style="font-size:28px; display:block; margin-bottom:8px; opacity:.4;"></i>
            Sem atividade registada.
        </div>
    <?php else: ?>
        <?php $actividadeRecenteDash = array_slice($actividadeRecente, 0, 4); ?>
        <div class="dash-atividade-grid">
            <?php foreach ($actividadeRecenteDash as $a):

                $icons = [
                    "criação"               => ["bi-plus-circle-fill",  "#22c55e"],
                    "criacao"               => ["bi-plus-circle-fill",  "#22c55e"],
                    "estado"                => ["bi-arrow-left-right",  "#3b82f6"],
                    "parceiro"              => ["bi-building",           "#8b5cf6"],
                    "eliminação"            => ["bi-trash3-fill",        "#ef4444"],
                    "eliminacao"            => ["bi-trash3-fill",        "#ef4444"],
                    "produto"               => ["bi-tag",                "#f59e0b"],
                    "categoria"             => ["bi-folder",             "#0ea5e9"],
                    "sn"                    => ["bi-upc-scan",           "#6366f1"],
                    "cod_barras"            => ["bi-barcode",            "#64748b"],
                    "envio"                 => ["bi-truck",              "#06b6d4"],
                    "pat"                   => ["bi-headset",            "#6f42c1"],
                    "revisão"               => ["bi-clipboard-check",   "#0d9488"],
                    "revisao"               => ["bi-clipboard-check",   "#0d9488"],
                    "local"                 => ["bi-geo-alt",            "#78716c"],
                    "cliente"               => ["bi-person",             "#2563eb"],
                    "notas"                 => ["bi-journal-text",       "#a855f7"],
                    "observacoes"           => ["bi-chat-left-text",     "#7c3aed"],
                    "observações"           => ["bi-chat-left-text",     "#7c3aed"],
                    "prioridade"            => ["bi-exclamation-circle", "#dc2626"],
                    "data_limite"           => ["bi-calendar-event",     "#059669"],
                    "data_recepcao"         => ["bi-calendar-check",     "#0284c7"],
                    "data_inicio"           => ["bi-play-circle",        "#16a34a"],
                    "tecnico"               => ["bi-person-badge",       "#d97706"],
                    "garantia"              => ["bi-shield-check",       "#2563eb"],
                    "numero_serie"          => ["bi-upc",                "#6366f1"],
                    "fotografia"            => ["bi-image",              "#f43f5e"],
                    "quantidade"            => ["bi-123",                "#64748b"],
                    "preco"                 => ["bi-currency-euro",      "#047857"],
                    "referencia"            => ["bi-hash",               "#6b7280"],
                ];
                [$ico, $cor] = $icons[$a["campo"]] ?? [
                    "bi-pencil-square",
                    "#6b7280",
                ];
                $nome = $a["produto"] ?: "Peça #" . $a["peca_id"];
                $diff = time() - strtotime($a["data_alteracao"]);
                $ago =
                    $diff < 60
                        ? "agora mesmo"
                        : ($diff < 3600
                            ? round($diff / 60) . "min atrás"
                            : ($diff < 86400
                                ? round($diff / 3600) . "h atrás"
                                : date(
                                    "d/m/Y H:i",
                                    strtotime($a["data_alteracao"]),
                                )));
                $descricao = match ($a["campo"]) {
                    "criação" => "Adicionada ao inventário",
                    "eliminação" => "Removida do inventário",
                    "estado" => htmlspecialchars($a["antes"]) .
                        " → " .
                        htmlspecialchars($a["depois"]),
                    "parceiro" => "Parceiro: " . htmlspecialchars($a["depois"]),
                    "produto" => "Nome: " . htmlspecialchars($a["depois"]),
                    "categoria" => "Categoria: " .
                        htmlspecialchars($a["depois"]),
                    "sn" => "SN: " . htmlspecialchars($a["depois"]),
                    "cod_barras" => "Cód: " . htmlspecialchars($a["depois"]),
                    "envio" => htmlspecialchars($a["depois"]),
                    default => ucfirst(htmlspecialchars($a["campo"])) .
                        ": " .
                        htmlspecialchars($a["depois"]),
                };
                ?>
        <div style="display:flex; gap:10px; padding:12px 14px; background:#f9fafb; border:1px solid #f0f0f0; border-radius:10px; border-left:3px solid <?= $cor ?>;">
            <div style="width:34px; height:34px; border-radius:50%; background:<?= $cor ?>1a; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <i class="bi <?= $ico ?>" style="font-size:16px; color:<?= $cor ?>;"></i>
            </div>
            <div style="flex:1; min-width:0;">
                <div style="font-size:13px; font-weight:700; color:#111827; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                    <?= htmlspecialchars($nome) ?>
                </div>
                <?php if ($a["sn"]): ?>
                <div style="font-size:11px; color:#9ca3af; margin-top:1px; font-family:monospace;">
                    SN: <?= htmlspecialchars($a["sn"]) ?>
                </div>
                <?php endif; ?>
                <div style="font-size:12px; color:#374151; margin-top:4px; font-weight:500;">
                    <?= $descricao ?>
                </div>
                <div style="font-size:11px; color:#9ca3af; margin-top:4px;">
                    <?= htmlspecialchars($a["utilizador"]) ?> · <?= $ago ?>
                </div>
            </div>
        </div>
        <?php
            endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="panel-grid-2">
  <!-- Opção B — Ranking de Parceiros por carga atual -->
  <div class="panel">
    <h4><i class="bi bi-people" style="color:#c9a14a; margin-right:6px;"></i>Ranking de Parceiros — carga atual</h4>
    <?php if (empty($rankingParceiros)): ?>
      <div class="table-empty-state"><i class="bi bi-people"></i>Sem peças atribuídas a parceiros.</div>
    <?php else: ?>
    <div class="table-responsive scroll-oculto">
    <table class="table">
      <thead>
        <tr>
          <th>Parceiro</th>
          <th class="nowrap" style="text-align:center;">Em curso</th>
          <th class="nowrap" style="text-align:center;">Total</th>
          <th style="width:34%;">Carga</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rankingParceiros as $rp):

            $emCurso = (int) $rp["em_curso"];
            $tot = (int) $rp["total"];
            $pct = $rankingMax > 0 ? round(($emCurso / $rankingMax) * 100) : 0;
            ?>
        <tr>
          <td style="font-weight:600;"><?= htmlspecialchars(
              $rp["parceiro"],
          ) ?></td>
          <td class="nowrap" style="text-align:center;font-weight:700;color:#b45309;"><?= $emCurso ?></td>
          <td class="nowrap" style="text-align:center;color:#6b7280;"><?= $tot ?></td>
          <td>
            <div style="background:#f1f3f5;border-radius:999px;height:8px;overflow:hidden;">
              <div style="width:<?= $pct ?>%;height:100%;background:linear-gradient(90deg,#c9a14a,#e0bd6e);border-radius:999px;"></div>
            </div>
          </td>
        </tr>
        <?php
        endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Opção G — Peças Paradas (sem movimento) -->
  <div class="panel">
    <h4><i class="bi bi-hourglass-bottom" style="color:#c9a14a; margin-right:6px;"></i>Peças Paradas <span style="font-weight:400;color:#9ca3af;font-size:13px;">(sem movimento há +7 dias)</span></h4>
    <?php if (empty($pecasParadas)): ?>
      <div class="table-empty-state"><i class="bi bi-check2-circle"></i>Nenhuma peça parada. Tudo em dia.</div>
    <?php else: ?>
    <div class="table-responsive scroll-oculto">
    <table class="table">
      <thead>
        <tr>
          <th>Peça</th>
          <th>Estado</th>
          <th class="nowrap" style="text-align:center;">Dias parada</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pecasParadas as $pp):

            $dias = (int) $pp["dias_parada"];
            $corDias =
                $dias >= 30 ? "#dc3545" : ($dias >= 15 ? "#f59e0b" : "#6b7280");
            $nome = $pp["produto"] ?: "Peça #" . $pp["id"];
            ?>
        <tr>
          <td>
            <a href="app.php?page=peca&id=<?= (int) $pp[
                "id"
            ] ?>" style="font-weight:600;color:#1f2937;text-decoration:none;"><?= htmlspecialchars(
    $nome,
) ?></a>
            <?php if (
                !empty($pp["sn"])
            ): ?><div style="font-size:11px;color:#9ca3af;font-family:monospace;">SN: <?= htmlspecialchars(
    $pp["sn"],
) ?></div><?php endif; ?>
          </td>
          <td><?= estadoBolha($pp["estado"]) ?></td>
          <td class="nowrap" style="text-align:center;font-weight:700;color:<?= $corDias ?>;"><?= $dias ?></td>
        </tr>
        <?php
        endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>
