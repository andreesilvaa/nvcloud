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
    if (!is_dir($dir)) mkdir($dir, 0777, true);

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
        flashError('Erro a processar relatório: ' . $e->getMessage());
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

<!-- == Linha Superior: Importar Relatório (Esquerda) + Validação (Direita) == -->
<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; align-items:start; margin-bottom:20px;">
   <div class="panel" style="height:100%;">
      <h4 style="margin-bottom:16px;"><i class="bi bi-file-earmark-pdf" style="margin-right:6px; color:#c9a14a;"></i>Importar Relatório</h4>
      <form method="post" enctype="multipart/form-data" action="app.php?page=relatorios">
          <input type="hidden" name="action" value="relatorio_upload">
          <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
          <div style="margin-bottom:14px;">
              <label style="margin-bottom:5px; display:block;">Ficheiro (PDF ou EML)</label>
              <input type="file" name="relatorio" accept=".pdf,.eml" required style="width:100%;">
          </div>
          <button type="submit" class="btn btn-teal" style="width:100%;">Importar relatório</button>
      </form>
   </div>

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
            <div style="text-align:center; padding:40px 20px; color:#6b7280;">
                <div style="font-size:40px; margin-bottom:12px;">📄</div>
                <p style="font-size:15px; font-weight:500; margin-bottom:6px;">Nenhum relatório selecionado</p>
                <p style="font-size:13px;">Importa um relatório ou escolhe um na lista abaixo.</p>
            </div>
        <?php endif; ?>
    </div>

</div><!-- fim grid superior -->


<!-- ══ PARCEIROS (fonte dos relatórios) ══ -->
<div class="panel" style="margin-bottom:20px;">
    <h4 style="margin-bottom:16px;"><i class="bi bi-people" style="margin-right:6px; color:#c9a14a;"></i>Parceiros</h4>
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px;">
        <a href="app.php?page=relatorios" style="text-decoration:none; color:inherit;">
            <div style="border:2px solid <?= $fonteAtiva === '' ? '#c9a14a' : '#e5e7eb' ?>; border-radius:10px; padding:16px; text-align:center; transition:border-color .15s ease;">
                <i class="bi bi-grid" style="font-size:26px; color:#c9a14a;"></i>
                <div style="font-size:24px; font-weight:700; margin:8px 0 2px;"><?= $totalRelatorios ?></div>
                <div style="font-size:14px; color:#374151;">Todos</div>
            </div>
        </a>
        <?php foreach ($fontesInfo as $fonteChave => $info): ?>
            <a href="app.php?page=relatorios&fonte=<?= urlencode($fonteChave) ?>" style="text-decoration:none; color:inherit;">
                <div style="border:2px solid <?= $fonteAtiva === $fonteChave ? '#c9a14a' : '#e5e7eb' ?>; border-radius:10px; padding:16px; text-align:center; transition:border-color .15s ease;">
                    <i class="bi <?= $info['icone'] ?>" style="font-size:26px; color:#c9a14a;"></i>
                    <div style="font-size:24px; font-weight:700; margin:8px 0 2px;"><?= $contagemFontes[$fonteChave] ?></div>
                    <div style="font-size:14px; color:#374151;"><?= e($info['nome']) ?></div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</div>


<!-- ══ LINHA INFERIOR: Lista de Relatórios (largura total) ══ -->
<div class="panel">
    <h4 style="margin-bottom:18px;">
        <i class="bi bi-list-ul" style="margin-right:6px; color:#c9a14a;"></i>Lista de Relatórios
        <?php if ($fonteAtiva !== '' && isset($fontesInfo[$fonteAtiva])): ?>
            <span style="font-size:13px; font-weight:500; color:#6b7280; margin-left:8px;">— <?= e($fontesInfo[$fonteAtiva]['nome']) ?></span>
        <?php endif; ?>
    </h4>
    <div style="overflow-x:auto;">
        <table class="table envios-table">
            <thead>
            <tr>
                <th>Ficheiro</th>
                <th>Fonte</th>
                <th>PAT</th>
                <th>Cliente</th>
                <th>Estado</th>
                <th>Data</th>
                <th>Ações</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($lista)): ?>
                <tr><td colspan="7" class="envios-vazio">Nenhum relatório registado.</td></tr>
            <?php else: ?>
                <?php foreach ($lista as $r): ?>
                    <?php [$bg, $fg] = $corEstado($r['estado']); ?>
                    <tr>
                        <td><?= e($r['ficheiro_nome']) ?></td>
                        <td><?= e($fontesInfo[$r['fonte']]['nome'] ?? $r['fonte']) ?></td>
                        <td><?= e($r['pat_numero']) ?></td>
                        <td><?= e($r['cliente_detect']) ?></td>
                        <td>
                            <span style="display:inline-block; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600; background:<?= $bg ?>; color:<?= $fg ?>;">
                                <?= e($r['estado']) ?>
                            </span>
                        </td>
                        <td><?= e($r['criado_em']) ?></td>
                        <td><a class="btn btn-yellow" href="<?= $linkBase . ($fonteAtiva !== '' ? '&' : '?') ?>ver=<?= (int)$r['id'] ?>">Ver</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
