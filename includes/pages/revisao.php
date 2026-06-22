<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'rever_peca') {
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) { exit('Ação inválida.'); }
    $revId   = (int)($_POST['rev_id'] ?? 0);
    $decisao = in_array($_POST['decisao'] ?? '', ['mantido','corrigido','abatido'], true) ? $_POST['decisao'] : '';
    $novoEstado = trim($_POST['novo_estado'] ?? '');
    $nota = trim($_POST['nota'] ?? '');
    $utilizador = $_SESSION['user_nome'] ?? 'Sistema';

    if ($revId > 0 && $decisao !== '') {
        // carregar a peça associada
        $st = $pdo->prepare("SELECT peca_id FROM revisoes_peca WHERE id = ?");
        $st->execute([$revId]);
        $pecaId = (int)$st->fetchColumn();

        // se corrigiu/abateu e escolheu novo estado válido, aplica-o à peça
        if (in_array($decisao, ['corrigido','abatido'], true) && $pecaId > 0
                && in_array($novoEstado, $estados, true)) {
            $stAnt = $pdo->prepare("SELECT estado FROM pecas WHERE id = ?");
            $stAnt->execute([$pecaId]);
            $estadoAntigo = (string)$stAnt->fetchColumn();
            $pdo->prepare("UPDATE pecas SET estado = ?, estado_desde = NOW() WHERE id = ?")
                    ->execute([$novoEstado, $pecaId]);
            $pdo->prepare("INSERT INTO historico (peca_id,campo,antes,depois,utilizador,data_alteracao)
                           VALUES (?, 'estado', ?, ?, ?, NOW())")
                    ->execute([$pecaId, $estadoAntigo, $novoEstado, $utilizador]);
        }

        $pdo->prepare("UPDATE revisoes_peca
                       SET decisao = ?, nota = ?, revisto_por = ?, revisto_em = NOW()
                       WHERE id = ?")
                ->execute([$decisao, $nota, $utilizador, $revId]);
        $_SESSION['mensagem_sucesso'] = 'Peça revista.';
    }
    $mesPar = preg_match('/^\d{4}-\d{2}$/', $_GET['mes'] ?? '') ? '&mes=' . $_GET['mes'] : '';
    header('Location: app.php?page=revisao' . $mesPar);
    exit;
}

/** @var PDO $pdo */
/** @var string $csrfToken */
/** @var array $estados */

require_once __DIR__ . '/../revisoes.php';

$periodo = preg_match('/^\d{4}-\d{2}$/', $_GET['mes'] ?? '') ? $_GET['mes'] : date('Y-m');

$revisoes = $pdo->prepare("
    SELECT r.id, r.peca_id, r.periodo, r.estado_no_momento, r.dias_parada,
           r.decisao, r.nota, r.revisto_por, r.revisto_em,
           p.produto, p.sn, p.parceiro, p.categoria, p.estado AS estado_atual
    FROM revisoes_peca r
    JOIN pecas p ON p.id = r.peca_id
    WHERE r.periodo = ?
    ORDER BY r.decisao ASC, r.dias_parada DESC
");
$revisoes->execute([$periodo]);
$linhas = $revisoes->fetchAll();

$totalPend   = 0;
$totalFeitas = 0;
foreach ($linhas as $l) {
    if ($l['decisao'] === 'pendente') $totalPend++;
    else $totalFeitas++;
}
$progPct = count($linhas) > 0 ? round($totalFeitas / count($linhas) * 100) : 0;
?>

<style>
/* ── Revisão de Peças ── */
.rev-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
}
.rev-header h2 {
    margin: 0;
    font-size: 22px;
    font-weight: 700;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 10px;
}
.rev-header h2 i { color: #3d82c4; font-size: 24px; }

.rev-mes-form {
    display: flex;
    gap: 8px;
    align-items: center;
}
.rev-mes-form input[type="month"] {
    padding: 7px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 13px;
    background: #fff;
    color: #374151;
}

/* KPIs */
.rev-kpis {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}
.rev-kpi {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 18px 16px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 1px 4px rgba(0,0,0,.04);
}
.rev-kpi-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}
.rev-kpi-icon.warn  { background: #fef9c3; color: #854d0e; }
.rev-kpi-icon.ok    { background: #dcfce7; color: #166534; }
.rev-kpi-icon.total { background: #dbeafe; color: #1e40af; }
.rev-kpi-body { min-width: 0; }
.rev-kpi-num {
    font-size: 26px;
    font-weight: 700;
    color: #1e293b;
    line-height: 1;
}
.rev-kpi-label {
    font-size: 12px;
    color: #6b7280;
    margin-top: 3px;
}

/* Barra de progresso */
.rev-progress-wrap {
    background: #f1f5f9;
    border-radius: 8px;
    padding: 14px 16px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 14px;
}
.rev-progress-bar-outer {
    flex: 1;
    height: 8px;
    background: #e2e8f0;
    border-radius: 99px;
    overflow: hidden;
}
.rev-progress-bar-inner {
    height: 100%;
    border-radius: 99px;
    background: linear-gradient(90deg, #3d82c4, #2563eb);
    transition: width .4s ease;
}
.rev-progress-label {
    font-size: 13px;
    font-weight: 600;
    color: #374151;
    white-space: nowrap;
}

/* Badges */
.badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 9px;
    border-radius: 99px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}
.badge-warn { background: #fef9c3; color: #854d0e; }
.badge-ok   { background: #dcfce7; color: #166534; }
.badge-info { background: #dbeafe; color: #1e40af; }
.badge-err  { background: #fee2e2; color: #991b1b; }

/* Tabela desktop */
.rev-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.rev-table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
    font-size: 13px;
}
.rev-table th {
    background: #f8fafc;
    border-bottom: 2px solid #e5e7eb;
    padding: 10px 12px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: #6b7280;
    white-space: nowrap;
}
.rev-table td {
    border-bottom: 1px solid #e2e8f0;
    padding: 11px 12px;
    vertical-align: middle;
    color: #374151;
}
.rev-table tbody tr:nth-child(even) td { background: #f8fafc; }
.rev-table tr:last-child td { border-bottom: none; }
.rev-table tr:hover td { background: #eef2f7; }
.rev-feita td { opacity: .6; }
.rev-nome-link {
    font-weight: 600;
    color: #1d4ed8;
    text-decoration: none;
}
.rev-nome-link:hover { text-decoration: underline; }
.rev-sn {
    font-family: monospace;
    font-size: 12px;
    color: #374151;
    background: #f1f5f9;
    padding: 2px 6px;
    border-radius: 4px;
}
.dias-pill {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 99px;
    font-size: 11px;
    font-weight: 700;
}
.dias-pill.alta  { background: #fee2e2; color: #991b1b; }
.dias-pill.media { background: #fef9c3; color: #854d0e; }
.dias-pill.baixa { background: #f1f5f9; color: #6b7280; }
.rev-nota-mini { font-size: 11px; color: #9ca3af; margin-top: 3px; }
.rev-btn {
    border: none;
    border-radius: 7px;
    padding: 5px 14px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: opacity .15s;
}
.rev-btn:hover { opacity: .85; }
.rev-btn-rever { background: #3d82c4; color: #fff; }
.rev-btn-editar { background: #f1f5f9; color: #374151; }

/* Cards mobile */
.rev-cards { display: none; }
.rev-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 1px 4px rgba(0,0,0,.04);
}
.rev-card + .rev-card { margin-top: 12px; }
.rev-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 12px;
}
.rev-card-title { font-weight: 700; font-size: 14px; color: #1e293b; }
.rev-card-sub { font-size: 12px; color: #6b7280; margin-top: 2px; }
.rev-card-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 5px 0;
    border-top: 1px solid #f1f5f9;
    font-size: 12px;
}
.rev-card-row-label { color: #9ca3af; font-weight: 600; text-transform: uppercase; font-size: 10px; }
.rev-card-row-val { color: #374151; text-align: right; }
.rev-card-footer {
    margin-top: 12px;
    display: flex;
    justify-content: flex-end;
}

/* Estado vazio */
.rev-vazia {
    text-align: center;
    padding: 48px 20px;
    color: #9ca3af;
}
.rev-vazia i { font-size: 48px; display: block; margin-bottom: 12px; }
.rev-vazia p { margin: 0; font-size: 15px; }

/* Modal */
.rev-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.rev-modal-overlay.open { display: flex; }
.rev-modal {
    background: #fff;
    border-radius: 14px;
    padding: 28px;
    max-width: 460px;
    width: 100%;
    box-shadow: 0 20px 60px rgba(0,0,0,.2);
    position: relative;
    max-height: 90vh;
    overflow-y: auto;
}
.rev-modal-close {
    position: absolute;
    top: 14px;
    right: 16px;
    background: none;
    border: none;
    font-size: 22px;
    cursor: pointer;
    color: #9ca3af;
    line-height: 1;
    padding: 2px 6px;
    border-radius: 6px;
    transition: background .15s;
}
.rev-modal-close:hover { background: #f1f5f9; color: #374151; }
.rev-modal h3 {
    margin: 0 0 4px;
    font-size: 17px;
    font-weight: 700;
    color: #1e293b;
    padding-right: 30px;
}
.rev-modal-sub { font-size: 12px; color: #9ca3af; margin: 0 0 20px; }
.rev-field { margin-bottom: 16px; }
.rev-field label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #6b7280;
    margin-bottom: 8px;
}
.rev-decisao-btns {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
}
.rev-decisao-btn {
    position: relative;
}
.rev-decisao-btn input[type="radio"] {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}
.rev-decisao-btn label {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 12px 8px;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    cursor: pointer;
    text-transform: none;
    letter-spacing: 0;
    font-size: 12px;
    font-weight: 600;
    color: #6b7280;
    transition: all .15s;
    background: #f8fafc;
    margin: 0;
}
.rev-decisao-btn label i { font-size: 18px; }
.rev-decisao-btn input:checked + label { border-color: currentColor; }
.rev-decisao-btn.mantido  input:checked + label { background:#dcfce7; color:#166534; border-color:#86efac; }
.rev-decisao-btn.corrigido input:checked + label { background:#dbeafe; color:#1e40af; border-color:#93c5fd; }
.rev-decisao-btn.abatido  input:checked + label { background:#fee2e2; color:#991b1b; border-color:#fca5a5; }
.rev-decisao-btn label:hover { border-color: #9ca3af; color: #374151; }

.rev-modal select,
.rev-modal textarea {
    width: 100%;
    padding: 9px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 13px;
    font-family: inherit;
    color: #374151;
    background: #fff;
    box-sizing: border-box;
    transition: border-color .15s;
}
.rev-modal select:focus,
.rev-modal textarea:focus {
    outline: none;
    border-color: #3d82c4;
    box-shadow: 0 0 0 3px rgba(61,130,196,.12);
}
.rev-modal textarea { resize: vertical; min-height: 80px; }
.rev-modal-footer {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid #f1f5f9;
}

@media (max-width: 900px) {
    .rev-kpis { grid-template-columns: repeat(3, 1fr); gap: 10px; }
    .rev-kpi { padding: 14px 12px; gap: 10px; }
    .rev-kpi-icon { width: 38px; height: 38px; font-size: 17px; }
    .rev-kpi-num { font-size: 22px; }
}

@media (max-width: 640px) {
    .rev-header { gap: 10px; }
    .rev-header h2 { font-size: 18px; }
    .rev-kpis { grid-template-columns: repeat(3, 1fr); gap: 8px; }
    .rev-kpi { padding: 12px 10px; gap: 8px; flex-direction: column; align-items: flex-start; }
    .rev-kpi-icon { width: 34px; height: 34px; font-size: 16px; }
    .rev-kpi-num { font-size: 20px; }
    /* Esconder tabela, mostrar cards */
    .rev-table-wrap { display: none; }
    .rev-cards { display: block; }
    /* Modal */
    .rev-modal { padding: 20px 16px; }
    .rev-decisao-btns { grid-template-columns: repeat(3, 1fr); }
    .rev-modal-footer { flex-direction: column-reverse; }
    .rev-modal-footer .btn { width: 100%; text-align: center; justify-content: center; }
}
</style>

<div class="card">
    <div class="rev-header">
        <h2><i class="bi bi-clipboard-check"></i> Revisão de Peças</h2>
        <form method="get" class="rev-mes-form">
            <input type="hidden" name="page" value="revisao">
            <input type="month" name="mes" value="<?= e($periodo) ?>">
            <button class="btn btn-blue" type="submit">Ver</button>
        </form>
    </div>

    <!-- KPIs -->
    <div class="rev-kpis">
        <div class="rev-kpi">
            <div class="rev-kpi-icon warn"><i class="bi bi-hourglass-split"></i></div>
            <div class="rev-kpi-body">
                <div class="rev-kpi-num"><?= $totalPend ?></div>
                <div class="rev-kpi-label">Por rever</div>
            </div>
        </div>
        <div class="rev-kpi">
            <div class="rev-kpi-icon ok"><i class="bi bi-check2-circle"></i></div>
            <div class="rev-kpi-body">
                <div class="rev-kpi-num"><?= $totalFeitas ?></div>
                <div class="rev-kpi-label">Revistas</div>
            </div>
        </div>
        <div class="rev-kpi">
            <div class="rev-kpi-icon total"><i class="bi bi-layers"></i></div>
            <div class="rev-kpi-body">
                <div class="rev-kpi-num"><?= count($linhas) ?></div>
                <div class="rev-kpi-label">Total</div>
            </div>
        </div>
    </div>

    <?php if (count($linhas) > 0): ?>
    <!-- Barra de progresso -->
    <div class="rev-progress-wrap">
        <span class="rev-progress-label"><?= $progPct ?>% concluído</span>
        <div class="rev-progress-bar-outer">
            <div class="rev-progress-bar-inner" style="width:<?= $progPct ?>%;"></div>
        </div>
        <span class="rev-progress-label" style="color:#9ca3af;font-weight:400;"><?= $totalFeitas ?>/<?= count($linhas) ?></span>
    </div>
    <?php endif; ?>

    <?php if (empty($linhas)): ?>
        <div class="rev-vazia">
            <i class="bi bi-clipboard-x"></i>
            <p>Sem peças para rever em <strong><?= e($periodo) ?></strong>.</p>
        </div>

    <?php else: ?>

    <div style="display:flex; justify-content:flex-end; margin-bottom:10px;">
        <div class="quick-search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" class="quick-search-input" data-table="#tabelaRevisao" data-empty="#tabelaRevisaoVazia" placeholder="Pesquisa rápida na tabela…">
        </div>
    </div>

    <!-- Tabela (desktop) -->
    <div class="rev-table-wrap">
        <table class="rev-table" id="tabelaRevisao">
            <thead>
                <tr>
                    <th>Peça / SN</th>
                    <th>Categoria</th>
                    <th>Parceiro</th>
                    <th>Estado anterior</th>
                    <th>Estado atual</th>
                    <th>Dias</th>
                    <th>Decisão</th>
                    <th>Revisto por</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($linhas as $r): ?>
                <?php
                $dias = (int)$r['dias_parada'];
                $diasCls = $dias >= 60 ? 'alta' : ($dias >= 30 ? 'media' : 'baixa');
                $ea = $r['estado_atual'];
                ?>
                <tr class="<?= $r['decisao'] !== 'pendente' ? 'rev-feita' : '' ?>">
                    <td>
                        <a href="app.php?page=peca&id=<?= $r['peca_id'] ?>" class="rev-nome-link"><?= e($r['produto']) ?></a>
                        <?php if ($r['sn']): ?>
                            <div><span class="rev-sn"><?= e($r['sn']) ?></span></div>
                        <?php endif; ?>
                    </td>
                    <td><?= e($r['categoria']) ?></td>
                    <td><?= e($r['parceiro']) ?: '<span style="color:#d1d5db;">—</span>' ?></td>
                    <td><?= $r['estado_no_momento'] ? estadoBolha($r['estado_no_momento']) : '<span style="color:#d1d5db;">—</span>' ?></td>
                    <td><?= $ea ? estadoBolha($ea) : '<span style="color:#d1d5db;">—</span>' ?></td>
                    <td><span class="dias-pill <?= $diasCls ?>"><?= $dias ?>d</span></td>
                    <td>
                        <?php if ($r['decisao'] === 'pendente'): ?>
                            <span class="badge badge-warn"><i class="bi bi-clock"></i> Pendente</span>
                        <?php elseif ($r['decisao'] === 'mantido'): ?>
                            <span class="badge badge-ok"><i class="bi bi-check"></i> Mantido</span>
                        <?php elseif ($r['decisao'] === 'corrigido'): ?>
                            <span class="badge badge-info"><i class="bi bi-pencil"></i> Corrigido</span>
                        <?php else: ?>
                            <span class="badge badge-err"><i class="bi bi-x"></i> Abatido</span>
                        <?php endif; ?>
                        <?php if ($r['nota']): ?>
                            <div class="rev-nota-mini"><?= e($r['nota']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:#6b7280;">
                        <?php if ($r['revisto_por']): ?>
                            <?= e($r['revisto_por']) ?><br>
                            <span style="font-size:11px;color:#d1d5db;"><?= e(substr($r['revisto_em'] ?? '', 0, 10)) ?></span>
                        <?php else: ?>
                            <span style="color:#d1d5db;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php $isPend = $r['decisao'] === 'pendente'; ?>
                        <button class="rev-btn <?= $isPend ? 'rev-btn-rever' : 'rev-btn-editar' ?>"
                            onclick="abrirModal(<?= (int)$r['id'] ?>, <?= htmlspecialchars(json_encode($r['produto']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($r['sn']), ENT_QUOTES) ?>)">
                            <?= $isPend ? 'Rever' : 'Editar' ?>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
                <tr id="tabelaRevisaoVazia" data-no-filter style="display:none;"><td colspan="9" style="text-align:center; color:#9ca3af; padding:24px;">Sem resultados para esta pesquisa.</td></tr>
            </tbody>
        </table>
    </div>

    <!-- Cards (mobile) -->
    <div class="rev-cards">
        <?php foreach ($linhas as $r): ?>
        <?php
        $dias = (int)$r['dias_parada'];
        $diasCls = $dias >= 60 ? 'alta' : ($dias >= 30 ? 'media' : 'baixa');
        $isPend = $r['decisao'] === 'pendente';
        ?>
        <div class="rev-card <?= !$isPend ? 'rev-feita' : '' ?>">
            <div class="rev-card-header">
                <div>
                    <div class="rev-card-title">
                        <a href="app.php?page=peca&id=<?= $r['peca_id'] ?>" class="rev-nome-link"><?= e($r['produto']) ?></a>
                    </div>
                    <?php if ($r['sn']): ?>
                        <div class="rev-card-sub"><span class="rev-sn"><?= e($r['sn']) ?></span></div>
                    <?php endif; ?>
                </div>
                <?php if ($r['decisao'] === 'pendente'): ?>
                    <span class="badge badge-warn">Pendente</span>
                <?php elseif ($r['decisao'] === 'mantido'): ?>
                    <span class="badge badge-ok">Mantido</span>
                <?php elseif ($r['decisao'] === 'corrigido'): ?>
                    <span class="badge badge-info">Corrigido</span>
                <?php else: ?>
                    <span class="badge badge-err">Abatido</span>
                <?php endif; ?>
            </div>
            <div class="rev-card-row">
                <span class="rev-card-row-label">Estado anterior</span>
                <span class="rev-card-row-val"><?= $r['estado_no_momento'] ? estadoBolha($r['estado_no_momento']) : '—' ?></span>
            </div>
            <div class="rev-card-row">
                <span class="rev-card-row-label">Estado atual</span>
                <span class="rev-card-row-val"><?= $r['estado_atual'] ? estadoBolha($r['estado_atual']) : '—' ?></span>
            </div>
            <div class="rev-card-row">
                <span class="rev-card-row-label">Dias parada</span>
                <span class="rev-card-row-val"><span class="dias-pill <?= $diasCls ?>"><?= $dias ?>d</span></span>
            </div>
            <?php if ($r['parceiro']): ?>
            <div class="rev-card-row">
                <span class="rev-card-row-label">Parceiro</span>
                <span class="rev-card-row-val"><?= e($r['parceiro']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($r['revisto_por']): ?>
            <div class="rev-card-row">
                <span class="rev-card-row-label">Revisto por</span>
                <span class="rev-card-row-val"><?= e($r['revisto_por']) ?> · <?= e(substr($r['revisto_em'] ?? '', 0, 10)) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($r['nota']): ?>
            <div class="rev-card-row">
                <span class="rev-card-row-label">Nota</span>
                <span class="rev-card-row-val" style="color:#6b7280;"><?= e($r['nota']) ?></span>
            </div>
            <?php endif; ?>
            <div class="rev-card-footer">
                <button class="rev-btn <?= $isPend ? 'rev-btn-rever' : 'rev-btn-editar' ?>"
                    onclick="abrirModal(<?= (int)$r['id'] ?>, <?= htmlspecialchars(json_encode($r['produto']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($r['sn']), ENT_QUOTES) ?>)">
                    <?= $isPend ? 'Rever' : 'Editar' ?>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>
</div>

<!-- Modal de revisão -->
<div id="modalRevisao" class="rev-modal-overlay">
    <div class="rev-modal">
        <button class="rev-modal-close" onclick="fecharModal()" type="button" aria-label="Fechar">×</button>
        <h3 id="modalTitulo"></h3>
        <p class="rev-modal-sub" id="modalSub"></p>

        <form method="post" action="app.php?page=revisao&mes=<?= e($periodo) ?>">
            <input type="hidden" name="form_type" value="rever_peca">
            <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="rev_id" id="modalRevId">

            <div class="rev-field">
                <label>Decisão <span style="color:#dc2626;">*</span></label>
                <div class="rev-decisao-btns">
                    <div class="rev-decisao-btn mantido">
                        <input type="radio" name="decisao" id="dec-mantido" value="mantido" required>
                        <label for="dec-mantido">
                            <i class="bi bi-check2-circle"></i>
                            Mantido
                        </label>
                    </div>
                    <div class="rev-decisao-btn corrigido">
                        <input type="radio" name="decisao" id="dec-corrigido" value="corrigido">
                        <label for="dec-corrigido">
                            <i class="bi bi-pencil-square"></i>
                            Corrigido
                        </label>
                    </div>
                    <div class="rev-decisao-btn abatido">
                        <input type="radio" name="decisao" id="dec-abatido" value="abatido">
                        <label for="dec-abatido">
                            <i class="bi bi-trash3"></i>
                            Abatido
                        </label>
                    </div>
                </div>
            </div>

            <div id="novoEstadoWrap" class="rev-field" style="display:none;">
                <label>Novo estado</label>
                <select name="novo_estado" id="novoEstadoSel">
                    <option value="">— selecionar —</option>
                    <?php foreach ($estados as $est): ?>
                        <option value="<?= e($est) ?>"><?= e($est) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="rev-field" style="margin-bottom:0;">
                <label>Nota (opcional)</label>
                <textarea name="nota" placeholder="Observação sobre a decisão tomada…"></textarea>
            </div>

            <div class="rev-modal-footer">
                <button type="button" onclick="fecharModal()" class="btn" style="background:#f1f5f9;color:#374151;">Cancelar</button>
                <button type="submit" class="btn btn-blue">Guardar decisão</button>
            </div>
        </form>
    </div>
</div>

<script>
const modalEl = document.getElementById('modalRevisao');

function abrirModal(id, produto, sn) {
    document.getElementById('modalRevId').value = id;
    document.getElementById('modalTitulo').textContent = produto;
    document.getElementById('modalSub').textContent = sn ? 'SN: ' + sn : '';
    document.querySelectorAll('input[name="decisao"]').forEach(r => r.checked = false);
    document.querySelector('textarea[name="nota"]').value = '';
    document.getElementById('novoEstadoSel').value = '';
    document.getElementById('novoEstadoWrap').style.display = 'none';
    modalEl.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function fecharModal() {
    modalEl.classList.remove('open');
    document.body.style.overflow = '';
}

document.querySelectorAll('input[name="decisao"]').forEach(r => {
    r.addEventListener('change', () => {
        const mostrar = r.value === 'corrigido' || r.value === 'abatido';
        document.getElementById('novoEstadoWrap').style.display = mostrar ? 'block' : 'none';
    });
});

modalEl.addEventListener('click', e => { if (e.target === modalEl) fecharModal(); });

document.addEventListener('keydown', e => { if (e.key === 'Escape') fecharModal(); });
</script>
