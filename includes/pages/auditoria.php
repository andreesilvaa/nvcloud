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
}
?>

  <section class="config-card auditoria-card">
    <div class="auditoria-header">
    </div>

    <div class="auditoria-box">
        <div class="panel-header-row" style="margin-bottom:18px;">
            <h3 class="auditoria-title" style="margin:0;">Registo de Atividades</h3>
            <div class="auditoria-botoes">
                <button type="submit" form="auditoriaFiltrosForm" class="btn-audit btn-filtrar">🔍 Filtrar</button>
                <button type="button" class="btn-audit btn-limpar" id="btnLimparAuditoria">Limpar filtros</button>
                <a href="exportar_auditoria_csv.php?audit_user=<?= urlencode($filtroUtilizador) ?>&audit_action=<?= urlencode($filtroAcao) ?>" class="btn-audit btn-exportar">Exportar CSV</a>
            </div>
        </div>

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
        </form>

        <div class="auditoria-tabela-wrap">
            <table class="auditoria-tabela">
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
                                if ($log['campo'] === 'criação' || $log['campo'] === 'eliminação') {
                                  echo htmlspecialchars(ucfirst($log['campo']));
                                } else {
                                  echo 'Alteração de ' . htmlspecialchars($log['campo']);
                                }
                                ?>
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
                            <td colspan="5" class="auditoria-vazia">Não foram encontrados registos para os filtros selecionados.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
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



