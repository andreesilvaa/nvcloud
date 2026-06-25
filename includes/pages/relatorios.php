<?php
// HANDLER: Relatórios / Upload Manual
// ══════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'relatorio_upload') {
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
        flashError('Ação inválida.'); redirectTo('app.php?page=relatorios');
    }
    if (($_FILES['relatorio']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flashError('Nenhum ficheiro válido enviado.'); redirectTo('app.php?page=relatorios');
    }
    $f = $_FILES['relatorio'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf','eml'], true)) {
        flashError('O ficheiro tem de ser PDF ou EML.'); redirectTo('app.php?page=relatorios');
    }

    $dir = __DIR__ . '/uploads/relatorios/';
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }

    $hash = hash_file('sha256', $f['tmp_name']);
    // anti-duplicado (mesmo ficheiro)
    $dup = $pdo->prepare("SELECT id, estado FROM relatorios WHERE hash_unico = ? LIMIT 1");
    $dup->execute([$hash]);
    if ($jaId = $dup->fetchColumn()) {
        flashError('Este relatório já foi importado (#' . (int)$jaId . ').');
        redirectTo('app.php?page=relatorios&ver=' . (int)$jaId);
    }

    $destino = $dir . $hash . '.' . $ext;
    move_uploaded_file($f['tmp_name'], $destino);

    try {
        $parse = nvParseRelatorio($destino, $f['name']);
        $res = nvReconciliarRelatorio($pdo, $parse, [
                'origem' => 'manual',
                'ficheiro_nome' => $f['name'],
                'ficheiro_path' => 'uploads/relatorios/' . basename($destino),
                'hash_unico' => $hash,
        ]);
        flashSuccess('Relatório importado. Reveja e aprove.');
        redirectTo('app.php?page=relatorios&ver=' . $res['relatorio_id']);
    } catch (Throwable $e) {
        error_log('[nvcloud] Erro a processar relatório: ' . $e->getMessage());
        \Sentry\captureException($e);
        flashError('Não foi possível processar o relatório. Verifica o ficheiro e tenta novamente.');
        redirectTo('app.php?page=relatorios');
    }
}

// ── RELATÓRIOS: aprovar / rejeitar ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'relatorio_decidir') {
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
        flashError('Ação inválida.'); redirectTo('app.php?page=relatorios');
    }
    $relId = (int)($_POST['relatorio_id'] ?? 0);
    $dec   = $_POST['decisao'] ?? '';
    $user  = $_SESSION['user_nome'] ?? 'Sistema';

    if ($dec === 'aprovar') {
        // decisões por linha (do ecrã rever): decisoes[ID][acao], decisoes[ID][cliente]
        $decisoes = $_POST['decisoes'] ?? [];
        $r = nvAplicarRelatorio($pdo, $relId, $decisoes, $user);
        $r['ok'] ? flashSuccess($r['msg']) : flashError($r['msg']);
    } elseif ($dec === 'rejeitar') {
        $pdo->prepare("UPDATE relatorios SET estado='rejeitado' WHERE id=?")->execute([$relId]);
        flashSuccess('Relatório rejeitado.');
    }
    redirectTo('app.php?page=relatorios&ver=' . $relId);
}

// ══════════════════════════════════════════════

    $verId      = (int)($_GET['ver'] ?? 0);
    $fonteAtiva = $_GET['fonte'] ?? '';

    // "Parceiros" = quem envia os relatórios (fonte). Contagem por fonte,
    // sempre sobre o total (independente do filtro atual).
    $fontesInfo = [
        'cronotecnica'  => ['nome' => 'Cronotécnica',   'icone' => 'bi-building'],
        'konica'        => ['nome' => 'Konica Minolta', 'icone' => 'bi-building'],
        'field_service' => ['nome' => 'Field Service',  'icone' => 'bi-tools'],
        'desconhecido'  => ['nome' => 'Desconhecido',   'icone' => 'bi-question-circle'],
    ];
    $contagemFontes = array_fill_keys(array_keys($fontesInfo), 0);
    foreach ($pdo->query("SELECT fonte, COUNT(*) AS n FROM relatorios GROUP BY fonte") as $row) {
        $contagemFontes[$row['fonte']] = (int)$row['n'];
    }
    $totalRelatorios = array_sum($contagemFontes);

    // Lista (filtrada pelo parceiro/fonte selecionado, se houver)
    if ($fonteAtiva !== '' && isset($fontesInfo[$fonteAtiva])) {
        $stLista = $pdo->prepare("SELECT id, ficheiro_nome, fonte, pat_numero, cliente_detect, estado, criado_em
                                    FROM relatorios WHERE fonte = ? ORDER BY criado_em DESC LIMIT 200");
        $stLista->execute([$fonteAtiva]);
        $lista = $stLista->fetchAll();
    } else {
        $lista = $pdo->query("SELECT id, ficheiro_nome, fonte, pat_numero, cliente_detect, estado, criado_em
                                FROM relatorios ORDER BY criado_em DESC LIMIT 200")->fetchAll();
    }

    // Detalhe (se ?ver=)
    $det = null; $linhas = [];
    if ($verId) {
        $s = $pdo->prepare("SELECT * FROM relatorios WHERE id=?"); $s->execute([$verId]);
        $det = $s->fetch();
        if ($det) {
            $sl = $pdo->prepare("SELECT * FROM relatorios_pecas WHERE relatorio_id=?");
            $sl->execute([$verId]); $linhas = $sl->fetchAll();
        }
    }

    // Cores do estado (mesma lógica de badges usada na página Envios)
    $corEstado = static function (string $estado): array {
        return match ($estado) {
            'por_confirmar'  => ['#fef3c7', '#92400e'],
            'revisao_manual' => ['#ffedd5', '#c2410c'],
            'aprovado'       => ['#dcfce7', '#15803d'],
            'rejeitado'      => ['#fee2e2', '#b91c1c'],
            'duplicado'      => ['#f3f4f6', '#374151'],
            default          => ['#f3f4f6', '#374151'],
        };
    };
    $linkBase = 'app.php?page=relatorios' . ($fonteAtiva !== '' ? '&fonte=' . urlencode($fonteAtiva) : '');
    ?>

<?php if ($det): ?>
<!-- == Linha Superior: Importar Relatório (Esquerda) + Validação (Direita) — só ao rever == -->
<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; align-items:start; margin-bottom:20px;">
   <div class="panel" style="height:100%;">
      <h4 style="margin-bottom:16px;"><i class="bi bi-file-earmark-pdf" style="margin-right:6px; color:#c9a14a;"></i>Importar Relatório</h4>
      <form method="post" enctype="multipart/form-data" action="app.php?page=relatorios">
          <input type="hidden" name="action" value="relatorio_upload">
          <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
          <div style="margin-bottom:0;">
              <label for="relatorio_input" class="upload-pdf-box" id="uploadRelatorioBox">
                  <span class="upload-pdf-icon"><i class="bi bi-cloud-arrow-up-fill"></i></span>
                  <span class="upload-pdf-text" id="uploadRelatorioText">
                      <strong>Clica para escolher um ficheiro</strong><br>ou arrasta o PDF/EML para aqui
                  </span>
                  <span class="upload-pdf-filename" id="uploadRelatorioFilename" style="display:none;"></span>
              </label>
              <input type="file" id="relatorio_input" name="relatorio" accept=".pdf,.eml" required class="upload-pdf-input">
          </div>
          <button type="submit" class="btn-ler-guia"><i class="bi bi-search"></i> Importar Relatório</button>
      </form>
   </div>

   <script>
   (function () {
       const input = document.getElementById('relatorio_input');
       const box = document.getElementById('uploadRelatorioBox');
       const texto = document.getElementById('uploadRelatorioText');
       const nomeFicheiro = document.getElementById('uploadRelatorioFilename');
       if (!input || !box || !texto || !nomeFicheiro) return;

       function mostrarFicheiro(file) {
           if (!file) {
               texto.style.display = '';
               nomeFicheiro.style.display = 'none';
               nomeFicheiro.innerHTML = '';
               return;
           }
           texto.style.display = 'none';
           nomeFicheiro.style.display = 'flex';
           nomeFicheiro.innerHTML = '<i class="bi bi-file-earmark-text-fill"></i><span></span>';
           nomeFicheiro.querySelector('span').textContent = file.name;
       }

       input.addEventListener('change', function () {
           mostrarFicheiro(input.files && input.files[0] ? input.files[0] : null);
       });

       ['dragenter', 'dragover'].forEach(function (evt) {
           box.addEventListener(evt, function (e) { e.preventDefault(); box.classList.add('is-dragover'); });
       });
       ['dragleave', 'drop'].forEach(function (evt) {
           box.addEventListener(evt, function (e) { e.preventDefault(); box.classList.remove('is-dragover'); });
       });
       box.addEventListener('drop', function (e) {
           if (e.dataTransfer.files && e.dataTransfer.files[0]) {
               input.files = e.dataTransfer.files;
               mostrarFicheiro(e.dataTransfer.files[0]);
           }
       });
   })();
   </script>

    <!-- PAINEL DIREITO: Validação do relatório importado -->
    <div class="panel" style="height:100%;">
        <h4 style="margin-bottom:16px;">
            <i class="bi bi-clipboard-check" style="margin-right:6px; color:#c9a14a;"></i>
            <?= $det ? 'Validação do Relatório' : 'Relatórios' ?>
            <?php if ($det): ?>
                <?php [$bg, $fg] = $corEstado($det['estado']); ?>
                <span style="font-size:12px; font-weight:600; margin-left:10px; padding:2px 10px; border-radius:20px; background:<?= $bg ?>; color:<?= $fg ?>;">
                    <?= e($det['estado']) ?>
                </span>
            <?php endif; ?>
        </h4>

        <?php if ($det): ?>
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; padding:10px 14px; margin-bottom:16px; font-size:13px; color:#15803d;">
                <strong><?= e($det['pat_numero'] ?: '(sem PAT)') ?></strong><?= $det['cliente_detect'] ? ' — ' . e($det['cliente_detect']) : '' ?>
            </div>

            <ul style="margin:0 0 16px; padding-left:18px; font-size:13px; color:#374151;">
                <li>PAT → estado "Resolvido", resolução preenchida
                    <?php if (!$det['pat_id']): ?><strong style="color:#c2410c;">(PAT não encontrado — revisão manual)</strong><?php endif; ?></li>
                <li><?= count(array_filter($linhas, fn($l)=>$l['acao']==='modificar')) ?> peça(s) a modificar ·
                    <?= count(array_filter($linhas, fn($l)=>$l['acao']==='rever')) ?> a rever</li>
            </ul>

            <?php if ($det['estado'] === 'por_confirmar' || $det['estado'] === 'revisao_manual'): ?>
                <form method="post" action="app.php?page=relatorios">
                    <input type="hidden" name="action" value="relatorio_decidir">
                    <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="relatorio_id" value="<?= (int)$det['id'] ?>">

                    <?php
                    // ── Q4: sugestões de SN a partir do inventário ──────────────
                    // Para relatórios Field Service (manuscritos) os SNs não são
                    // fiáveis. Sugerimos peças que JÁ estão no inventário em
                    // estado "Parceiro" com parceiro "Field NewVision", agrupadas
                    // por Tipo (categoria), para o revisor confirmar/copiar.
                    if (($det['fonte'] ?? '') === 'field_service'):
                        $stSug = $pdo->prepare(
                            "SELECT categoria, produto, sn
                               FROM pecas
                              WHERE estado = 'Parceiro' AND parceiro = 'Field NewVision'
                                AND sn IS NOT NULL AND sn <> ''
                              ORDER BY categoria ASC, produto ASC, sn ASC"
                        );
                        $stSug->execute();
                        $sugestoes = $stSug->fetchAll();
                        $sugPorTipo = [];
                        foreach ($sugestoes as $sg) {
                            $sugPorTipo[$sg['categoria'] !== '' ? $sg['categoria'] : 'Sem tipo'][] = $sg;
                        }
                    ?>
                        <div style="border:1px dashed #c9a14a; background:#fffdf5; border-radius:8px; padding:12px 14px; margin:0 0 14px;">
                            <div style="font-weight:600; color:#92400e; margin-bottom:8px;">
                                <i class="bi bi-lightbulb"></i> SN sugerido — confirmar
                                <span style="font-weight:400; color:#6b7280; font-size:12px;">(peças Field NewVision em estado &laquo;Parceiro&raquo;)</span>
                            </div>
                            <?php if (!$sugestoes): ?>
                                <div style="color:#6b7280; font-size:13px;">Não há peças Field NewVision em &laquo;Parceiro&raquo; de momento.</div>
                            <?php else: foreach ($sugPorTipo as $tipoSug => $itensSug): ?>
                                <div style="margin-bottom:8px;">
                                    <div style="font-size:12px; font-weight:600; color:#374151;"><?= e($tipoSug) ?></div>
                                    <div style="display:flex; flex-wrap:wrap; gap:6px; margin-top:4px;">
                                        <?php foreach ($itensSug as $it): ?>
                                            <button type="button" class="btn btn-grey" style="padding:3px 8px; font-size:12px;"
                                                    title="Copiar SN — <?= e($it['produto']) ?>"
                                                    onclick="if(navigator.clipboard){navigator.clipboard.writeText('<?= e($it['sn']) ?>');} this.innerHTML='<i class=\'bi bi-check2\'></i> <?= e($it['sn']) ?>';">
                                                <?= e($it['produto']) ?> · <?= e($it['sn']) ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($linhas as $l): if ($l['acao'] !== 'rever') continue; ?>
                        <div style="border:1px solid #e5e7eb; border-radius:6px; padding:10px 12px; margin:0 0 10px;">
                            <div style="margin-bottom:8px;">SN <strong><?= e($l['sn']) ?></strong> → <?= e($l['estado_destino']) ?></div>
                            <select name="decisoes[<?= (int)$l['id'] ?>][acao]" style="margin-bottom:8px;">
                                <option value="ignorar">Ignorar</option>
                                <option value="criar">Criar peça nova</option>
                                <option value="modificar">Modificar existente</option>
                            </select>
                            <?php if ($l['estado_destino'] === 'Cliente'): ?>
                                <input type="text" name="decisoes[<?= (int)$l['id'] ?>][cliente]"
                                       placeholder="Cliente onde foi instalada">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <div style="display:flex; gap:10px;">
                        <button class="btn btn-green" name="decisao" value="aprovar">✓ Aprovar</button>
                        <button class="btn btn-red" name="decisao" value="rejeitar">Rejeitar</button>
                    </div>
                </form>
            <?php else: ?>
                <p style="font-size:13px; color:#6b7280;">Estado: <strong><?= e($det['estado']) ?></strong>
                    <?= $det['aprovado_por'] ? ' (' . e($det['aprovado_por']) . ')' : '' ?></p>
            <?php endif; ?>
        <?php else: ?>
            <div style="text-align:center; padding:34px 20px; color:#6b7280;">
                <div style="width:56px; height:56px; margin:0 auto 14px; border-radius:50%; background:#fbf1da; display:flex; align-items:center; justify-content:center;">
                    <i class="bi bi-file-earmark-text" style="font-size:24px; color:#c9a14a;"></i>
                </div>
                <p style="font-size:15px; font-weight:600; color:#374151; margin-bottom:6px;">Nenhum relatório selecionado</p>
                <p style="font-size:13px; margin:0;">Importa um relatório ou escolhe um na lista abaixo.</p>
            </div>
        <?php endif; ?>
    </div>

</div><!-- fim grid superior -->
<?php endif; ?>


<!-- ══ PARCEIROS (fonte dos relatórios) — no topo, com Importar em menu ══ -->
<div class="panel" style="margin-bottom:20px;">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
        <h4 style="margin:0;"><i class="bi bi-people" style="margin-right:6px; color:#c9a14a;"></i>Parceiros</h4>
        <details class="actions-dd">
            <summary class="btn btn-teal"><i class="bi bi-cloud-arrow-up"></i> Importar Relatório <i class="bi bi-chevron-down"></i></summary>
            <div class="actions-dd-menu" style="min-width:300px; padding:14px; right:0;">
                <form method="post" enctype="multipart/form-data" action="app.php?page=relatorios">
                    <input type="hidden" name="action" value="relatorio_upload">
                    <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
                    <label style="font-size:12px; color:#6b7280; display:block; margin-bottom:6px;">Ficheiro PDF ou EML</label>
                    <input type="file" name="relatorio" accept=".pdf,.eml" required style="display:block; width:100%; margin-bottom:10px; font-size:13px;">
                    <button type="submit" class="btn btn-blue" style="width:100%; justify-content:center;"><i class="bi bi-upload"></i> Importar</button>
                </form>
            </div>
        </details>
    </div>
    <div class="relatorios-fonte-grid" id="relatoriosFonteGrid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px;">
        <a href="app.php?page=relatorios" class="relatorios-fonte-card" data-fonte="" style="text-decoration:none; color:inherit;">
            <div style="border:2px solid <?= $fonteAtiva === '' ? '#c9a14a' : '#e5e7eb' ?>; border-radius:10px; padding:16px; text-align:center; transition:border-color .15s ease;">
                <i class="bi bi-grid" style="font-size:26px; color:#c9a14a;"></i>
                <div style="font-size:24px; font-weight:700; margin:8px 0 2px;"><?= $totalRelatorios ?></div>
                <div style="font-size:14px; color:#374151;">Todos</div>
            </div>
        </a>
        <?php foreach ($fontesInfo as $fonteChave => $info): ?>
            <a href="app.php?page=relatorios&fonte=<?= urlencode($fonteChave) ?>" class="relatorios-fonte-card" data-fonte="<?= htmlspecialchars($fonteChave) ?>" style="text-decoration:none; color:inherit;">
                <div style="border:2px solid <?= $fonteAtiva === $fonteChave ? '#c9a14a' : '#e5e7eb' ?>; border-radius:10px; padding:16px; text-align:center; transition:border-color .15s ease;">
                    <i class="bi <?= $info['icone'] ?>" style="font-size:26px; color:#c9a14a;"></i>
                    <div style="font-size:24px; font-weight:700; margin:8px 0 2px;"><?= $contagemFontes[$fonteChave] ?></div>
                    <div style="font-size:14px; color:#374151;"><?= e($info['nome']) ?></div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</div>


<?php
// Opção F — meses disponíveis (para agrupar a lista e alimentar o filtro inline)
$mesesPt = [1=>'Janeiro',2=>'Fevereiro',3=>'Março',4=>'Abril',5=>'Maio',6=>'Junho',7=>'Julho',8=>'Agosto',9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro'];
$relMesesDisponiveis = [];
foreach ($lista as $rTmp) {
    $tsTmp = strtotime((string)$rTmp['criado_em']);
    if ($tsTmp) { $relMesesDisponiveis[date('Y-m',$tsTmp)] = $mesesPt[(int)date('n',$tsTmp)].' '.date('Y',$tsTmp); }
}
?>
<!-- ══ LINHA INFERIOR: Lista de Relatórios (largura total) ══ -->
<div class="panel" id="painelListaRelatorios">
    <div class="panel-header-row">
        <div class="panel-header-left">
            <h4 style="margin:0;">
                <i class="bi bi-list-ul" style="margin-right:6px; color:#c9a14a;"></i>Lista de Relatórios
                <?php if ($fonteAtiva !== '' && isset($fontesInfo[$fonteAtiva])): ?>
                    <span style="font-size:13px; font-weight:500; color:#6b7280; margin-left:8px;">— <?= e($fontesInfo[$fonteAtiva]['nome']) ?></span>
                <?php endif; ?>
            </h4>
            <span class="panel-count-badge"><?= count($lista) ?></span>
        </div>
        <div class="panel-header-actions" style="gap:10px;">
            <select class="rel-mes-filtro" onchange="nvRelFiltrarMes(this)" aria-label="Filtrar por mês">
                <option value="">Todos os meses</option>
                <?php foreach ($relMesesDisponiveis as $k => $lbl): ?>
                    <option value="<?= $k ?>"><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table envios-table" id="tabelaRelatorios">
            <thead>
            <tr>
                <th>Ficheiro</th>
                <th>Fonte</th>
                <th>PAT</th>
                <th>Cliente</th>
                <th>Estado</th>
                <th>Data</th>
                <th class="actions">Ações</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($lista)): ?>
                <tr id="tabelaRelatoriosVazia" data-no-filter><td colspan="7" class="envios-vazio">Nenhum relatório registado.</td></tr>
            <?php else: ?>
                <?php $mesAtual = null; foreach ($lista as $r): ?>
                    <?php
                        [$bg, $fg] = $corEstado($r['estado']);
                        $tsR    = strtotime((string)$r['criado_em']);
                        $mesKey = $tsR ? date('Y-m', $tsR) : '0000-00';
                        $mesLbl = $tsR ? $mesesPt[(int)date('n',$tsR)].' '.date('Y',$tsR) : 'Sem data';
                        if ($mesKey !== $mesAtual): $mesAtual = $mesKey;
                    ?>
                        <tr class="rel-month-row" data-mes="<?= $mesKey ?>"><td colspan="7"><i class="bi bi-calendar3"></i> <?= $mesLbl ?></td></tr>
                    <?php endif; ?>
                    <tr data-mes="<?= $mesKey ?>">
                        <td class="rel-file">
                            <span class="rel-file-name"><?= e($r['ficheiro_nome']) ?></span>
                            <div class="rel-preview">
                                <div><span class="k">PAT</span><span><?= e($r['pat_numero'] ?: '—') ?></span></div>
                                <div><span class="k">Cliente</span><span><?= e($r['cliente_detect'] ?: '—') ?></span></div>
                                <div><span class="k">Fonte</span><span><?= e($fontesInfo[$r['fonte']]['nome'] ?? $r['fonte']) ?></span></div>
                                <div><span class="k">Estado</span><span><?= e($r['estado']) ?></span></div>
                                <div><span class="k">Data</span><span><?= e($r['criado_em']) ?></span></div>
                            </div>
                        </td>
                        <td><?= e($fontesInfo[$r['fonte']]['nome'] ?? $r['fonte']) ?></td>
                        <td><?= e($r['pat_numero']) ?></td>
                        <td><?= e($r['cliente_detect']) ?></td>
                        <td>
                            <span style="display:inline-block; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600; background:<?= $bg ?>; color:<?= $fg ?>; white-space:nowrap;">
                                <?= e($r['estado']) ?>
                            </span>
                        </td>
                        <td style="white-space:nowrap;"><?= e($r['criado_em']) ?></td>
                        <td class="actions"><a class="btn btn-yellow" href="<?= $linkBase . ($fonteAtiva !== '' ? '&' : '?') ?>ver=<?= (int)$r['id'] ?>">Ver</a></td>
                    </tr>
                <?php endforeach; ?>
                <tr id="tabelaRelatoriosVazia" data-no-filter style="display:none;"><td colspan="7" class="envios-vazio">Sem resultados para esta pesquisa.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    const grid = document.getElementById('relatoriosFonteGrid');
    if (!grid) return;

    window.addEventListener('popstate', function () {
        // Navegação por back/forward do browser: recarrega para garantir conteúdo correto.
        window.location.reload();
    });

    grid.querySelectorAll('.relatorios-fonte-card').forEach(function (card) {
        card.addEventListener('click', function (e) {
            e.preventDefault();
            const href = card.getAttribute('href');

            // Estado visual "ativo" imediato, sem esperar pela resposta
            grid.querySelectorAll('.relatorios-fonte-card > div').forEach(function (d) {
                d.style.borderColor = '#e5e7eb';
            });
            card.querySelector('div').style.borderColor = '#c9a14a';

            fetch(href)
                .then(function (resp) { return resp.text(); })
                .then(function (html) {
                    const docNovo = new DOMParser().parseFromString(html, 'text/html');
                    const painelNovo = docNovo.getElementById('painelListaRelatorios');
                    const painelAtual = document.getElementById('painelListaRelatorios');
                    if (painelNovo && painelAtual) {
                        painelAtual.replaceWith(painelNovo);
                        // Religar a pesquisa rápida e o estado vazio no novo painel
                        const input = painelNovo.querySelector('.quick-search-input');
                        if (input && typeof nvFiltrarTabela === 'function') {
                            input.addEventListener('input', function () { nvFiltrarTabela(input); });
                        }
                    }
                    history.pushState(null, '', href);
                })
                .catch(function () {
                    // Em caso de falha de rede, recorrer à navegação normal
                    window.location.href = href;
                });
        });
    });
})();
</script>

<style>
.rel-mes-filtro{ height:40px; border:1px solid #e5e9ef; background:#f8fafc; border-radius:9px; font-size:13.5px; padding:0 10px; color:#374151; }
.rel-month-row td{ background:#eef1f5 !important; font-weight:700; font-size:11.5px; text-transform:uppercase; letter-spacing:.04em; color:#4b5563; padding:7px 12px !important; }
.rel-month-row .bi{ margin-right:6px; color:#c9a14a; }
.rel-file{ position:relative; }
.rel-file-name{ font-weight:600; }
.rel-preview{ display:none; position:absolute; left:0; top:calc(100% + 4px); z-index:70; background:#fff; border:1px solid #e5e9ef; border-radius:10px; box-shadow:0 10px 28px rgba(16,24,40,.16); padding:12px 14px; min-width:250px; }
.rel-file:hover .rel-preview{ display:block; }
.rel-preview > div{ display:flex; justify-content:space-between; gap:18px; font-size:12.5px; padding:3px 0; }
.rel-preview .k{ color:#9ca3af; text-transform:uppercase; font-size:10.5px; font-weight:700; letter-spacing:.04em; }
</style>
<script>
window.nvRelFiltrarMes = function(sel){
  var mes = sel.value;
  var panel = sel.closest('.panel'); if(!panel) return;
  var tbody = panel.querySelector('tbody'); if(!tbody) return;
  tbody.querySelectorAll('tr[data-mes]').forEach(function(tr){
    tr.style.display = (!mes || tr.getAttribute('data-mes') === mes) ? '' : 'none';
  });
};
</script>
