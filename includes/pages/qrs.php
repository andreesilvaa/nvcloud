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

    <style>
      @media (max-width:760px){ .qr-grid{ grid-template-columns:1fr !important; } }
    </style>
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
                        <!-- ✅ Peça encontrada -->
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

                        <!-- Opção D — quick-action bar -->
                        <div class="qr-actions">
                            <a class="btn btn-yellow" href="app.php?page=nova_peca&edit=<?= (int)$qrResultado['id'] ?>"><i class="bi bi-pencil"></i> Editar</a>
                            <a class="btn btn-grey" href="app.php?page=historico&id=<?= (int)$qrResultado['id'] ?>"><i class="bi bi-clock-history"></i> Ver Histórico</a>
                            <a class="btn btn-blue" href="app.php?page=pats&acao=novo"><i class="bi bi-clipboard-plus"></i> Atribuir PAT</a>
                            <button type="button" class="btn btn-teal" onclick="qrCopySN('<?= htmlspecialchars($qrResultado['sn'], ENT_QUOTES) ?>', this)"><i class="bi bi-clipboard"></i> Copiar SN</button>
                        </div>

                    <?php else: ?>
                        <div class="qr-banner qr-no"><i class="bi bi-x-circle-fill"></i> Peça não encontrada</div>
                        <!-- ❌ Não encontrado -->
                        <div style="text-align:center; padding:24px 16px;">
                            <div style="font-size:40px; margin-bottom:12px;">🔍</div>
                            <p style="font-weight:600; margin-bottom:4px;">Nenhuma peça encontrada</p>
                            <p style="font-size:13px; color:#6b7280; margin-bottom:18px;">
                                Não existe nenhuma peça com o SN/código <strong><?= htmlspecialchars($qrTermo) ?></strong>.
                            </p>
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
