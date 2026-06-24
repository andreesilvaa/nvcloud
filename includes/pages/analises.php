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
<?php
// Seletor de mês como segmented control (igual à Revisão), alinhado à direita
$anMesesPt = [1=>'Janeiro',2=>'Fevereiro',3=>'Março',4=>'Abril',5=>'Maio',6=>'Junho',7=>'Julho',8=>'Agosto',9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro'];
$anTs           = strtotime($periodo . '-01');
$anMesPrev      = date('Y-m', strtotime('-1 month', $anTs));
$anMesNext      = date('Y-m', strtotime('+1 month', $anTs));
$anPeriodoLabel = $anMesesPt[(int)date('n', $anTs)] . ' ' . date('Y', $anTs);
?>
<div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
    <h4 style="margin:0;"><i class="bi bi-calendar3" style="color:#c9a14a; margin-right:6px;"></i>Resumo Mensal</h4>
    <div class="seg-control">
        <a href="app.php?page=analises&mes=<?= e($anMesPrev) ?>" title="Mês anterior" aria-label="Mês anterior"><i class="bi bi-chevron-left"></i></a>
        <span class="seg-mid"><?= e($anPeriodoLabel) ?></span>
        <a href="app.php?page=analises&mes=<?= e($anMesNext) ?>" title="Mês seguinte" aria-label="Mês seguinte"><i class="bi bi-chevron-right"></i></a>
    </div>
</div>
<style>
/* Opção A — cartões do resumo mensal em layout horizontal compacto (scoped: não afeta o Dashboard) */
.kpi-resumo-compact .kpi-card{
    display:flex !important; flex-direction:row !important; align-items:center;
    justify-content:center !important; text-align:left; gap:14px; padding:12px 16px;
    min-height:0 !important; height:auto !important; aspect-ratio:unset !important;
}
.kpi-resumo-compact .kpi-card > i{ font-size:26px !important; margin:0 !important; line-height:1; }
.kpi-resumo-compact .kpi-card .kpi-body{ display:flex; flex-direction:column; gap:2px; }
.kpi-resumo-compact .kpi-card .num{ font-size:24px !important; margin:0 !important; line-height:1.1; }
.kpi-resumo-compact .kpi-card .kpi-lbl{ font-size:12.5px; color:#6b7280; }
@media (max-width:768px){ .kpi-resumo-compact{ grid-template-columns:repeat(2,1fr) !important; } }
</style>
<div class="kpi-row kpi-resumo-compact" style="grid-template-columns:repeat(4, 1fr); margin-bottom:10px;">
    <div class="kpi-card">
        <i class="bi bi-box-seam" style="color:#c9a14a;"></i>
        <div class="kpi-body">
            <div class="num"><?= $entradas ?></div>
            <div class="kpi-lbl">Peças novas</div>
        </div>
    </div>
    <div class="kpi-card">
        <i class="bi bi-arrow-left-right" style="color:#3d82c4;"></i>
        <div class="kpi-body">
            <div class="num"><?= $movEstado ?></div>
            <div class="kpi-lbl">Mudanças de estado</div>
        </div>
    </div>
    <div class="kpi-card">
        <i class="bi bi-check2-square" style="color:#59b94f;"></i>
        <div class="kpi-body">
            <div class="num"><?= $revFeitas ?></div>
            <div class="kpi-lbl">Revisões feitas</div>
        </div>
    </div>
    <div class="kpi-card">
        <i class="bi bi-hourglass-split" style="color:#f59e0b;"></i>
        <div class="kpi-body">
            <div class="num"><?= $revPend ?></div>
            <div class="kpi-lbl">Revisões pendentes</div>
        </div>
    </div>
</div>
<p class="small-note" style="margin-bottom:20px;">Valores referentes a <?= e(($mesesPt[substr($periodo,5,2)] ?? substr($periodo,5,2)) . '/' . substr($periodo,0,4)) ?>. Os totais de revisão estão ligados à página "Revisão".</p>

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
<div style="display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:20px; align-items:start; margin-bottom:20px;">
<div class="panel">
    <h4 style="margin-bottom:16px;"><i class="bi bi-sliders" style="color:#c9a14a; margin-right:6px;"></i><?= $slaEdit ? 'Editar Regra de SLA' : 'Nova Regra de SLA' ?></h4>

    <form method="post" action="app.php?page=analises" id="formSlaRegra">
        <input type="hidden" name="action" value="sla_guardar">
        <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= (int)($slaEdit['id'] ?? 0) ?>">

        <div class="sla-form-secao">
            <span class="sla-form-secao-titulo"><i class="bi bi-bullseye"></i>Âmbito da regra</span>
            <div class="form-grid">
                <div>
                    <label>Aplica-se a</label>
                    <select name="alvo_tipo" id="slaAlvoTipo" onchange="nvSlaAlvoToggle()">
                        <option value="global" <?= ($slaEdit['alvo_tipo'] ?? 'global') === 'global' ? 'selected' : '' ?>>Todos (Global)</option>
                        <option value="parceiro" <?= ($slaEdit['alvo_tipo'] ?? '') === 'parceiro' ? 'selected' : '' ?>>Um Parceiro</option>
                        <option value="cliente" <?= ($slaEdit['alvo_tipo'] ?? '') === 'cliente' ? 'selected' : '' ?>>Um Cliente</option>
                    </select>
                </div>
                <div>
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
                    <div id="slaAlvoGlobalNota" class="sla-form-nota">
                        <i class="bi bi-info-circle"></i> Esta regra vai aplicar-se a todos os parceiros e clientes.
                    </div>
                </div>
            </div>
        </div>

        <div class="sla-form-secao">
            <span class="sla-form-secao-titulo"><i class="bi bi-stopwatch"></i>Condição do alerta</span>
            <div class="form-grid">
                <div>
                    <label>Estado da peça</label>
                    <select name="estado" required>
                        <option value="">-- Selecione o estado --</option>
                        <?php foreach ($estados as $est): ?>
                            <option value="<?= e($est) ?>" <?= ($slaEdit['estado'] ?? '') === $est ? 'selected' : '' ?>><?= e($est) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Limite (dias)</label>
                    <input type="number" name="dias_limite" min="1" required value="<?= e($slaEdit['dias_limite'] ?? '') ?>" placeholder="ex.: 15">
                </div>
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
            document.getElementById('slaAlvoGlobalNota').style.display   = (tipo === 'global')   ? '' : 'none';
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

</div>

<div class="panel">
    <h4 style="margin-bottom:16px;"><i class="bi bi-list-check" style="color:#c9a14a; margin-right:6px;"></i>Regras de SLA</h4>

    <div style="overflow-x:auto;">
        <table class="table envios-table">
            <thead>
            <tr><th>Aplica-se a</th><th>Estado</th><th>Ações</th></tr>
            </thead>
            <tbody>
            <?php if (empty($slaRegras)): ?>
                <tr><td colspan="3" class="envios-vazio">Ainda não há regras de SLA criadas.</td></tr>
            <?php else: ?>
                <?php foreach ($slaRegras as $r): ?>
                    <?php
                        $alvoLabel = $r['alvo_tipo'] === 'global' ? 'Global' : ($r['alvo_tipo'] === 'parceiro' ? 'Parceiro' : 'Cliente');
                        $alvoDetalhe = $r['alvo_tipo'] === 'global' ? 'Todos os parceiros e clientes' : ($alvoLabel . ': ' . $r['alvo_nome']);
                        $dias = (int)$r['dias_limite'];
                    ?>
                    <tr>
                        <td>
                            <?php if ($r['alvo_tipo'] === 'global'): ?>
                                <span style="display:inline-block; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600; background:#e0e7ff; color:#3730a3;">Global</span>
                            <?php else: ?>
                                <span style="display:inline-block; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600; background:#fef3c7; color:#92400e;"><?= e($alvoLabel) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($r['estado']) ?></td>
                        <td class="actions">
                            <a class="btn btn-yellow" href="app.php?page=analises&sla_edit=<?= (int)$r['id'] ?>" title="Editar" aria-label="Editar"><i class="bi bi-pencil"></i></a>
                            <button type="button" class="btn btn-grey" title="Detalhes" aria-label="Detalhes"
                                onclick='nvSlaDetalhes(<?= json_encode($alvoDetalhe) ?>, <?= json_encode($r['estado']) ?>, <?= $dias ?>, <?= $r['ativo'] ? 'true' : 'false' ?>)'>
                                <i class="bi bi-info-circle"></i>
                            </button>
                            <form method="post" action="app.php?page=analises" style="display:inline-block;">
                                <input type="hidden" name="action" value="sla_toggle">
                                <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="btn <?= $r['ativo'] ? 'btn-green' : 'btn-grey' ?>" title="<?= $r['ativo'] ? 'Desativar' : 'Ativar' ?>" aria-label="<?= $r['ativo'] ? 'Desativar' : 'Ativar' ?>">
                                    <i class="bi <?= $r['ativo'] ? 'bi-toggle2-on' : 'bi-toggle2-off' ?>"></i>
                                </button>
                            </form>
                            <form method="post" action="app.php?page=analises" style="display:inline-block;" onsubmit="return confirm('Eliminar esta regra de SLA?');">
                                <input type="hidden" name="action" value="sla_eliminar">
                                <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="btn btn-red" title="Eliminar" aria-label="Eliminar"><i class="bi bi-trash3"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<style>
.sla-form-secao{
    margin-bottom:20px;
    padding-bottom:18px;
    border-bottom:1px solid #f1f5f9;
}
.sla-form-secao:last-of-type{ border-bottom:none; margin-bottom:18px; }
.sla-form-secao-titulo{
    display:flex; align-items:center; gap:7px;
    font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.04em;
    color:#9ca3af; margin-bottom:14px;
}
.sla-form-nota{
    display:flex; align-items:center; gap:8px;
    height:46px; padding:0 14px; border-radius:10px;
    background:#fafbfc; border:1px solid #eef0f3;
    color:#6b7280; font-size:13px; box-sizing:border-box;
}
.sla-detalhes-overlay{
    display:none; position:fixed; inset:0; background:rgba(15,23,42,.55);
    z-index:1000; align-items:center; justify-content:center; padding:16px;
}
.sla-detalhes-overlay.open{ display:flex; }
.sla-detalhes-modal{
    background:#fff; border-radius:18px; padding:30px 28px; max-width:380px; width:100%;
    box-shadow:0 24px 60px rgba(0,0,0,.25); position:relative; text-align:center;
    animation:slaDetalhesPop .15s ease;
}
@keyframes slaDetalhesPop{ from{ transform:scale(.95); opacity:0; } to{ transform:scale(1); opacity:1; } }
.sla-detalhes-close{
    position:absolute; top:16px; right:16px; width:30px; height:30px; border-radius:50%;
    background:#f1f5f9; border:none; font-size:18px; cursor:pointer; color:#6b7280;
    display:flex; align-items:center; justify-content:center;
    transition:background .15s, color .15s;
}
.sla-detalhes-close:hover{ background:#e5e7eb; color:#374151; }
.sla-detalhes-icon{
    width:56px; height:56px; border-radius:50%; margin:0 auto 14px;
    background:#fdf3df; display:flex; align-items:center; justify-content:center;
}
.sla-detalhes-icon i{ font-size:24px; color:#c9a14a; }
.sla-detalhes-modal h3{ margin:0 0 4px; font-size:17px; font-weight:700; color:#1e293b; }
.sla-detalhes-sub{ margin:0 0 22px; font-size:13px; color:#6b7280; font-weight:600; }
.sla-detalhes-grid{ display:grid; grid-template-columns:1fr 1fr; gap:10px; text-align:left; }
.sla-detalhes-item{ background:#f8fafc; border-radius:10px; padding:12px 14px; }
.sla-detalhes-item.full{ grid-column:1 / -1; }
.sla-detalhes-label{
    display:block; font-size:10px; font-weight:700; text-transform:uppercase;
    letter-spacing:.04em; color:#9ca3af; margin-bottom:4px;
}
.sla-detalhes-value{ display:block; font-size:14px; font-weight:700; color:#1e293b; }
</style>

<div class="sla-detalhes-overlay" id="slaDetalhesOverlay">
    <div class="sla-detalhes-modal">
        <button type="button" class="sla-detalhes-close" onclick="nvSlaDetalhesFechar()" aria-label="Fechar">×</button>
        <div class="sla-detalhes-icon"><i class="bi bi-shield-check"></i></div>
        <h3>Detalhes da Regra de SLA</h3>
        <p class="sla-detalhes-sub" id="slaDetAlvo"></p>
        <div class="sla-detalhes-grid">
            <div class="sla-detalhes-item">
                <span class="sla-detalhes-label">Estado</span>
                <span class="sla-detalhes-value" id="slaDetEstado"></span>
            </div>
            <div class="sla-detalhes-item">
                <span class="sla-detalhes-label">Limite</span>
                <span class="sla-detalhes-value" id="slaDetLimite"></span>
            </div>
            <div class="sla-detalhes-item full">
                <span class="sla-detalhes-label">Situação</span>
                <span class="sla-detalhes-value" id="slaDetAtiva"></span>
            </div>
        </div>
    </div>
</div>
<script>
function nvSlaDetalhes(alvo, estado, dias, ativo) {
    document.getElementById('slaDetAlvo').textContent = alvo;
    document.getElementById('slaDetEstado').textContent = estado;
    document.getElementById('slaDetLimite').textContent = dias + ' dia(s)';
    document.getElementById('slaDetAtiva').textContent = ativo ? 'Ativa' : 'Inativa';
    document.getElementById('slaDetAtiva').style.color = ativo ? '#15803d' : '#9ca3af';
    document.getElementById('slaDetalhesOverlay').classList.add('open');
}
function nvSlaDetalhesFechar() {
    document.getElementById('slaDetalhesOverlay').classList.remove('open');
}
document.getElementById('slaDetalhesOverlay').addEventListener('click', function (e) {
    if (e.target === this) nvSlaDetalhesFechar();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') nvSlaDetalhesFechar();
});
</script>

<!-- ══ SLA — QUEBRAS ATIVAS (cartões) ══ -->
<div class="panel">
    <div class="panel-header-row">
        <div class="panel-header-left">
            <h4 style="margin:0;"><i class="bi bi-exclamation-triangle" style="color:#c9a14a; margin-right:6px;"></i>SLA — Quebras Ativas</h4>
            <span class="panel-count-badge" style="<?= $slaQuebras ? 'background:#fee2e2;color:#b91c1c;' : '' ?>"><?= count($slaQuebras) ?></span>
        </div>
    </div>

    <?php if (empty($slaQuebras)): ?>
        <div class="table-empty-state" style="padding:30px 16px;">
            <i class="bi bi-shield-check" style="color:#22c55e;"></i>
            Sem quebras de SLA neste momento.
        </div>
    <?php else: ?>
        <div class="sla-cards-grid">
            <?php foreach ($slaQuebras as $sq):
                $dias = (int)$sq['dias'];
                $limite = (int)$sq['dias_limite'];
                $excesso = $limite > 0 ? $dias / $limite : 2;
                $sev = $excesso >= 2 ? 'alta' : ($excesso >= 1.3 ? 'media' : 'baixa');
                $sevCor = ['alta' => '#dc2626', 'media' => '#d97706', 'baixa' => '#ca8a04'][$sev];
                $sevBg  = ['alta' => '#fef2f2', 'media' => '#fffbeb', 'baixa' => '#fefce8'][$sev];
                $sevTxt = ['alta' => 'Crítico', 'media' => 'Atenção', 'baixa' => 'Ligeiro'][$sev];
                $pct = max(8, min(100, (int)round(($dias / max(1, $limite)) * 100)));
            ?>
            <div class="sla-card" style="border-left-color:<?= $sevCor ?>;">
                <div class="sla-card-top">
                    <div>
                        <div class="sla-card-titulo"><?= e($sq['produto']) ?></div>
                        <div class="sla-card-sn"><?= e($sq['sn']) ?: '—' ?></div>
                    </div>
                    <span class="sla-card-badge" style="background:<?= $sevBg ?>; color:<?= $sevCor ?>;"><?= $sevTxt ?></span>
                </div>

                <div class="sla-card-row">
                    <span><i class="bi bi-flag" style="color:#9ca3af; margin-right:5px;"></i>Estado</span>
                    <strong><?= e($sq['estado']) ?></strong>
                </div>
                <div class="sla-card-row">
                    <span><i class="bi bi-building" style="color:#9ca3af; margin-right:5px;"></i>Parceiro</span>
                    <strong><?= e($sq['parceiro'] ?: '—') ?></strong>
                </div>
                <div class="sla-card-row">
                    <span><i class="bi bi-person" style="color:#9ca3af; margin-right:5px;"></i>Cliente</span>
                    <strong><?= e($sq['cliente_nome'] ?? '') ?: '—' ?></strong>
                </div>

                <div class="sla-card-progress">
                    <div class="sla-card-progress-bar"><div style="width:<?= $pct ?>%; background:<?= $sevCor ?>;"></div></div>
                    <div class="sla-card-progress-label">
                        <strong style="color:<?= $sevCor ?>;"><?= $dias ?>d</strong> no estado · limite <?= $limite ?>d
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
.sla-cards-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill, minmax(260px, 1fr));
    gap:14px;
}
.sla-card{
    background:#fff;
    border:1px solid #e5e7eb;
    border-left:4px solid #d1d5db;
    border-radius:10px;
    padding:14px 16px;
    box-shadow:0 1px 4px rgba(0,0,0,.04);
}
.sla-card-top{ display:flex; align-items:flex-start; justify-content:space-between; gap:10px; margin-bottom:10px; }
.sla-card-titulo{ font-weight:700; font-size:14px; color:#1f2937; }
.sla-card-sn{ font-size:11px; color:#9ca3af; font-family:monospace; margin-top:1px; }
.sla-card-badge{ flex-shrink:0; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap; }
.sla-card-row{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:5px 0; font-size:12.5px; color:#6b7280; border-top:1px solid #f3f4f6; }
.sla-card-row strong{ color:#374151; text-align:right; }
.sla-card-progress{ margin-top:10px; }
.sla-card-progress-bar{ height:6px; border-radius:99px; background:#f1f5f9; overflow:hidden; }
.sla-card-progress-bar div{ height:100%; border-radius:99px; }
.sla-card-progress-label{ font-size:11.5px; color:#6b7280; margin-top:6px; }
</style>
