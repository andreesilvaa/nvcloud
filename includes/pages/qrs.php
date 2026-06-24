<?php
$qrResultado = null;
$qrTermo = trim($_GET['qr_code'] ?? '');

  if ($page === 'qrs' && $qrTermo !== ''){
    $stmtQr = $pdo->prepare("
      SELECT *
      FROM pecas
      WHERE sn = ? OR cod_barras = ?
      ORDER BY id DESC
      LIMIT 1
    ");
  $stmtQr->execute([$qrTermo, $qrTermo]);
  $qrResultado = $stmtQr->fetch();

  if (!$qrResultado) {
    $_SESSION['mensagem_erro'] = 'Nenhuma peça encontrada. Preenche os restantes dados para criar uma nova peça.';
    $_SESSION['form_nova_peca'] = [
      'sn' =>$qrTermo,
      'cod_barras' => $qrTermo
    ];

    header('Location: app.php?page=nova_peca');
    exit;
  }
}
?>

<?php
// ── Dados para etiquetas ──────────────────────────────────────
$etqCategorias = $pdo->query("SELECT DISTINCT categoria FROM pecas WHERE categoria IS NOT NULL AND categoria != '' ORDER BY categoria")->fetchAll(PDO::FETCH_COLUMN);
$etqPecas      = $pdo->query("SELECT id, produto, sn FROM pecas WHERE sn IS NOT NULL AND sn != '' ORDER BY produto, sn")->fetchAll(PDO::FETCH_ASSOC);
// Agrupar peças por categoria para o JS
$etqPorCategoria = [];
foreach ($etqPecas as $p) {
    // A categoria não está na query acima; faz uma query com categoria
}
$etqPecasFull = $pdo->query("SELECT id, produto, categoria, sn FROM pecas WHERE sn IS NOT NULL AND sn != '' ORDER BY produto, sn")->fetchAll(PDO::FETCH_ASSOC);
$etqPorCategoria = [];
foreach ($etqPecasFull as $p) {
    $cat = $p['categoria'] ?? 'Sem Categoria';
    $etqPorCategoria[$cat][] = ['id' => $p['id'], 'produto' => $p['produto'], 'sn' => $p['sn']];
}
?>

<!-- Carregar JsBarcode para gerar códigos de barras -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>

<style>
    /* ── Toggle Etiquetas / Leitor ── */
    .qr-mode-toggle { display:flex; gap:0; margin-bottom:20px; border:1px solid #e5e9ef; border-radius:10px; overflow:hidden; width:fit-content; }
    .qr-mode-btn { padding:9px 24px; font-size:14px; font-weight:600; background:#f8fafc; border:none; cursor:pointer; color:#6b7280; transition:.15s; }
    .qr-mode-btn.active { background:#1d4ed8; color:#fff; }
    .qr-mode-btn:first-child { border-right:1px solid #e5e9ef; }

    /* ── Painel Etiquetas ── */
    .etq-panel { background:#fff; border:1px solid #e5e9ef; border-radius:12px; padding:20px; max-width:700px; }
    .etq-row { display:flex; gap:14px; flex-wrap:wrap; align-items:flex-end; margin-bottom:16px; }
    .etq-field { display:flex; flex-direction:column; gap:5px; }
    .etq-field label { font-size:12px; font-weight:600; color:#374151; text-transform:uppercase; letter-spacing:.4px; }
    .etq-field select, .etq-field input[type=number] { height:40px; border:1px solid #e5e9ef; border-radius:9px; padding:0 12px; font-size:14px; background:#f8fafc; min-width:160px; }
    .etq-field input[type=number] { min-width:80px; max-width:100px; }

    /* ── Pré-visualização ── */
    .etq-preview-area { margin-top:20px; border-top:1px solid #e5e9ef; padding-top:18px; }
    .etq-preview-grid { display:flex; flex-wrap:wrap; gap:10px; }

    /* ── Etiqueta Pequena 50×30mm ── */
    .etq-label-small {
        width:189px; height:113px; /* 50×30mm @ 96dpi */
        border:1px solid #ccc; border-radius:4px;
        background:#fff; display:flex; flex-direction:column;
        align-items:center; justify-content:space-between;
        padding:6px 8px 4px; font-family:'Poppins',sans-serif;
        page-break-inside:avoid; overflow:hidden;
    }
    /* ── Etiqueta Média 62×29mm ── */
    .etq-label-medium {
        width:234px; height:110px; /* 62×29mm @ 96dpi */
        border:1px solid #ccc; border-radius:4px;
        background:#fff; display:flex; flex-direction:column;
        align-items:center; justify-content:space-between;
        padding:6px 10px 4px; font-family:'Poppins',sans-serif;
        page-break-inside:avoid; overflow:hidden;
    }
    /* ── A4 Grelha ── */
    .etq-label-a4 {
        width:189px; height:113px;
        border:1px solid #ccc; border-radius:4px;
        background:#fff; display:flex; flex-direction:column;
        align-items:center; justify-content:space-between;
        padding:6px 8px 4px; font-family:'Poppins',sans-serif;
        page-break-inside:avoid;
    }

    /* Conteúdo interno da etiqueta */
    .etq-brand { font-size:9px; font-weight:800; color:#1a1a2e; letter-spacing:1px; text-transform:uppercase; width:100%; }
    .etq-brand span { color:#c9a14a; }
    .etq-produto { font-size:8.5px; font-weight:600; color:#1e293b; text-align:center; line-height:1.2; max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .etq-barcode-wrap { display:flex; flex-direction:column; align-items:center; gap:1px; width:100%; }
    .etq-barcode-wrap svg { max-width:100%; height:30px; }
    .etq-sn { font-size:7px; font-family:monospace; color:#374151; }

    /* ── Impressão ── */
    @media print {
        body > *:not(#etqPrintArea) { display:none !important; }
        #etqPrintArea { display:block !important; }
        .etq-label-small, .etq-label-medium, .etq-label-a4 { border:1px solid #999; }
        .etq-print-grid-a4 { display:grid; grid-template-columns: repeat(4, 1fr); gap:4mm; padding:8mm; }
    }

    @media (max-width:760px){ .qr-grid{ grid-template-columns:1fr !important; } }
</style>

<!-- Toggle de modo -->
<div class="qr-mode-toggle">
    <button type="button" class="qr-mode-btn" id="btnModoEtiquetas" onclick="qrSetModo('etiquetas')">
        <i class="bi bi-tag"></i> Etiquetas
    </button>
    <button type="button" class="qr-mode-btn active" id="btnModoLeitor" onclick="qrSetModo('leitor')">
        <i class="bi bi-qr-code-scan"></i> Leitor
    </button>
</div>

<!-- ══════════════ MODO ETIQUETAS ══════════════ -->
<div id="painel-etiquetas" style="display:none;">
    <div class="etq-panel">
        <div class="etq-row">
            <div class="etq-field" style="flex:1; min-width:160px;">
                <label>Tipo de peça</label>
                <select id="etqTipo" onchange="etqAtualizarPecas()">
                    <option value="">-- Todos --</option>
                    <?php foreach ($etqCategorias as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="etq-field" style="flex:2; min-width:200px;">
                <label>Peça</label>
                <select id="etqPeca">
                    <option value="">-- Seleciona uma peça --</option>
                    <?php foreach ($etqPecasFull as $p): ?>
                        <option value="<?= htmlspecialchars($p['sn']) ?>"
                                data-produto="<?= htmlspecialchars($p['produto']) ?>"
                                data-categoria="<?= htmlspecialchars($p['categoria'] ?? '') ?>">
                            <?= htmlspecialchars($p['produto']) ?> — <?= htmlspecialchars($p['sn']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="etq-field">
                <label>Tamanho</label>
                <select id="etqTamanho">
                    <option value="small">Pequena — 50×30mm</option>
                    <option value="medium">Média — 62×29mm</option>
                    <option value="a4">A4 grelha</option>
                </select>
            </div>
            <div class="etq-field">
                <label>Quantidade</label>
                <input type="number" id="etqQtd" value="1" min="1" max="50">
            </div>
        </div>
        <div style="display:flex; gap:10px;">
            <button type="button" class="btn btn-blue" onclick="etqPrevisualizar()">
                <i class="bi bi-eye"></i> Pré-visualizar
            </button>
            <button type="button" class="btn btn-teal" onclick="etqImprimir()">
                <i class="bi bi-printer"></i> Imprimir / PDF
            </button>
        </div>

        <!-- Área de pré-visualização -->
        <div class="etq-preview-area" id="etqPreviewArea" style="display:none;">
            <p style="font-size:12px; color:#6b7280; margin-bottom:10px;">Pré-visualização (<?= '<span id="etqPreviewCount">0</span>' ?> etiqueta(s))</p>
            <div class="etq-preview-grid" id="etqPreviewGrid"></div>
        </div>
    </div>
</div>

<!-- ══════════════ MODO LEITOR ══════════════ -->
<div id="painel-leitor">
    <div class="qr-grid" style="display:grid; grid-template-columns:300px 1fr; gap:18px; align-items:start; max-width:980px;">

        <!-- PAINEL ESQUERDO: Câmara -->
        <div class="panel">
            <h4 style="margin-bottom:4px;"><i class="bi bi-camera" style="color:#c9a14a; margin-right:6px;"></i>Leitura Automática</h4>
            <p style="font-size:12px; color:#6b7280; margin-bottom:14px;">Permite o acesso à câmara e aponta para o código.</p>
            <div id="reader" style="width:100%; max-width:260px; margin:0 auto; border-radius:10px; overflow:hidden; background:#000; aspect-ratio:1;"></div>
        </div>

        <!-- PAINEL DIREITO: Pesquisa manual + Resultado -->
        <div style="display:flex; flex-direction:column; gap:16px;">

            <!-- Caixa de pesquisa -->
            <div class="panel">
                <h4 style="margin-bottom:14px;"><i class="bi bi-search" style="color:#c9a14a; margin-right:6px;"></i>Pesquisa Manual</h4>
                <form method="get">
                    <input type="hidden" name="page" value="qrs">
                    <div style="display:flex; gap:10px;">
                        <input type="text" name="qr_code" id="qr_code"
                               value="<?= htmlspecialchars($qrTermo) ?>"
                               placeholder="SN ou Código de Barras"
                               style="flex:1;">
                        <button type="submit" class="btn btn-blue">Procurar</button>
                        <?php if ($qrTermo !== ''): ?>
                            <a href="app.php?page=qrs" class="btn btn-grey">✕</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Resultado -->
            <?php if ($qrTermo !== ''): ?>
                <div class="panel">
                    <?php if ($qrResultado): ?>
                        <div class="qr-banner qr-ok"><i class="bi bi-check-circle-fill"></i> Peça encontrada</div>
                        <div style="display:flex; align-items:center; gap:10px; margin-bottom:18px; padding-bottom:14px; border-bottom:1px solid #e5e7eb;">
                            <div style="width:44px; height:44px; background:#dcfce7; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0;">✅</div>
                            <div>
                                <div style="font-weight:700; font-size:16px;"><?= htmlspecialchars($qrResultado['produto']) ?></div>
                                <div style="font-size:12px; color:#6b7280; font-family:monospace;"><?= htmlspecialchars($qrResultado['sn']) ?></div>
                            </div>
                        </div>
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:18px;">
                            <div style="background:#f8f9fa; border-radius:8px; padding:10px 14px;">
                                <div style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px;">ID</div>
                                <div style="font-weight:600;">#<?= (int)$qrResultado['id'] ?></div>
                            </div>
                            <div style="background:#f8f9fa; border-radius:8px; padding:10px 14px;">
                                <div style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px;">Categoria</div>
                                <div style="font-weight:600;"><?= htmlspecialchars($qrResultado['categoria']) ?></div>
                            </div>
                            <div style="background:#f8f9fa; border-radius:8px; padding:10px 14px;">
                                <div style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px;">Parceiro</div>
                                <div style="font-weight:600;"><?= htmlspecialchars($qrResultado['parceiro'] ?: '—') ?></div>
                            </div>
                            <div style="background:#f8f9fa; border-radius:8px; padding:10px 14px;">
                                <div style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px;">Estado</div>
                                <div style="font-weight:600;"><?= estadoBolha($qrResultado['estado']) ?></div>
                            </div>
                            <div style="background:#f8f9fa; border-radius:8px; padding:10px 14px; grid-column:1/-1;">
                                <div style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px;">Código de Barras</div>
                                <div style="font-weight:600; font-family:monospace;"><?= htmlspecialchars($qrResultado['cod_barras'] ?: '—') ?></div>
                            </div>
                        </div>
                        <div class="qr-actions">
                            <a class="btn btn-yellow" href="app.php?page=nova_peca&edit=<?= (int)$qrResultado['id'] ?>"><i class="bi bi-pencil"></i> Editar</a>
                            <a class="btn btn-grey" href="app.php?page=historico&id=<?= (int)$qrResultado['id'] ?>"><i class="bi bi-clock-history"></i> Ver Histórico</a>
                            <a class="btn btn-blue" href="app.php?page=pats&acao=novo"><i class="bi bi-clipboard-plus"></i> Atribuir PAT</a>
                            <button type="button" class="btn btn-teal" onclick="qrCopySN('<?= htmlspecialchars($qrResultado['sn'], ENT_QUOTES) ?>', this)"><i class="bi bi-clipboard"></i> Copiar SN</button>
                        </div>
                    <?php else: ?>
                        <div class="qr-banner qr-no"><i class="bi bi-x-circle-fill"></i> Peça não encontrada</div>
                        <div style="text-align:center; padding:24px 16px;">
                            <div style="font-size:40px; margin-bottom:12px;">🔍</div>
                            <p style="font-weight:600; margin-bottom:4px;">Nenhuma peça encontrada</p>
                            <p style="font-size:13px; color:#6b7280; margin-bottom:18px;">Não existe nenhuma peça com o SN/código <strong><?= htmlspecialchars($qrTermo) ?></strong>.</p>
                            <a class="btn btn-teal" href="app.php?page=nova_peca&sn=<?= urlencode($qrTermo) ?>&cod_barras=<?= urlencode($qrTermo) ?>">
                                <i class="bi bi-plus-circle"></i> Criar nova peça com este SN
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="panel" style="text-align:center; color:#9ca3af; padding:28px 20px;">
                    <i class="bi bi-upc-scan" style="font-size:34px; display:block; margin-bottom:10px; opacity:.4;"></i>
                    <p style="font-size:14px; margin:0 0 4px; color:#6b7280;">Lê um código com a câmara ou pesquisa um SN/código acima.</p>
                    <p style="font-size:12.5px; margin:0;">O resultado aparece aqui.</p>
                </div>
            <?php endif; ?>

        </div><!-- fim coluna direita -->
    </div><!-- fim grid -->
</div><!-- fim painel-leitor -->

<!-- Área oculta para impressão -->
<div id="etqPrintArea" style="display:none;"></div>

<style>
    .qr-banner{ display:flex; align-items:center; gap:8px; padding:10px 14px; border-radius:8px; font-weight:600; font-size:13.5px; margin-bottom:16px; }
    .qr-banner.qr-ok{ background:#dcfce7; color:#15803d; }
    .qr-banner.qr-no{ background:#fee2e2; color:#dc2626; }
    .qr-actions{ display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .qr-actions .btn{ display:inline-flex; align-items:center; justify-content:center; gap:7px; padding:12px 14px; font-size:14px; }
    @media (max-width:600px){ .qr-actions{ grid-template-columns:1fr; } }
</style>

<script>
    // ── Dados de peças por categoria (gerado em PHP) ──
    var etqDadosPorCategoria = <?= json_encode($etqPorCategoria, JSON_UNESCAPED_UNICODE) ?>;

    // ── Toggle de modo ──
    function qrSetModo(modo) {
        document.getElementById('painel-etiquetas').style.display = modo === 'etiquetas' ? 'block' : 'none';
        document.getElementById('painel-leitor').style.display     = modo === 'leitor'    ? 'block' : 'none';
        document.getElementById('btnModoEtiquetas').classList.toggle('active', modo === 'etiquetas');
        document.getElementById('btnModoLeitor').classList.toggle('active', modo === 'leitor');
    }

    // ── Filtrar peças por categoria ──
    function etqAtualizarPecas() {
        var cat = document.getElementById('etqTipo').value;
        var sel = document.getElementById('etqPeca');
        var opts = sel.querySelectorAll('option');
        opts.forEach(function(o) {
            if (!o.value) return; // placeholder
            var oCat = o.getAttribute('data-categoria');
            o.style.display = (!cat || oCat === cat) ? '' : 'none';
        });
        sel.value = '';
    }

    // ── Construir HTML de uma etiqueta ──
    function etqCriarHTML(sn, produto, tamanho) {
        var cls = tamanho === 'medium' ? 'etq-label-medium' : (tamanho === 'a4' ? 'etq-label-a4' : 'etq-label-small');
        var uid = 'bc_' + Math.random().toString(36).substr(2,9);
        return '<div class="' + cls + '">'
            + '<div class="etq-brand">New<span>Vision</span> <span style="color:#6b7280;font-weight:400;font-size:7px;">technology centre</span></div>'
            + '<div class="etq-produto">' + produto.replace(/</g,'&lt;') + '</div>'
            + '<div class="etq-barcode-wrap"><svg id="' + uid + '"></svg><div class="etq-sn">' + sn.replace(/</g,'&lt;') + '</div></div>'
            + '</div>';
    }

    // ── Pré-visualizar ──
    function etqPrevisualizar() {
        var sn      = document.getElementById('etqPeca').value;
        var tamanho = document.getElementById('etqTamanho').value;
        var qtd     = parseInt(document.getElementById('etqQtd').value) || 1;
        var selOpt  = document.getElementById('etqPeca').selectedOptions[0];
        var produto = selOpt ? selOpt.getAttribute('data-produto') : '—';

        if (!sn) { alert('Seleciona uma peça primeiro.'); return; }

        var grid = document.getElementById('etqPreviewGrid');
        var html = '';
        for (var i = 0; i < qtd; i++) html += etqCriarHTML(sn, produto, tamanho);
        grid.innerHTML = html;

        // Renderizar barcodes
        grid.querySelectorAll('svg[id^="bc_"]').forEach(function(svg) {
            try { JsBarcode(svg, sn, { format:'CODE128', displayValue:false, height:28, margin:2, lineColor:'#1e293b' }); } catch(e){}
        });

        document.getElementById('etqPreviewArea').style.display = 'block';
        document.getElementById('etqPreviewCount').textContent = qtd;
    }

    // ── Imprimir ──
    function etqImprimir() {
        var sn      = document.getElementById('etqPeca').value;
        var tamanho = document.getElementById('etqTamanho').value;
        var qtd     = parseInt(document.getElementById('etqQtd').value) || 1;
        var selOpt  = document.getElementById('etqPeca').selectedOptions[0];
        var produto = selOpt ? selOpt.getAttribute('data-produto') : '—';

        if (!sn) { alert('Seleciona uma peça primeiro.'); return; }

        var printDiv = document.getElementById('etqPrintArea');
        var html = '';
        if (tamanho === 'a4') {
            html = '<div class="etq-print-grid-a4">';
            for (var i = 0; i < qtd; i++) html += etqCriarHTML(sn, produto, 'a4');
            html += '</div>';
        } else {
            html = '<div style="display:flex;flex-wrap:wrap;gap:6mm;padding:8mm;">';
            for (var i = 0; i < qtd; i++) html += etqCriarHTML(sn, produto, tamanho);
            html += '</div>';
        }
        printDiv.innerHTML = html;
        printDiv.style.display = 'block';

        printDiv.querySelectorAll('svg[id^="bc_"]').forEach(function(svg) {
            try { JsBarcode(svg, sn, { format:'CODE128', displayValue:false, height:28, margin:2, lineColor:'#1e293b' }); } catch(e){}
        });

        setTimeout(function() {
            window.print();
            printDiv.style.display = 'none';
        }, 300);
    }

    // ── Copiar SN ──
    function qrCopySN(sn, btn){
        try { if (navigator.clipboard) navigator.clipboard.writeText(sn); } catch(e){}
        var o = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2"></i> Copiado';
        setTimeout(function(){ btn.innerHTML = o; }, 1500);
    }
</script>

<style>
.qr-banner{ display:flex; align-items:center; gap:8px; padding:10px 14px; border-radius:8px; font-weight:600; font-size:13.5px; margin-bottom:16px; }
.qr-banner.qr-ok{ background:#dcfce7; color:#15803d; }
.qr-banner.qr-no{ background:#fee2e2; color:#dc2626; }
.qr-actions{ display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.qr-actions .btn{ display:inline-flex; align-items:center; justify-content:center; gap:7px; padding:12px 14px; font-size:14px; }
@media (max-width:600px){ .qr-actions{ grid-template-columns:1fr; } }
</style>
<script>
function qrCopySN(sn, btn){
  try { if (navigator.clipboard) navigator.clipboard.writeText(sn); } catch(e){}
  var o = btn.innerHTML;
  btn.innerHTML = '<i class="bi bi-check2"></i> Copiado';
  setTimeout(function(){ btn.innerHTML = o; }, 1500);
}
</script>
