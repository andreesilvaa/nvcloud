<?php

require_once __DIR__ . '/bootstrap.php';

// ══════════════════════════════════════════════
// workorder.php — Folha de Obra (impressão)
// Requer: sessão ativa e ID do PAT via GET
// ══════════════════════════════════════════════
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$host = 'localhost'; $db = 'stocks_db'; $user = 'root'; $pass = ''; $charset = 'utf8mb4';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    die('Erro de ligação à base de dados.');
}

$patId = (int)($_GET['id'] ?? 0);
if ($patId <= 0) { die('ID do PAT inválido.'); }

$stmt = $pdo->prepare("SELECT * FROM pats WHERE id = ?");
$stmt->execute([$patId]);
$pat = $stmt->fetch();
if ($pat) {
    $pat = array_map(fn($v) => is_null($v) ? '' : $v, $pat);
}
if (!$pat) { die('PAT não encontrado.'); }

$sm = $pdo->prepare("SELECT * FROM pats_modulos WHERE pat_id = ? ORDER BY id");
$sm->execute([$patId]);
$modulos = $sm->fetchAll();

$sc = $pdo->prepare("SELECT * FROM pats_componentes WHERE pat_id = ? ORDER BY id");
$sc->execute([$patId]);
$componentes = $sc->fetchAll();

// Garantir pelo menos 2 linhas vazias nas tabelas
while (count($modulos)     < 2) $modulos[]     = ['solucao_equipamento'=>'','modelo'=>'','num_serie'=>''];
while (count($componentes) < 3) $componentes[] = ['removido'=>'','sn_removido'=>'','colocado'=>'','sn_colocado'=>'','quantidade'=>1];

function fmt($dt, $format = 'd/m/Y H:i') {
    return $dt ? date($format, strtotime($dt)) : '';
}
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$duracaoStr = '';
if ($pat['data_inicio'] && $pat['data_fim']) {
    $diff = strtotime($pat['data_fim']) - strtotime($pat['data_inicio']);
    $h = floor($diff / 3600);
    $m = floor(($diff % 3600) / 60);
    $duracaoStr = sprintf('%dh%02d', $h, $m);
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Folha de Obra — <?= e($pat['numero_pat']) ?>/<?= (int)$pat['revisao'] ?></title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root{--gold:#cba35c;--gold-lt:#d8bd83;--gold-deep:#8a6d2f;--dark:#343a40;--mid:#5b6470;--ink:#1c1f24;--muted:#6b7280;--rule:#e2e5ea;--bg:#f7f8fa;--white:#ffffff;--urgent:#b23b3b}
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);font-size:13px;line-height:1.5}
  .toolbar{position:fixed;top:0;left:0;right:0;height:56px;background:var(--dark);display:flex;align-items:center;justify-content:space-between;padding:0 32px;z-index:100;gap:12px}
  .toolbar-left{display:flex;align-items:center;gap:16px}
  .toolbar-title{color:var(--white);font-weight:600;font-size:14px}
  .toolbar-badge{background:var(--gold);color:var(--dark);font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;font-family:'DM Mono',monospace}
  .btn-print{display:flex;align-items:center;gap:8px;background:var(--gold);color:var(--dark);border:none;border-radius:8px;padding:9px 20px;font-family:'DM Sans',sans-serif;font-size:13px;font-weight:700;cursor:pointer;transition:background .2s}
  .btn-print:hover{background:var(--gold-lt)}
  .btn-back{display:flex;align-items:center;gap:6px;background:transparent;color:rgba(255,255,255,.7);border:1px solid rgba(255,255,255,.2);border-radius:8px;padding:8px 16px;font-family:'DM Sans',sans-serif;font-size:13px;cursor:pointer;text-decoration:none;transition:border-color .2s}
  .btn-back:hover{border-color:var(--gold);color:var(--white)}
  .page-wrap{margin-top:72px;padding:32px;display:flex;justify-content:center}
  .sheet{background:var(--white);width:794px;min-height:1123px;box-shadow:0 4px 32px rgba(0,0,0,.12);border-radius:4px;overflow:hidden;display:flex;flex-direction:column}
  .sheet-header{background:var(--dark);padding:22px 32px 20px;display:flex;align-items:flex-start;justify-content:space-between;gap:20px}
  .brand-name{color:var(--white);font-size:18px;font-weight:700;letter-spacing:.04em}
  .brand-sub{color:rgba(255,255,255,.45);font-size:10px;letter-spacing:.12em;text-transform:uppercase}
  .header-center{text-align:center;flex:1}
  .doc-label{color:rgba(255,255,255,.5);font-size:9px;letter-spacing:.18em;text-transform:uppercase;margin-bottom:4px}
  .doc-title{color:var(--white);font-size:15px;font-weight:600}
  .doc-sub{color:rgba(255,255,255,.45);font-size:10px;margin-top:1px}
  .pat-label{color:rgba(255,255,255,.5);font-size:9px;letter-spacing:.16em;text-transform:uppercase;margin-bottom:5px;text-align:right}
  .pat-number{background:var(--gold);color:var(--dark);font-family:'DM Mono',monospace;font-size:13px;font-weight:500;padding:5px 14px;border-radius:6px;display:inline-block}
  .pat-revision{color:rgba(255,255,255,.35);font-size:10px;margin-top:5px;text-align:right}
  .status-bar{background:var(--mid);padding:10px 32px;display:flex;align-items:center;gap:24px;flex-wrap:wrap}
  .status-item{display:flex;align-items:center;gap:8px;font-size:11px;color:rgba(255,255,255,.6)}
  .status-dot{width:7px;height:7px;border-radius:50%;background:var(--muted);flex-shrink:0}
  .status-dot.active{background:var(--gold)}
  .status-value{color:var(--white);font-weight:500}
  .status-sep{width:1px;height:16px;background:rgba(255,255,255,.12)}
  .priority-badge{margin-left:auto;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:3px 10px;border-radius:4px;border:1px solid}
  .priority-normal{color:#6b7280;border-color:rgba(107,114,128,.35);background:rgba(107,114,128,.06)}
  .priority-urgente{color:var(--urgent);border-color:rgba(178,59,59,.4);background:rgba(178,59,59,.07)}
  .sheet-body{flex:1;padding:24px 32px 28px;display:flex;flex-direction:column;gap:18px}
  .section{border:1px solid var(--rule);border-radius:8px;overflow:hidden}
  .section-header{display:flex;align-items:center;gap:10px;padding:9px 14px;background:#f1f3f6;border-bottom:1px solid var(--rule)}
  .section-icon{width:20px;height:20px;border-radius:5px;background:var(--gold);display:flex;align-items:center;justify-content:center;flex-shrink:0}
  .section-icon svg{width:11px;height:11px;fill:none;stroke:var(--dark);stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
  .section-title{font-size:10px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--mid)}
  .section-body{padding:14px}
  .field-grid{display:grid;gap:10px 16px}
  .cols-2{grid-template-columns:1fr 1fr}
  .cols-4{grid-template-columns:1fr 1fr 1fr 1fr}
  .col-full{grid-column:1/-1}
  .field{display:flex;flex-direction:column;gap:4px}
  .field-label{font-size:9px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}
  .field-value{font-size:13px;font-weight:500;color:var(--ink);border-bottom:1.5px solid var(--rule);padding-bottom:5px;min-height:24px}
  .field-value.mono{font-family:'DM Mono',monospace;font-size:12px}
  .field-value.large{min-height:64px;border:1.5px solid var(--rule);border-radius:5px;padding:8px 10px;font-size:12px;font-weight:400;line-height:1.6}
  .field-value.empty{color:var(--muted);font-weight:400;font-style:italic}
  .check-row{display:flex;gap:24px;align-items:center;padding:8px 0 4px}
  .check-item{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:500}
  .check-box{width:16px;height:16px;border:2px solid var(--rule);border-radius:3px;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:var(--white)}
  .check-box.checked{background:var(--gold);border-color:var(--gold)}
  .check-box.checked::after{content:'';width:8px;height:5px;border-left:2px solid var(--dark);border-bottom:2px solid var(--dark);transform:rotate(-45deg) translateY(-1px);display:block}
  .comp-table{width:100%;border-collapse:collapse;font-size:12px}
  .comp-table thead tr{background:#f1f3f6}
  .comp-table th{padding:8px 10px;font-size:9px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);text-align:left;border:1px solid var(--rule)}
  .comp-table td{padding:9px 10px;border:1px solid var(--rule);vertical-align:middle}
  .comp-table td.mono{font-family:'DM Mono',monospace;font-size:11px;color:var(--mid)}
  .comp-table td.center,.comp-table th.center{text-align:center}
  .comp-table .divider-col{width:32px;background:#f7f8fa;text-align:center;color:var(--muted)}
  .comp-table tbody tr:nth-child(even) td:not(.divider-col){background:#fafbfc}
  .tag{display:inline-flex;align-items:center;gap:4px;font-size:9px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;border-radius:3px;padding:2px 6px}
  .tag-removed{color:var(--mid);background:rgba(91,100,112,.08)}
  .tag-placed{color:var(--gold-deep);background:rgba(203,163,92,.16)}
  .removed-col{background:rgba(91,100,112,.04)}
  .placed-col{background:rgba(203,163,92,.05)}
  .sig-row{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:4px}
  .sig-box{border:1px solid var(--rule);border-radius:8px;padding:14px}
  .sig-label{font-size:9px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-bottom:6px;display:block}
  .sig-area{height:52px;border-bottom:1.5px solid var(--rule);margin:4px 0}
  .sig-name-line{font-size:11px;color:var(--muted);display:flex;justify-content:space-between}
  .sheet-footer{border-top:1px solid var(--rule);padding:10px 32px;display:flex;align-items:center;justify-content:space-between;background:#fafbfc}
  .footer-left{font-size:9px;color:var(--muted)}
  .footer-left strong{color:var(--ink)}
  .footer-right{font-size:9px;color:var(--muted);font-family:'DM Mono',monospace}
  .declaration-text{font-size:11px;color:var(--muted);text-align:center;padding:10px 0 14px;border-bottom:1px dashed var(--rule);margin-bottom:16px;line-height:1.6}
  .obs-area{min-height:56px;border:1.5px solid var(--rule);border-radius:5px;padding:8px 10px;font-size:12px;color:var(--ink);line-height:1.6}
  @media print{
    @page{size:A4;margin:0}
    body{background:white}
    .toolbar{display:none!important}
    .page-wrap{margin:0;padding:0}
    .sheet{width:100%;min-height:100vh;box-shadow:none;border-radius:0}
    .section{break-inside:avoid}
  }
</style>
</head>
<body>

<div class="toolbar">
  <div class="toolbar-left">
    <a class="btn-back" href="javascript:history.back()">← Voltar</a>
    <span class="toolbar-title">Folha de Obra</span>
    <span class="toolbar-badge"><?= e($pat['numero_pat']) ?>/<?= (int)$pat['revisao'] ?></span>
  </div>
  <button class="btn-print" onclick="window.print()">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
      <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
      <path d="M6 9V3h12v6"/><rect x="6" y="14" width="12" height="8"/>
    </svg>
    Imprimir / PDF
  </button>
</div>

<div class="page-wrap">
<div class="sheet">

  <div class="sheet-header">
    <div>
      <div class="brand-name">NEWVISION</div>
      <div class="brand-sub">Technology Centre</div>
    </div>
    <div class="header-center">
      <div class="doc-label">Documento Interno</div>
      <div class="doc-title">Worksheet · Folha de Obra</div>
      <div class="doc-sub">Assistência Técnica</div>
    </div>
    <div>
      <div class="pat-label">Nº PAT</div>
      <div class="pat-number"><?= e($pat['numero_pat']) ?></div>
      <div class="pat-revision">Revisão / <?= (int)$pat['revisao'] ?></div>
    </div>
  </div>

  <div class="status-bar">
    <?php if ($pat['data_recepcao']): ?>
    <div class="status-item">
      <div class="status-dot active"></div>
      <span>Receção&nbsp;</span>
      <span class="status-value"><?= fmt($pat['data_recepcao']) ?></span>
    </div>
    <?php endif; ?>
    <?php if ($pat['data_limite']): ?>
    <div class="status-sep"></div>
    <div class="status-item">
      <div class="status-dot"></div>
      <span>Data Limite&nbsp;</span>
      <span class="status-value"><?= fmt($pat['data_limite']) ?></span>
    </div>
    <?php endif; ?>
    <?php if ($pat['tecnico']): ?>
    <div class="status-sep"></div>
    <div class="status-item">
      <div class="status-dot"></div>
      <span>Técnico&nbsp;</span>
      <span class="status-value"><?= e($pat['tecnico']) ?></span>
    </div>
    <?php endif; ?>
    <div class="priority-badge <?= $pat['prioridade']==='Urgente' ? 'priority-urgente' : 'priority-normal' ?>">
      <?= e($pat['prioridade']) ?>
    </div>
  </div>

  <div class="sheet-body">

    <!-- Cliente -->
    <div class="section">
      <div class="section-header">
        <div class="section-icon"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
        <span class="section-title">Cliente</span>
      </div>
      <div class="section-body">
        <div class="field-grid cols-2">
          <div class="field"><span class="field-label">Entidade</span>
            <span class="field-value <?= $pat['entidade']==='' ? 'empty' : '' ?>"><?= $pat['entidade'] !== '' ? e($pat['entidade']) : '—' ?></span></div>
          <div class="field"><span class="field-label">Local</span>
            <span class="field-value <?= $pat['local_cliente']==='' ? 'empty' : '' ?>"><?= $pat['local_cliente'] !== '' ? e($pat['local_cliente']) : '—' ?></span></div>
          <div class="field"><span class="field-label">Contacto</span>
            <span class="field-value <?= $pat['contacto']==='' ? 'empty' : '' ?>"><?= $pat['contacto'] !== '' ? e($pat['contacto']) : '—' ?></span></div>
          <div class="field"><span class="field-label">Morada</span>
            <span class="field-value <?= $pat['morada']==='' ? 'empty' : '' ?>"><?= $pat['morada'] !== '' ? e($pat['morada']) : '—' ?></span></div>
        </div>
      </div>
    </div>

    <!-- Pedido -->
    <div class="section">
      <div class="section-header">
        <div class="section-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8"/></svg></div>
        <span class="section-title">Pedido de Assistência Técnica</span>
      </div>
      <div class="section-body">
        <div class="check-row">
          <div class="check-item"><div class="check-box <?= $pat['garantia'] ? 'checked' : '' ?>"></div><span>Ao Abrigo da Garantia</span></div>
          <div class="check-item"><div class="check-box <?= $pat['contrato_manutencao'] ? 'checked' : '' ?>"></div><span>Ao Abrigo do Contrato de Manutenção</span></div>
        </div>
        <div class="field" style="margin-top:12px">
          <span class="field-label">Descrição do Pedido</span>
          <div class="field-value large <?= $pat['descricao']==='' ? 'empty' : '' ?>"><?= $pat['descricao'] !== '' ? nl2br(e($pat['descricao'])) : 'Sem descrição.' ?></div>
        </div>
      </div>
    </div>

    <!-- Técnico -->
    <div class="section">
      <div class="section-header">
        <div class="section-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg></div>
        <span class="section-title">Técnico NewVision</span>
      </div>
      <div class="section-body">
        <div class="field-grid cols-2">
          <div class="field"><span class="field-label">Técnico Responsável</span>
            <span class="field-value <?= $pat['tecnico']==='' ? 'empty' : '' ?>"><?= $pat['tecnico'] !== '' ? e($pat['tecnico']) : '—' ?></span></div>
          <div class="field"><span class="field-label">Comentários / Instruções</span>
            <div class="field-value large" style="min-height:44px"><?= $pat['comentarios'] !== '' ? nl2br(e($pat['comentarios'])) : '' ?></div></div>
        </div>
      </div>
    </div>

    <!-- Módulos -->
    <?php if (!empty(array_filter($modulos, fn($m) => $m['solucao_equipamento'] !== '' || $m['modelo'] !== '' || $m['num_serie'] !== ''))
              || true): // Mostrar sempre ?>
    <div class="section">
      <div class="section-header">
        <div class="section-icon"><svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg></div>
        <span class="section-title">Módulos para Assistência</span>
      </div>
      <div class="section-body">
        <table class="comp-table">
          <thead><tr><th style="width:40%">Solução / Equipamento</th><th style="width:30%">Modelo</th><th>Nº de Série</th></tr></thead>
          <tbody>
            <?php foreach ($modulos as $m): ?>
            <tr>
              <td><?= e($m['solucao_equipamento']) ?></td>
              <td><?= e($m['modelo']) ?></td>
              <td class="mono"><?= e($m['num_serie']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- Intervenção -->
    <div class="section">
      <div class="section-header">
        <div class="section-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></div>
        <span class="section-title">Intervenção</span>
      </div>
      <div class="section-body">
        <div class="field-grid cols-4">
          <div class="field"><span class="field-label">Data Inicial</span>
            <span class="field-value mono"><?= $pat['data_inicio'] ? fmt($pat['data_inicio'],'d/m/Y') : '___/___/______' ?></span></div>
          <div class="field"><span class="field-label">Hora Inicial</span>
            <span class="field-value mono"><?= $pat['data_inicio'] ? fmt($pat['data_inicio'],'H:i') : '___:___' ?></span></div>
          <div class="field"><span class="field-label">Data Final</span>
            <span class="field-value mono"><?= $pat['data_fim'] ? fmt($pat['data_fim'],'d/m/Y') : '___/___/______' ?></span></div>
          <div class="field"><span class="field-label">Duração</span>
            <span class="field-value mono"><?= $duracaoStr ?: '___:___' ?></span></div>
          <div class="field col-full"><span class="field-label">Técnicos Presentes</span>
            <span class="field-value"><?= e($pat['tecnicos_presentes']) ?></span></div>
        </div>
      </div>
    </div>

    <!-- Componentes -->
    <div class="section">
      <div class="section-header">
        <div class="section-icon"><svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></div>
        <span class="section-title">Módulos e Componentes Trocados</span>
      </div>
      <div class="section-body">
        <table class="comp-table">
          <thead>
            <tr>
              <th class="removed-col" style="width:24%"><span class="tag tag-removed">▼ Removido</span></th>
              <th class="removed-col" style="width:20%">Nº Série</th>
              <th class="divider-col center" style="width:32px">↔</th>
              <th class="placed-col"  style="width:24%"><span class="tag tag-placed">▲ Colocado</span></th>
              <th class="placed-col"  style="width:20%">Nº Série</th>
              <th class="center"      style="width:6%">Qtd</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($componentes as $c): ?>
            <tr>
              <td class="removed-col"><?= e($c['removido']) ?></td>
              <td class="removed-col mono"><?= e($c['sn_removido']) ?></td>
              <td class="divider-col"></td>
              <td class="placed-col"><?= e($c['colocado']) ?></td>
              <td class="placed-col mono"><?= e($c['sn_colocado']) ?></td>
              <td class="center"><?= (int)$c['quantidade'] > 1 ? (int)$c['quantidade'] : '' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Observações -->
    <div class="section">
      <div class="section-header">
        <div class="section-icon"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>
        <span class="section-title">Observações</span>
      </div>
      <div class="section-body">
        <div class="obs-area"><?= $pat['observacoes'] !== '' ? nl2br(e($pat['observacoes'])) : '<span style="color:var(--muted);font-style:italic;">Sem observações.</span>' ?></div>
      </div>
    </div>

    <!-- Declaração -->
    <div class="section">
      <div class="section-header">
        <div class="section-icon"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div>
        <span class="section-title">Declaração</span>
      </div>
      <div class="section-body">
        <div class="declaration-text">
          Declaramos que a intervenção foi realizada conforme o descrito neste documento.<br>
          <em>We declare that the intervention was carried out as described in this document.</em>
        </div>
        <div class="sig-row">
          <div class="sig-box">
            <span class="sig-label">Assinatura do Cliente</span>
            <div class="sig-area"></div>
            <div class="sig-name-line"><span>Nome / Name</span><span>Data / Date</span></div>
          </div>
          <div class="sig-box">
            <span class="sig-label">Assinatura do Técnico</span>
            <div class="sig-area"></div>
            <div class="sig-name-line"><span><?= e($pat['tecnico']) ?></span><span>Data / Date</span></div>
          </div>
        </div>
      </div>
    </div>

  </div>

  <div class="sheet-footer">
    <div class="footer-left"><strong>NEWVISION Technology Centre, S.A.</strong> &nbsp;·&nbsp; NIF 504 983 474 &nbsp;·&nbsp; T. +351 211 991 510 &nbsp;·&nbsp; finance@newvision.pt</div>
    <div class="footer-right">Pág. 1 / 1</div>
  </div>

</div>
</div>
</body>
</html>
