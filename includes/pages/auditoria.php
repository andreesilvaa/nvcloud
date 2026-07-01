<?php

$utilizadoresAuditoria = [];
$acoesAuditoria = [];
$auditoriaLogs = [];
$filtroUtilizador = '';
$filtroAcao = '';

if ($page === 'auditoria') {
  $filtroUtilizador = trim($_GET['audit_user'] ?? '');
  $filtroAcao = trim($_GET['audit_action'] ?? '');

  $utilizadoresAuditoria = $pdo->query("
    SELECT DISTINCT utilizador
    FROM  historico
    WHERE utilizador IS NOT NULL AND utilizador <> ''
    ORDER BY utilizador ASC
  ")->fetchAll(PDO::FETCH_COLUMN);

  $acoesAuditoria = $pdo->query("
    SELECT DISTINCT campo
    FROM historico
    WHERE campo IS NOT NULL AND campo <> ''
    ORDER BY campo ASC
  ")->fetchAll(PDO::FETCH_COLUMN);

  $whereAuditoria = [];
  $paramsAuditoria =[];

  if ($filtroUtilizador !== '') {
    $whereAuditoria[] = "utilizador = ?";
    $paramsAuditoria[] = $filtroUtilizador;
  }

  if ($filtroAcao !== '') {
    $whereAuditoria[] = "campo = ?";
    $paramsAuditoria[] = $filtroAcao; 
  }

  $whereSqlAud = $whereAuditoria ? (" WHERE " . implode(" AND ", $whereAuditoria)) : "";

  // Paginação (50 por página)
  $audPerPage = 50;
  $audPag     = max(1, (int)($_GET['p'] ?? 1));
  $stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM historico" . $whereSqlAud);
  $stmtCnt->execute($paramsAuditoria);
  $audTotal   = (int)$stmtCnt->fetchColumn();
  $audPaginas = max(1, (int)ceil($audTotal / $audPerPage));
  if ($audPag > $audPaginas) { $audPag = $audPaginas; }
  $audOffset  = ($audPag - 1) * $audPerPage;

  $sqlAuditoria = "SELECT id, peca_id, campo, antes, depois, utilizador, data_alteracao FROM historico"
                . $whereSqlAud
                . " ORDER BY data_alteracao DESC, id DESC"
                . " LIMIT $audPerPage OFFSET $audOffset";

    $stmtAuditoria = $pdo->prepare($sqlAuditoria);
    $stmtAuditoria->execute($paramsAuditoria);
    $auditoriaLogs = $stmtAuditoria->fetchAll();

  // querystring dos filtros para manter na paginação
  $audExtra = '';
  if ($filtroUtilizador !== '') { $audExtra .= '&audit_user=' . urlencode($filtroUtilizador); }
  if ($filtroAcao !== '')       { $audExtra .= '&audit_action=' . urlencode($filtroAcao); }

  // ── Resumo rápido (apenas leitura) ──
  $audHoje = (int)$pdo->query("SELECT COUNT(*) FROM historico WHERE DATE(data_alteracao) = CURDATE()")->fetchColumn();
  $audUtilizadoresHoje = (int)$pdo->query("SELECT COUNT(DISTINCT utilizador) FROM historico WHERE DATE(data_alteracao) = CURDATE() AND utilizador IS NOT NULL AND utilizador <> ''")->fetchColumn();
  $audUltima = $pdo->query("SELECT MAX(data_alteracao) FROM historico")->fetchColumn();
  $audUltimaLabel = $audUltima ? date('H:i', strtotime($audUltima)) : '—';
}
?>

  <section class="config-card auditoria-card">
    <div class="auditoria-header">
    </div>

    <!-- WF2: Filtros (esquerda) | Resumo rápido (direita) -->
    <div class="aud-topo">
      <div class="panel aud-filtros-panel">
        <h4><i class="bi bi-funnel" style="color:#c9a14a; margin-right:6px;"></i>Filtros</h4>
        <form method="get" class="auditoria-filtros" id="auditoriaFiltrosForm">
          <input type="hidden" name="page" value="auditoria">
            <div class="auditoria-filtro">
                <label for="audit_user">Utilizador</label>
                <select name="audit_user" id="audit_user">
                    <option value="">Todos</option>
                    <?php foreach ($utilizadoresAuditoria as $utilizador): ?>
                        <option value="<?= htmlspecialchars($utilizador) ?>" <?= $filtroUtilizador === $utilizador ? 'selected' : '' ?>>
                            <?= htmlspecialchars($utilizador) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="auditoria-filtro">
                <label for="audit_action">Ação</label>
                <select name="audit_action" id="audit_action">
                    <option value="">Todas</option>
                    <?php foreach ($acoesAuditoria as $acao): ?>
                        <option value="<?= htmlspecialchars($acao) ?>" <?= $filtroAcao === $acao ? 'selected' : '' ?>>
                            <?= htmlspecialchars($acao) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="aud-filtros-botoes">
                <button type="submit" class="btn-audit btn-filtrar"><i class="bi bi-search"></i> Filtrar</button>
                <button type="button" class="btn-audit btn-limpar" id="btnLimparAuditoria">Limpar</button>
            </div>
        </form>
      </div>

      <div class="panel aud-resumo-panel">
        <h4><i class="bi bi-speedometer2" style="color:#c9a14a; margin-right:6px;"></i>Resumo rápido</h4>
        <div class="aud-resumo-item">
          <span class="aud-resumo-label"><i class="bi bi-calendar-day"></i> Hoje</span>
          <span class="aud-resumo-val"><?= (int)$audHoje ?> alterações</span>
        </div>
        <div class="aud-resumo-item">
          <span class="aud-resumo-label"><i class="bi bi-people"></i> Utilizadores ativos</span>
          <span class="aud-resumo-val"><?= (int)$audUtilizadoresHoje ?></span>
        </div>
        <div class="aud-resumo-item">
          <span class="aud-resumo-label"><i class="bi bi-clock-history"></i> Última alteração</span>
          <span class="aud-resumo-val"><?= htmlspecialchars($audUltimaLabel) ?></span>
        </div>
        <a href="exportar_auditoria_csv.php?audit_user=<?= urlencode($filtroUtilizador) ?>&audit_action=<?= urlencode($filtroAcao) ?>" class="btn-audit btn-exportar" style="width:100%; margin-top:6px;"><i class="bi bi-download"></i> Exportar CSV</a>
      </div>
    </div>

    <style>
      .aud-topo{ display:grid; grid-template-columns:minmax(0,1.4fr) minmax(0,1fr); gap:18px; margin-bottom:18px; align-items:stretch; }
      .aud-topo .panel{ height:100%; box-sizing:border-box; }
      .aud-filtros-panel h4, .aud-resumo-panel h4{ margin:0 0 16px; font-size:16px; }
      .aud-filtros-panel .auditoria-filtros{ display:flex; flex-wrap:wrap; gap:14px; align-items:end; margin:0; height:100%; align-content:space-between; }
      .aud-filtros-panel .auditoria-filtro{ flex:1 1 200px; min-width:160px; margin:0; }
      .aud-filtros-botoes{ display:flex; gap:8px; flex:0 0 100%; margin-top:4px; }
      .aud-resumo-item{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 0; border-bottom:1px solid #f1f3f6; font-size:14px; }
      .aud-resumo-item:first-of-type{ padding-top:0; }
      .aud-resumo-label{ color:#6b7280; display:inline-flex; align-items:center; gap:8px; }
      .aud-resumo-label i{ color:#9ca3af; }
      .aud-resumo-val{ font-weight:700; color:#1f2937; }
      body.dark-mode .aud-resumo-item{ border-color:#2b3647; }
      body.dark-mode .aud-resumo-val{ color:#f3f4f6; }
      @media (max-width:900px){ .aud-topo{ grid-template-columns:1fr; } .aud-topo .panel{ height:auto; } }

      /* Cartões de auditoria (desktop) — substituem a tabela básica */
      .aud-cards-grid{ display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:14px; }
      .aud-card{ background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:14px 16px; box-shadow:0 1px 5px rgba(0,0,0,.05); display:flex; flex-direction:column; gap:10px; }
      .aud-card-top{ display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
      .aud-card-id{ font-size:11.5px; color:#9ca3af; font-weight:600; }
      .aud-card-utilizador{ font-weight:700; font-size:14.5px; color:#1e293b; margin-top:2px; }
      .aud-card-badge{ flex-shrink:0; padding:3px 11px; border-radius:999px; font-size:11.5px; font-weight:600; white-space:nowrap; }
      .aud-card-linha{ display:flex; align-items:center; gap:8px; font-size:12.5px; color:#6b7280; flex-wrap:wrap; padding-top:10px; border-top:1px solid #f1f3f6; }
      .aud-card-linha .aud-peca{ font-weight:600; color:#374151; }
      .aud-card-antes-depois{ display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
      .aud-card-antes{ color:#d9534f; }
      .aud-card-depois{ color:#28a745; font-weight:600; }
      .aud-card-data{ font-size:11.5px; color:#9ca3af; text-align:right; }
      body.dark-mode .aud-card{ background:#1e2533; border-color:#374151; }
      body.dark-mode .aud-card-utilizador{ color:#f1f5f9; }
      body.dark-mode .aud-card-linha{ border-color:#2b3647; }
      @media (max-width:640px){ .aud-cards-grid{ display:none; } }
    </style>

    <div class="auditoria-box">
        <div class="panel-header-row" style="margin-bottom:18px;">
            <h3 class="auditoria-title" style="margin:0;">Registo de Atividades</h3>
        </div>

        <div class="aud-cards-grid">
          <?php if (!empty($auditoriaLogs)): ?>
            <?php foreach ($auditoriaLogs as $log):
                $campoAud = (string)$log['campo'];
                $audCoresAcao = [
                    'criação'       => ['#dcfce7', '#15803d'],
                    'eliminacao'    => ['#fee2e2', '#b91c1c'],
                    'eliminação'    => ['#fee2e2', '#b91c1c'],
                    'estado'        => ['#dbeafe', '#1d4ed8'],
                    'parceiro'      => ['#ede9fe', '#6d28d9'],
                    'produto'       => ['#fef3c7', '#b45309'],
                    'categoria'     => ['#e0f2fe', '#0369a1'],
                    'sn'            => ['#e0e7ff', '#4338ca'],
                    'cod_barras'    => ['#f1f5f9', '#475569'],
                    'envio'         => ['#cffafe', '#0e7490'],
                    'pat'           => ['#ede9fe', '#6d28d9'],
                    'revisao'       => ['#ccfbf1', '#0f766e'],
                    'revisão'       => ['#ccfbf1', '#0f766e'],
                    'local'         => ['#f5f5f4', '#57534e'],
                    'cliente'       => ['#dbeafe', '#1d4ed8'],
                ];
                if ($campoAud === 'criação')        { $aTxt='Criação'; }
                elseif ($campoAud === 'eliminação') { $aTxt='Eliminação'; }
                else                                { $aTxt='Alteração de ' . $campoAud; }
                [$aBg, $aFg] = $audCoresAcao[$campoAud] ?? ['#f3f4f6', '#374151'];
                $audAntes  = (string)($log['antes'] ?? '');
                $audDepois = (string)($log['depois'] ?? '');
                ?>
            <div class="aud-card">
              <div class="aud-card-top">
                <div>
                  <div class="aud-card-id">#<?= (int)$log['id'] ?></div>
                  <div class="aud-card-utilizador"><?= htmlspecialchars($log['utilizador']) ?></div>
                </div>
                <span class="aud-card-badge" style="background:<?= $aBg ?>; color:<?= $aFg ?>;"><?= htmlspecialchars($aTxt) ?></span>
              </div>
              <div class="aud-card-linha">
                <?php if ((int)$log['peca_id'] > 0): ?>
                  <span class="aud-peca">Peça: #<?= (int)$log['peca_id'] ?></span>
                  <?php if ($audAntes !== '' || $audDepois !== ''): ?>
                    <span class="aud-card-antes-depois">
                      <span>Antes: <span class="aud-card-antes"><?= htmlspecialchars($audAntes) ?></span></span>
                      <span>Depois: <span class="aud-card-depois"><?= htmlspecialchars($audDepois) ?></span></span>
                    </span>
                  <?php endif; ?>
                <?php else: ?>
                  <span><?= htmlspecialchars($audDepois !== '' ? $audDepois : $audAntes) ?></span>
                <?php endif; ?>
              </div>
              <div class="aud-card-data"><?= date('d/m/Y H:i', strtotime($log['data_alteracao'])) ?></div>
            </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="table-empty-state" style="grid-column:1/-1;"><i class="bi bi-inbox"></i>Não foram encontrados registos para os filtros selecionados.</div>
          <?php endif; ?>
        </div>

        <div class="table-responsive mv-table-wrap" style="display:none;">
            <table class="table" id="tabelaAuditoria">
                <thead>
                    <tr>
                        <th style="width: 80px;">ID</th>
                        <th style="width: 220px;">Utilizador</th>
                        <th style="width: 240px;">Ação</th>
                        <th>Detalhes</th>
                        <th style="width: 180px;">Data</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($auditoriaLogs)): ?>
                    <?php foreach ($auditoriaLogs as $log): ?>
                            <tr>
                              <td>#<?= (int)$log['id'] ?></td>
                              <td><?= htmlspecialchars($log['utilizador']) ?></td>
                              <td>
                                <?php
                                $campoAud = (string)$log['campo'];
                                $audCoresAcao = [
                                    'criação'       => ['#dcfce7', '#15803d'],
                                    'eliminacao'    => ['#fee2e2', '#b91c1c'],
                                    'eliminação'    => ['#fee2e2', '#b91c1c'],
                                    'estado'        => ['#dbeafe', '#1d4ed8'],
                                    'parceiro'      => ['#ede9fe', '#6d28d9'],
                                    'produto'       => ['#fef3c7', '#b45309'],
                                    'categoria'     => ['#e0f2fe', '#0369a1'],
                                    'sn'            => ['#e0e7ff', '#4338ca'],
                                    'cod_barras'    => ['#f1f5f9', '#475569'],
                                    'envio'         => ['#cffafe', '#0e7490'],
                                    'pat'           => ['#ede9fe', '#6d28d9'],
                                    'revisao'       => ['#ccfbf1', '#0f766e'],
                                    'revisão'       => ['#ccfbf1', '#0f766e'],
                                    'local'         => ['#f5f5f4', '#57534e'],
                                    'cliente'       => ['#dbeafe', '#1d4ed8'],
                                ];
                                if ($campoAud === 'criação')        { $aTxt='Criação'; }
                                elseif ($campoAud === 'eliminação') { $aTxt='Eliminação'; }
                                else                                { $aTxt='Alteração de ' . $campoAud; }
                                [$aBg, $aFg] = $audCoresAcao[$campoAud] ?? ['#f3f4f6', '#374151'];
                                ?>
                                <span style="display:inline-block; padding:2px 10px; border-radius:999px; font-size:11.5px; font-weight:600; background:<?= $aBg ?>; color:<?= $aFg ?>;"><?= htmlspecialchars($aTxt) ?></span>
                              </td>
                              <td>
                    <?php
                      $audAntes  = (string)($log['antes'] ?? '');
                      $audDepois = (string)($log['depois'] ?? '');
                      if ((int)$log['peca_id'] > 0):
                    ?>
                      Peça #<?= (int)$log['peca_id'] ?>
                      <?php if ($audAntes !== '' || $audDepois !== ''): ?>
                        — antes: <span style="color:#d9534f;"><?= htmlspecialchars($audAntes) ?></span>
                        | depois: <span style="color:#28a745;"><?= htmlspecialchars($audDepois) ?></span>
                      <?php endif; ?>
                    <?php else: ?>
                      <?= htmlspecialchars($audDepois !== '' ? $audDepois : $audAntes) ?>
                    <?php endif; ?>
                  </td>
    <td><?= date('d/m/Y H:i', strtotime($log['data_alteracao'])) ?></td>
</tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="table-empty-state"><i class="bi bi-inbox"></i>Não foram encontrados registos para os filtros selecionados.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

<!-- ── Auditoria · Cards mobile (≤640px) ── -->
<div class="mv-cards">
<?php if (!empty($auditoriaLogs)): ?>
    <?php foreach ($auditoriaLogs as $log):
        $campoAud = (string)$log['campo'];
        $audCoresAcao = [
            'criação'       => ['#dcfce7', '#15803d'],
            'eliminacao'    => ['#fee2e2', '#b91c1c'],
            'eliminação'    => ['#fee2e2', '#b91c1c'],
            'estado'        => ['#dbeafe', '#1d4ed8'],
            'parceiro'      => ['#ede9fe', '#6d28d9'],
            'produto'       => ['#fef3c7', '#b45309'],
            'categoria'     => ['#e0f2fe', '#0369a1'],
            'sn'            => ['#e0e7ff', '#4338ca'],
            'cod_barras'    => ['#f1f5f9', '#475569'],
            'envio'         => ['#cffafe', '#0e7490'],
            'pat'           => ['#ede9fe', '#6d28d9'],
            'revisao'       => ['#ccfbf1', '#0f766e'],
            'revisão'       => ['#ccfbf1', '#0f766e'],
            'local'         => ['#f5f5f4', '#57534e'],
            'cliente'       => ['#dbeafe', '#1d4ed8'],
        ];
        if ($campoAud === 'criação')        { $aTxt='Criação'; }
        elseif ($campoAud === 'eliminação') { $aTxt='Eliminação'; }
        else                                { $aTxt='Alteração de ' . $campoAud; }
        [$aBg, $aFg] = $audCoresAcao[$campoAud] ?? ['#f3f4f6', '#374151'];
        $audAntes  = (string)($log['antes'] ?? '');
        $audDepois = (string)($log['depois'] ?? '');
        ?>
    <div class="mv-card">
        <div class="mv-card-header">
            <div>
                <div class="mv-card-title"><?= htmlspecialchars($log['utilizador']) ?></div>
                <div class="mv-card-sub">#<?= (int)$log['id'] ?></div>
            </div>
            <span style="padding:2px 10px;border-radius:999px;font-size:11px;font-weight:600;white-space:nowrap;flex-shrink:0;background:<?= $aBg ?>;color:<?= $aFg ?>;"><?= htmlspecialchars($aTxt) ?></span>
        </div>
        <div class="mv-card-row" style="align-items:flex-start;">
            <span class="mv-card-row-label">Detalhes</span>
            <span class="mv-card-row-val">
                <?php if ((int)$log['peca_id'] > 0): ?>
                    Peça #<?= (int)$log['peca_id'] ?>
                    <?php if ($audAntes !== '' || $audDepois !== ''): ?>
                        <br><span style="color:#d9534f;"><?= htmlspecialchars($audAntes) ?></span> → <span style="color:#28a745;"><?= htmlspecialchars($audDepois) ?></span>
                    <?php endif; ?>
                <?php else: ?>
                    <?= htmlspecialchars($audDepois !== '' ? $audDepois : $audAntes) ?>
                <?php endif; ?>
            </span>
        </div>
        <div class="mv-card-row">
            <span class="mv-card-row-label">Data</span>
            <span class="mv-card-row-val"><?= date('d/m/Y H:i', strtotime($log['data_alteracao'])) ?></span>
        </div>
    </div>
    <?php endforeach; ?>
<?php else: ?>
    <div class="mv-cards-empty"><i class="bi bi-inbox"></i>Não foram encontrados registos para os filtros selecionados.</div>
<?php endif; ?>
</div>

        <?php if ($audPaginas > 1): ?>
        <div style="display:flex; gap:6px; flex-wrap:wrap; justify-content:center; align-items:center; margin-top:18px;">
          <?php
            $audWin = 2;
            $ini = max(1, $audPag - $audWin);
            $fim = min($audPaginas, $audPag + $audWin);
            $audLink = function (int $n, string $txt, bool $atual = false) use ($audExtra) {
                $cls = $atual ? 'btn btn-blue' : 'btn btn-grey';
                return '<a class="' . $cls . '" href="app.php?page=auditoria&p=' . $n . $audExtra . '">' . $txt . '</a>';
            };
            if ($audPag > 1)         { echo $audLink(1, '«') . $audLink($audPag - 1, '‹'); }
            if ($ini > 1)            { echo '<span style="color:#9ca3af;">…</span>'; }
            for ($i = $ini; $i <= $fim; $i++) { echo $audLink($i, (string)$i, $i === $audPag); }
            if ($fim < $audPaginas)  { echo '<span style="color:#9ca3af;">…</span>'; }
            if ($audPag < $audPaginas) { echo $audLink($audPag + 1, '›') . $audLink($audPaginas, '»'); }
          ?>
          <span style="color:#6b7280; font-size:13px; margin-left:8px;">
            Página <?= $audPag ?> de <?= $audPaginas ?> · <?= (int)$audTotal ?> registos
          </span>
        </div>
        <?php endif; ?>
    </div>
</section>



