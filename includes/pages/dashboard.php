<?php
$totalPecas = countQuery($pdo, "SELECT COUNT(*) FROM pecas");

$patsAtivos = countQuery($pdo, "SELECT COUNT(*) FROM pats WHERE estado NOT IN ('Resolvido','Concluído','Cancelado')");

$ordensAtivas = countQuery($pdo, "SELECT COUNT(*) FROM envios WHERE estado='Ativa'");

$ordensCanceladas = countQuery($pdo, "SELECT COUNT(*) FROM envios WHERE estado='Cancelada'");

$ordensConcluidas = countQuery($pdo, "SELECT COUNT(*) FROM envios WHERE estado='Concluida'");

$ultimoPat = $pdo->query("SELECT created_at FROM pats ORDER BY created_at DESC LIMIT 1")->fetchColumn() ?: null;
?>

<!-- Dashboard-Quadrados --> 
  <div class="kpi-row">
    <div class="kpi-card">
      <i class="bi bi-box"></i>
        <div class="num"> <?=$totalPecas?></div>
          <div>Total Peças</div>
    </div>

    <div class="kpi-card">
      <i class="bi bi-clipboard"></i>
        <div class="num"><?=$patsAtivos?></div>
          <div>PATs Ativos</div>
    </div>

    <div class="kpi-card">
      <i class="bi bi-send"></i>
        <div class="num"><?=$ordensAtivas?></div>
          <div>Ordens Ativas</div>
    </div>

    <div class="kpi-card">
      <i class="bi bi-x-circle" style="color:#e05d57"></i>
        <div class="num"><?=$ordensCanceladas?></div>
          <div>Ordens Canceladas</div>
    </div>

    <div class="kpi-card">
      <i class="bi bi-check-circle" style="color:#2ca59a"></i>
        <div class="num"><?=$ordensConcluidas?></div>
          <div>Ordens Concluídas</div>
    </div>

    <div class="kpi-card">
      <i class="bi bi-stopwatch"></i>
        <div class="num" style="font-size:24px;line-height:1.1">1.5h</div>
          <div>~ PAT→Execução</div>
    </div>

    <div class="kpi-card">
      <i class="bi bi-calendar3"></i>
        <div class="num" style="font-size:18px;line-height:1.1"><?= $ultimoPat ? date('d/m', strtotime($ultimoPat)) : '-' ?></div>
          <div>Último PAT</div>    
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
          <span>Fornecedor(Reparação)</span>
        </div>

        <div class="legend-item">
          <div class="legend-color" style="background: #495057;"></div>
          <span>OT</span>
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
        <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:10px; max-height:230px; overflow:hidden;">
            <?php foreach ($actividadeRecente as $a):
            $icons = [
                'criação'    => ['bi-plus-circle-fill', '#22c55e'],
                'estado'     => ['bi-arrow-left-right', '#3b82f6'],
                'parceiro'   => ['bi-building',          '#8b5cf6'],
                'eliminação' => ['bi-trash3-fill',       '#ef4444'],
                'produto'    => ['bi-tag',                '#f59e0b'],
                'categoria'  => ['bi-folder',             '#0ea5e9'],
                'sn'         => ['bi-upc-scan',           '#6366f1'],
                'cod_barras' => ['bi-barcode',            '#64748b'],
                'envio'      => ['bi-send',               '#06b6d4'],
            ];
            [$ico, $cor] = $icons[$a['campo']] ?? ['bi-pencil', '#6b7280'];
            $nome = $a['produto'] ?: ('Peça #' . $a['peca_id']);
            $diff = time() - strtotime($a['data_alteracao']);
            $ago  = $diff < 60   ? 'agora mesmo'
                  : ($diff < 3600  ? round($diff/60).'min atrás'
                  : ($diff < 86400 ? round($diff/3600).'h atrás'
                  : date('d/m/Y H:i', strtotime($a['data_alteracao']))));
            $descricao = match($a['campo']) {
                'criação'    => 'Adicionada ao inventário',
                'eliminação' => 'Removida do inventário',
                'estado'     => htmlspecialchars($a['antes']) . ' → ' . htmlspecialchars($a['depois']),
                'parceiro'   => 'Parceiro: ' . htmlspecialchars($a['depois']),
                'produto'    => 'Nome: ' . htmlspecialchars($a['depois']),
                'categoria'  => 'Categoria: ' . htmlspecialchars($a['depois']),
                'sn'         => 'SN: ' . htmlspecialchars($a['depois']),
                'cod_barras' => 'Cód: ' . htmlspecialchars($a['depois']),
                'envio'      => htmlspecialchars($a['depois']),
                default      => ucfirst(htmlspecialchars($a['campo'])) . ': ' . htmlspecialchars($a['depois']),
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
                <?php if ($a['sn']): ?>
                <div style="font-size:11px; color:#9ca3af; margin-top:1px; font-family:monospace;">
                    SN: <?= htmlspecialchars($a['sn']) ?>
                </div>
                <?php endif; ?>
                <div style="font-size:12px; color:#374151; margin-top:4px; font-weight:500;">
                    <?= $descricao ?>
                </div>
                <div style="font-size:11px; color:#9ca3af; margin-top:4px;">
                    <?= htmlspecialchars($a['utilizador']) ?> · <?= $ago ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="panel-grid-2">
  <div class="panel">
    <h4>Tendência (6 meses)</h4>
    <canvas id="trendChart"></canvas>
  </div>

  <div class="panel">
    <h4>Stock por Categorias</h4>
    <canvas id="categoriaChart"></canvas>
  </div>
</div>



