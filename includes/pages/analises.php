<?php // includes/pages/analises.php
/** @var PDO $pdo */
/** @var string $csrfToken */
/** @var array $estados */
/** @var array $parceiros */

require_once __DIR__ . '/../revisoes.php';
require_once __DIR__ . '/../sla.php';

// ── SLA: criar / editar / ativar-desativar / eliminar regra ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['sla_guardar', 'sla_toggle', 'sla_eliminar'], true)) {
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
        flashError('Ação inválida.');
        redirectTo('app.php?page=analises');
    }
    exigirAdmin();

    $acaoSla = $_POST['action'];

    if ($acaoSla === 'sla_guardar') {
        $id         = (int)($_POST['id'] ?? 0);
        $alvoTipo   = in_array($_POST['alvo_tipo'] ?? '', ['cliente', 'parceiro', 'global'], true) ? $_POST['alvo_tipo'] : 'global';
        $alvoNome   = trim($_POST['alvo_nome'] ?? '');
        $estadoRule = trim($_POST['estado'] ?? '');
        $diasLimite = (int)($_POST['dias_limite'] ?? 0);

        if ($estadoRule === '') {
            flashError('Tens de escolher um estado para a regra.');
            redirectTo('app.php?page=analises');
        }
        if ($diasLimite <= 0) {
            flashError('O limite de dias tem de ser maior que zero.');
            redirectTo('app.php?page=analises');
        }
        if ($alvoTipo !== 'global' && $alvoNome === '') {
            flashError('Tens de escolher um ' . ($alvoTipo === 'parceiro' ? 'parceiro' : 'cliente') . ' para esta regra.');
            redirectTo('app.php?page=analises');
        }
        if ($alvoTipo === 'global') {
            $alvoNome = null;
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE sla_regras SET alvo_tipo=?, alvo_nome=?, estado=?, dias_limite=? WHERE id=?")
                ->execute([$alvoTipo, $alvoNome, $estadoRule, $diasLimite, $id]);
            flashSuccess('Regra de SLA atualizada.');
        } else {
            $pdo->prepare("INSERT INTO sla_regras (alvo_tipo, alvo_nome, estado, dias_limite, ativo) VALUES (?,?,?,?,1)")
                ->execute([$alvoTipo, $alvoNome, $estadoRule, $diasLimite]);
            flashSuccess('Regra de SLA criada.');
        }
    } elseif ($acaoSla === 'sla_toggle') {
        $pdo->prepare("UPDATE sla_regras SET ativo = 1 - ativo WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
        flashSuccess('Estado da regra atualizado.');
    } elseif ($acaoSla === 'sla_eliminar') {
        $pdo->prepare("DELETE FROM sla_regras WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
        flashSuccess('Regra de SLA eliminada.');
    }
    redirectTo('app.php?page=analises');
}

// ── Resumo Mensal ──────────────────────────────────────────
$periodo = preg_match('/^\d{4}-\d{2}$/', $_GET['mes'] ?? '') ? $_GET['mes'] : date('Y-m');

$kpi = function (PDO $pdo, string $sql, array $p = []) {
    $s = $pdo->prepare($sql);
    $s->execute($p);
    return (int)$s->fetchColumn();
};
$entradas  = $kpi($pdo, "SELECT COUNT(*) FROM pecas WHERE DATE_FORMAT(created_at,'%Y-%m') = ?", [$periodo]);
$movEstado = $kpi($pdo, "SELECT COUNT(*) FROM historico WHERE campo='estado' AND DATE_FORMAT(data_alteracao,'%Y-%m') = ?", [$periodo]);
$revFeitas = $kpi($pdo, "SELECT COUNT(*) FROM revisoes_peca WHERE periodo = ? AND decisao <> 'pendente'", [$periodo]);
$revPend   = $kpi($pdo, "SELECT COUNT(*) FROM revisoes_peca WHERE periodo = ? AND decisao = 'pendente'", [$periodo]);

// ── Movimentos (12 meses) ───────────────────────────────────
$entradasMes = $pdo->query("
    SELECT DATE_FORMAT(created_at,'%Y-%m') AS mes, COUNT(*) total
    FROM pecas
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY mes ORDER BY mes
")->fetchAll();

$saidasMes = $pdo->query("
    SELECT DATE_FORMAT(data_alteracao,'%Y-%m') AS mes, COUNT(*) total
    FROM historico
    WHERE campo='estado' AND depois IN ('Cliente','Parceiro','Fornecedor (Reparação)')
      AND data_alteracao >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY mes ORDER BY mes
")->fetchAll();

$movIdx = [];
foreach ($entradasMes as $mLin) { $movIdx[$mLin['mes']]['ent'] = (int)$mLin['total']; }
foreach ($saidasMes   as $mLin) { $movIdx[$mLin['mes']]['sai'] = (int)$mLin['total']; }
ksort($movIdx);

// ── SLA — quebras + regras existentes ───────────────────────
$slaQuebras = nvSlaQuebras($pdo);

$slaRegras = $pdo->query("
    SELECT * FROM sla_regras
    ORDER BY ativo DESC, (alvo_tipo='global') DESC, alvo_tipo ASC, estado ASC
")->fetchAll();

$slaEdit = null;
if (($_GET['sla_edit'] ?? '') !== '') {
    $stmt = $pdo->prepare("SELECT * FROM sla_regras WHERE id = ?");
    $stmt->execute([(int)$_GET['sla_edit']]);
    $slaEdit = $stmt->fetch() ?: null;
}

$listaClientesSla = $pdo->query("
    SELECT DISTINCT account_name FROM clientes
    WHERE account_name IS NOT NULL AND account_name <> ''
    ORDER BY account_name ASC
")->fetchAll(PDO::FETCH_COLUMN);

$mesesPt = ['01'=>'Jan','02'=>'Fev','03'=>'Mar','04'=>'Abr','05'=>'Mai','06'=>'Jun',
            '07'=>'Jul','08'=>'Ago','09'=>'Set','10'=>'Out','11'=>'Nov','12'=>'Dez'];
?>

<!-- ══ RESUMO MENSAL ══ -->
<div class="panel" style="margin-bottom:20px;">
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
        <h4 style="margin:0;"><i class="bi bi-calendar3" style="color:#c9a14a; margin-right:6px;"></i>Resumo Mensal</h4>
        <form method="get" style="display:flex; gap:10px; align-items:center;">
            <input type="hidden" name="page" value="analises">
            <input type="month" name="mes" value="<?= e($periodo) ?>" style="height:38px;">
            <button class="btn btn-blue" type="submit" style="padding:8px 16px;">Ver</button>
        </form>
    </div>
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:16px;">
        <div class="kpi-card">
            <i class="bi bi-box-seam" style="color:#c9a14a;"></i>
            <div class="num"><?= $entradas ?></div>
            <div>Peças novas</div>
        </div>
        <div class="kpi-card">
            <i class="bi bi-arrow-left-right" style="color:#3d82c4;"></i>
            <div class="num"><?= $movEstado ?></div>
            <div>Mudanças de estado</div>
        </div>
        <div class="kpi-card">
            <i class="bi bi-check2-square" style="color:#59b94f;"></i>
            <div class="num"><?= $revFeitas ?></div>
            <div>Revisões feitas</div>
        </div>
        <div class="kpi-card">
            <i class="bi bi-hourglass-split" style="color:#f59e0b;"></i>
            <div class="num"><?= $revPend ?></div>
            <div>Revisões pendentes</div>
        </div>
    </div>
    <p class="small-note">Valores referentes a <?= e(($mesesPt[substr($periodo,5,2)] ?? substr($periodo,5,2)) . '/' . substr($periodo,0,4)) ?>. Os totais de revisão estão ligados à página "Revisão".</p>
</div>

<!-- ══ MOVIMENTOS (12 MESES) ══ -->
<div class="panel" style="margin-bottom:20px;">
    <h4 style="margin-bottom:16px;"><i class="bi bi-graph-up-arrow" style="color:#c9a14a; margin-right:6px;"></i>Movimentos de Stock (últimos 12 meses)</h4>
    <div style="overflow-x:auto;">
        <table class="table envios-table">
            <thead>
            <tr><th>Mês</th><th>Entradas</th><th>Saídas</th><th>Saldo</th></tr>
            </thead>
            <tbody>
            <?php if (empty($movIdx)): ?>
                <tr><td colspan="4" class="envios-vazio">Sem movimentos nos últimos 12 meses.</td></tr>
            <?php else: ?>
                <?php foreach ($movIdx as $mesChave => $v):
                    $ent = (int)($v['ent'] ?? 0);
                    $sai = (int)($v['sai'] ?? 0);
                    $saldo = $ent - $sai;
                    $corSaldo = $saldo > 0 ? '#15803d' : ($saldo < 0 ? '#b91c1c' : '#6b7280');
                    $rotulo = ($mesesPt[substr($mesChave,5,2)] ?? substr($mesChave,5,2)) . ' ' . substr($mesChave,0,4);
                    ?>
                    <tr>
                        <td><?= e($rotulo) ?></td>
                        <td><?= $ent ?></td>
                        <td><?= $sai ?></td>
                        <td style="color:<?= $corSaldo ?>; font-weight:700;"><?= $saldo > 0 ? '+' : '' ?><?= $saldo ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="small-note">"Saídas" conta peças que passaram a Cliente, Parceiro ou Fornecedor (Reparação) nesse mês.</p>
</div>

<!-- ══ REGRAS DE SLA (configuração) ══ -->
<div class="panel" style="margin-bottom:20px;">
    <h4 style="margin-bottom:16px;"><i class="bi bi-sliders" style="color:#c9a14a; margin-right:6px;"></i><?= $slaEdit ? 'Editar Regra de SLA' : 'Nova Regra de SLA' ?></h4>

    <form method="post" action="app.php?page=analises" id="formSlaRegra">
        <input type="hidden" name="action" value="sla_guardar">
        <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= (int)($slaEdit['id'] ?? 0) ?>">

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:16px; margin-bottom:14px;">
            <div>
                <label>Aplica-se a</label>
                <select name="alvo_tipo" id="slaAlvoTipo" onchange="nvSlaAlvoToggle()">
                    <option value="global" <?= ($slaEdit['alvo_tipo'] ?? 'global') === 'global' ? 'selected' : '' ?>>Todos (Global)</option>
                    <option value="parceiro" <?= ($slaEdit['alvo_tipo'] ?? '') === 'parceiro' ? 'selected' : '' ?>>Um Parceiro</option>
                    <option value="cliente" <?= ($slaEdit['alvo_tipo'] ?? '') === 'cliente' ? 'selected' : '' ?>>Um Cliente</option>
                </select>
            </div>
            <div id="slaAlvoParceiroWrap" style="display:none;">
                <label>Parceiro</label>
                <select name="alvo_nome_parceiro" id="slaAlvoNomeParceiro">
                    <option value="">-- Selecione o parceiro --</option>
                    <?php foreach ($parceiros as $p): ?>
                        <option value="<?= e($p) ?>" <?= ($slaEdit['alvo_tipo'] ?? '') === 'parceiro' && ($slaEdit['alvo_nome'] ?? '') === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="slaAlvoClienteWrap" style="display:none;">
                <label>Cliente</label>
                <select name="alvo_nome_cliente" id="slaAlvoNomeCliente">
                    <option value="">-- Selecione o cliente --</option>
                    <?php foreach ($listaClientesSla as $c): ?>
                        <option value="<?= e($c) ?>" <?= ($slaEdit['alvo_tipo'] ?? '') === 'cliente' && ($slaEdit['alvo_nome'] ?? '') === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Estado</label>
                <select name="estado" required>
                    <option value="">-- Selecione o estado --</option>
                    <?php foreach ($estados as $est): ?>
                        <option value="<?= e($est) ?>" <?= ($slaEdit['estado'] ?? '') === $est ? 'selected' : '' ?>><?= e($est) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Limite (dias)</label>
                <input type="number" name="dias_limite" min="1" required value="<?= e($slaEdit['dias_limite'] ?? '') ?>">
            </div>
        </div>

        <div style="display:flex; gap:10px;">
            <button class="btn btn-teal" type="submit"><?= $slaEdit ? 'Atualizar Regra' : 'Criar Regra' ?></button>
            <?php if ($slaEdit): ?>
                <a class="btn btn-grey" href="app.php?page=analises" onclick="nvVoltar(event)">Cancelar</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- O <select> com a alvo_nome real (escolhido conforme o tipo) é injetado via JS antes do submit -->
    <script>
        function nvSlaAlvoToggle() {
            const tipo = document.getElementById('slaAlvoTipo').value;
            document.getElementById('slaAlvoParceiroWrap').style.display = (tipo === 'parceiro') ? '' : 'none';
            document.getElementById('slaAlvoClienteWrap').style.display  = (tipo === 'cliente')  ? '' : 'none';
        }
        nvSlaAlvoToggle();
        document.getElementById('formSlaRegra').addEventListener('submit', function () {
            const tipo = document.getElementById('slaAlvoTipo').value;
            let nome = '';
            if (tipo === 'parceiro') nome = document.getElementById('slaAlvoNomeParceiro').value;
            if (tipo === 'cliente')  nome = document.getElementById('slaAlvoNomeCliente').value;
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'alvo_nome';
            hidden.value = nome;
            this.appendChild(hidden);
        });
    </script>

    <hr style="margin:20px 0; border:none; border-top:1px solid #e5e7eb;">

    <div style="overflow-x:auto;">
        <table class="table envios-table">
            <thead>
            <tr><th>Aplica-se a</th><th>Estado</th><th>Limite</th><th>Ativa</th><th>Ações</th></tr>
            </thead>
            <tbody>
            <?php if (empty($slaRegras)): ?>
                <tr><td colspan="5" class="envios-vazio">Ainda não há regras de SLA criadas.</td></tr>
            <?php else: ?>
                <?php foreach ($slaRegras as $r): ?>
                    <tr>
                        <td>
                            <?php if ($r['alvo_tipo'] === 'global'): ?>
                                <span style="display:inline-block; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600; background:#e0e7ff; color:#3730a3;">Global</span>
                            <?php else: ?>
                                <span style="display:inline-block; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600; background:#fef3c7; color:#92400e; margin-right:6px;"><?= $r['alvo_tipo'] === 'parceiro' ? 'Parceiro' : 'Cliente' ?></span>
                                <?= e($r['alvo_nome']) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= e($r['estado']) ?></td>
                        <td><?= (int)$r['dias_limite'] ?>d</td>
                        <td>
                            <form method="post" action="app.php?page=analises" style="display:inline;">
                                <input type="hidden" name="action" value="sla_toggle">
                                <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="btn" style="padding:4px 10px; font-size:12px; background:<?= $r['ativo'] ? '#dcfce7' : '#f3f4f6' ?>; color:<?= $r['ativo'] ? '#15803d' : '#6b7280' ?>;">
                                    <?= $r['ativo'] ? 'Ativa' : 'Inativa' ?>
                                </button>
                            </form>
                        </td>
                        <td>
                            <a class="btn btn-yellow" style="padding:5px 10px; font-size:12px;" href="app.php?page=analises&sla_edit=<?= (int)$r['id'] ?>">Editar</a>
                            <form method="post" action="app.php?page=analises" style="display:inline;" onsubmit="return confirm('Eliminar esta regra de SLA?');">
                                <input type="hidden" name="action" value="sla_eliminar">
                                <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="btn btn-red" style="padding:5px 10px; font-size:12px;">Eliminar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ══ SLA — QUEBRAS ATIVAS ══ -->
<div class="panel">
    <h4 style="margin-bottom:16px;"><i class="bi bi-exclamation-triangle" style="color:#c9a14a; margin-right:6px;"></i>SLA — Quebras Ativas</h4>
    <div style="overflow-x:auto;">
        <table class="table envios-table">
            <thead>
            <tr><th>Peça</th><th>SN</th><th>Estado</th><th>Parceiro</th><th>Cliente</th><th>Dias no estado</th><th>Limite</th></tr>
            </thead>
            <tbody>
            <?php if (empty($slaQuebras)): ?>
                <tr><td colspan="7" class="envios-vazio">Sem quebras de SLA neste momento.</td></tr>
            <?php else: ?>
                <?php foreach ($slaQuebras as $sq): ?>
                    <tr>
                        <td><?= e($sq['produto']) ?></td>
                        <td><?= e($sq['sn']) ?></td>
                        <td><?= e($sq['estado']) ?></td>
                        <td><?= e($sq['parceiro'] ?: '—') ?></td>
                        <td><?= e($sq['cliente_nome'] ?? '') ?: '—' ?></td>
                        <td style="color:#b91c1c; font-weight:700;"><?= (int)$sq['dias'] ?>d</td>
                        <td><?= (int)$sq['dias_limite'] ?>d</td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
