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

    $verId = (int)($_GET['ver'] ?? 0);

    // Lista
    $lista = $pdo->query("SELECT id, ficheiro_nome, fonte, pat_numero, cliente_detect, estado, criado_em
                            FROM relatorios ORDER BY criado_em DESC LIMIT 200")->fetchAll();

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
    ?>

    <?php if (!empty($_SESSION['mensagem_erro'])): ?>
      <div class="alerta-erro" style="margin-bottom:16px;"><?= htmlspecialchars($_SESSION['mensagem_erro']) ?></div>
      <?php unset($_SESSION['mensagem_erro']); endif; ?>
    <?php if (!empty($_SESSION['mensagem_sucesso'])): ?>
      <div class="alerta-sucesso" style="margin-bottom:16px;"><?= htmlspecialchars($_SESSION['mensagem_sucesso']) ?></div>
      <?php unset($_SESSION['mensagem_sucesso']); endif; ?>

    <!-- Upload manual -->
    <div class="card" style="margin-bottom:18px">
        <form method="post" enctype="multipart/form-data" action="app.php?page=relatorios">
            <input type="hidden" name="action" value="relatorio_upload">
            <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
            <input type="file" name="relatorio" accept=".pdf,.eml" required>
            <button class="btn btn-teal" type="submit">Importar relatório</button>
        </form>
    </div>

    <?php if ($det): ?>
        <!-- NOTIFICAÇÃO DE APROVAÇÃO (curta, só o essencial) -->
        <div class="card" style="margin-bottom:18px">
            <h3>Relatório <?= e($det['pat_numero'] ?: '(sem PAT)') ?>
                <?= $det['cliente_detect'] ? '— ' . e($det['cliente_detect']) : '' ?></h3>
            <ul>
                <li>PAT → estado "Resolvido", resolução preenchida
                    <?= $det['pat_id'] ? '' : '<strong>(PAT não encontrado — revisão manual)</strong>' ?></li>
                <li><?= count(array_filter($linhas, fn($l)=>$l['acao']==='modificar')) ?> peça(s) a modificar ·
                    <?= count(array_filter($linhas, fn($l)=>$l['acao']==='rever')) ?> a rever</li>
            </ul>

            <?php if ($det['estado'] === 'por_confirmar' || $det['estado'] === 'revisao_manual'): ?>
                <form method="post" action="app.php?page=relatorios">
                    <input type="hidden" name="action" value="relatorio_decidir">
                    <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="relatorio_id" value="<?= (int)$det['id'] ?>">

                    <!-- Linhas que precisam de decisão (SN inexistente / conflito) -->
                    <?php foreach ($linhas as $l): if ($l['acao'] !== 'rever') continue; ?>
                        <div style="border:1px solid #ddd;padding:8px;margin:6px 0">
                            <div>SN <strong><?= e($l['sn']) ?></strong> → <?= e($l['estado_destino']) ?></div>
                            <select name="decisoes[<?= (int)$l['id'] ?>][acao]">
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

                    <button class="btn btn-green" name="decisao" value="aprovar">Aprovar</button>
                    <button class="btn" name="decisao" value="rejeitar">Rejeitar</button>
                </form>
            <?php else: ?>
                <p>Estado: <strong><?= e($det['estado']) ?></strong>
                    <?= $det['aprovado_por'] ? '(' . e($det['aprovado_por']) . ')' : '' ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Lista de relatórios -->
    <table class="tabela">
        <thead><tr><th>Ficheiro</th><th>Fonte</th><th>PAT</th><th>Cliente</th><th>Estado</th><th>Data</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($lista as $r): ?>
            <tr>
                <td><?= e($r['ficheiro_nome']) ?></td>
                <td><?= e($r['fonte']) ?></td>
                <td><?= e($r['pat_numero']) ?></td>
                <td><?= e($r['cliente_detect']) ?></td>
                <td><?= e($r['estado']) ?></td>
                <td><?= e($r['criado_em']) ?></td>
                <td><a href="app.php?page=relatorios&ver=<?= (int)$r['id'] ?>">Ver</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

