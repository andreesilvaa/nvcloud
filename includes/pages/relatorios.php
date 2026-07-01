<?php
// HANDLER: Relatórios / Upload Manual
// ══════════════════════════════════════════════
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["action"] ?? "") === "relatorio_upload"
) {
    if (($_POST["csrf"] ?? "") !== ($_SESSION["csrf_token"] ?? "")) {
        flashError("Ação inválida.");
        redirectTo("app.php?page=relatorios");
    }
    if (
        ($_FILES["relatorio"]["error"] ?? UPLOAD_ERR_NO_FILE) !==
        UPLOAD_ERR_OK
    ) {
        flashError("Nenhum ficheiro válido enviado.");
        redirectTo("app.php?page=relatorios");
    }
    $f = $_FILES["relatorio"];
    $ext = strtolower(pathinfo($f["name"], PATHINFO_EXTENSION));
    if (!in_array($ext, ["pdf", "eml"], true)) {
        flashError("O ficheiro tem de ser PDF ou EML.");
        redirectTo("app.php?page=relatorios");
    }

    $dir = dirname(__DIR__, 2) . "/uploads/relatorios/";
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $hash = hash_file("sha256", $f["tmp_name"]);
    // anti-duplicado (mesmo ficheiro)
    $dup = $pdo->prepare(
        "SELECT id, estado FROM relatorios WHERE hash_unico = ? LIMIT 1",
    );
    $dup->execute([$hash]);
    if ($jaId = $dup->fetchColumn()) {
        flashError("Este relatório já foi importado (#" . (int) $jaId . ").");
        redirectTo("app.php?page=relatorios&ver=" . (int) $jaId);
    }

    $destino = $dir . $hash . "." . $ext;
    move_uploaded_file($f["tmp_name"], $destino);

    try {
        $parse = nvParseRelatorio($destino, $f["name"]);
        $res = nvReconciliarRelatorio($pdo, $parse, [
            "origem" => "manual",
            "ficheiro_nome" => $f["name"],
            "ficheiro_path" => "uploads/relatorios/" . basename($destino),
            "hash_unico" => $hash,
        ]);
        flashSuccess("Relatório importado. Reveja e aprove.");
        redirectTo("app.php?page=relatorios&ver=" . $res["relatorio_id"]);
    } catch (Throwable $e) {
        error_log("[nvcloud] Erro a processar relatório: " . $e->getMessage());
        \Sentry\captureException($e);
        flashError(
            "Não foi possível processar o relatório. Verifica o ficheiro e tenta novamente.",
        );
        redirectTo("app.php?page=relatorios");
    }
}

// ── RELATÓRIOS: aprovar / rejeitar ───────────────────────────
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["action"] ?? "") === "relatorio_decidir"
) {
    if (($_POST["csrf"] ?? "") !== ($_SESSION["csrf_token"] ?? "")) {
        flashError("Ação inválida.");
        redirectTo("app.php?page=relatorios");
    }
    $relId = (int) ($_POST["relatorio_id"] ?? 0);
    $dec = $_POST["decisao"] ?? "";
    $user = $_SESSION["user_nome"] ?? "Sistema";

    if ($dec === "aprovar") {
        // decisões por linha (do ecrã rever): decisoes[ID][acao], decisoes[ID][cliente]
        $decisoes = $_POST["decisoes"] ?? [];
        $r = nvAplicarRelatorio($pdo, $relId, $decisoes, $user);
        $r["ok"] ? flashSuccess($r["msg"]) : flashError($r["msg"]);
    } elseif ($dec === "rejeitar") {
        $pdo->prepare(
            "UPDATE relatorios SET estado='rejeitado' WHERE id=?",
        )->execute([$relId]);
        flashSuccess("Relatório rejeitado.");
    }
    redirectTo("app.php?page=relatorios&ver=" . $relId);
}

// ── RELATÓRIOS: apagar definitivamente ───────────────────────
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["action"] ?? "") === "relatorio_apagar"
) {
    if (($_POST["csrf"] ?? "") !== ($_SESSION["csrf_token"] ?? "")) {
        flashError("Ação inválida.");
        redirectTo("app.php?page=relatorios");
    }
    $relId = (int) ($_POST["relatorio_id"] ?? 0);

    $stRel = $pdo->prepare("SELECT * FROM relatorios WHERE id = ? LIMIT 1");
    $stRel->execute([$relId]);
    $rel = $stRel->fetch();

    if (!$rel) {
        flashError("Relatório não encontrado.");
        redirectTo("app.php?page=relatorios");
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM relatorios_pecas WHERE relatorio_id = ?")->execute([$relId]);
        $pdo->prepare("DELETE FROM relatorios_log WHERE relatorio_id = ?")->execute([$relId]);
        $pdo->prepare("DELETE FROM relatorios WHERE id = ?")->execute([$relId]);
        $pdo->commit();

        // Apagar o PDF só depois de confirmar que a BD foi limpa com sucesso.
        $ficheiroPath = $rel["ficheiro_path"] ?? "";
        if ($ficheiroPath !== "") {
            $fsPath = dirname(__DIR__, 2) . "/" . ltrim($ficheiroPath, "/");
            if (is_file($fsPath)) {
                @unlink($fsPath);
            }
        }

        flashSuccess("Relatório eliminado definitivamente.");
        redirectTo("app.php?page=relatorios");
    } catch (Throwable $e) {
        $pdo->rollBack();
        flashError("Não foi possível eliminar o relatório: " . $e->getMessage());
        redirectTo("app.php?page=relatorios&ver=" . $relId);
    }
}

// ── RELATÓRIOS: criar o PAT em falta diretamente a partir do relatório ──
// Usado quando o número de PAT foi detetado no documento mas não existe
// (ainda) na tabela `pats` — normalmente porque a Work Order nunca foi
// importada via extensão Salesforce. Só corre com confirmação explícita
// do utilizador (botão dedicado no ecrã de validação).
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["action"] ?? "") === "relatorio_criar_pat"
) {
    if (($_POST["csrf"] ?? "") !== ($_SESSION["csrf_token"] ?? "")) {
        flashError("Ação inválida.");
        redirectTo("app.php?page=relatorios");
    }
    $relId = (int) ($_POST["relatorio_id"] ?? 0);
    $user = $_SESSION["user_nome"] ?? "Sistema";

    $stRel = $pdo->prepare("SELECT * FROM relatorios WHERE id = ? LIMIT 1");
    $stRel->execute([$relId]);
    $rel = $stRel->fetch();

    if (!$rel) {
        flashError("Relatório não encontrado.");
        redirectTo("app.php?page=relatorios");
    }
    if (!empty($rel["pat_id"])) {
        flashError("Este relatório já tem um PAT associado.");
        redirectTo("app.php?page=relatorios&ver=" . $relId);
    }
    if (empty($rel["pat_numero"]) || !preg_match('/PAT-(\d+)\/(\d+)/', $rel["pat_numero"], $mPat)) {
        flashError("Não foi possível ler um número de PAT válido neste relatório.");
        redirectTo("app.php?page=relatorios&ver=" . $relId);
    }

    // Salvaguarda: se entretanto já existe um PAT com este número (ex.:
    // criado por outra via depois deste relatório ter sido importado),
    // associamos ao existente em vez de criar um duplicado.
    $stDup = $pdo->prepare("SELECT id FROM pats WHERE numero_pat = ? LIMIT 1");
    $stDup->execute([$rel["pat_numero"]]);
    $patIdExistente = $stDup->fetchColumn();

    if ($patIdExistente) {
        $pdo->prepare("UPDATE relatorios SET pat_id = ? WHERE id = ?")
            ->execute([(int) $patIdExistente, $relId]);
        flashSuccess("Este PAT já existia — associado ao relatório.");
        redirectTo("app.php?page=relatorios&ver=" . $relId);
    }

    $clienteRel = trim((string) ($rel["cliente_detect"] ?? ""));
    $pdo->prepare("
        INSERT INTO pats
          (numero_pat, revisao, entidade, local_cliente, estado, criado_por, created_at)
        VALUES (?, ?, ?, ?, 'Aberto', ?, NOW())
    ")->execute([
        $rel["pat_numero"],
        (int) $mPat[2],
        $clienteRel !== "" ? $clienteRel : "(criado via relatório)",
        $clienteRel,
        $user,
    ]);
    $novoPatId = (int) $pdo->lastInsertId();

    $pdo->prepare("UPDATE relatorios SET pat_id = ? WHERE id = ?")
        ->execute([$novoPatId, $relId]);

    flashSuccess(
        "PAT " . $rel["pat_numero"] . " criado e associado a este relatório. Podes agora aprovar.",
    );
    redirectTo("app.php?page=relatorios&ver=" . $relId);
}

// ══════════════════════════════════════════════

$verId = (int) ($_GET["ver"] ?? 0);
$fonteAtiva = $_GET["fonte"] ?? "";

// "Parceiros" = quem envia os relatórios (fonte). Contagem por fonte,
// sempre sobre o total (independente do filtro atual).
$fontesInfo = [
    "cronotecnica" => ["nome" => "Cronotécnica", "icone" => "bi-building"],
    "konica" => ["nome" => "Konica Minolta", "icone" => "bi-building"],
    "field_service" => ["nome" => "Field Service", "icone" => "bi-tools"],
    "desconhecido" => [
        "nome" => "Outros",
        "icone" => "bi-question-circle",
    ],
];
$contagemFontes = array_fill_keys(array_keys($fontesInfo), 0);
foreach (
    $pdo->query("SELECT fonte, COUNT(*) AS n FROM relatorios GROUP BY fonte")
    as $row
) {
    $contagemFontes[$row["fonte"]] = (int) $row["n"];
}
$totalRelatorios = array_sum($contagemFontes);

// Lista (filtrada pelo parceiro/fonte selecionado, se houver)
if ($fonteAtiva !== "" && isset($fontesInfo[$fonteAtiva])) {
    $stLista = $pdo->prepare("SELECT id, ficheiro_nome, ficheiro_path, fonte, pat_numero, cliente_detect, estado, criado_em
                                    FROM relatorios WHERE fonte = ? ORDER BY criado_em DESC LIMIT 200");
    $stLista->execute([$fonteAtiva]);
    $lista = $stLista->fetchAll();
} else {
    $lista = $pdo
        ->query(
            "SELECT id, ficheiro_nome, ficheiro_path, fonte, pat_numero, cliente_detect, estado, criado_em
                                FROM relatorios ORDER BY criado_em DESC LIMIT 200",
        )
        ->fetchAll();
}

// Detalhe (se ?ver=)
$det = null;
$linhas = [];
if ($verId) {
    $s = $pdo->prepare("SELECT * FROM relatorios WHERE id=?");
    $s->execute([$verId]);
    $det = $s->fetch();
    if ($det) {
        $sl = $pdo->prepare(
            "SELECT * FROM relatorios_pecas WHERE relatorio_id=?",
        );
        $sl->execute([$verId]);
        $linhas = $sl->fetchAll();
    }
}

// Cores do estado (mesma lógica de badges usada na página Envios)
$corEstado = static function (string $estado): array {
    return match ($estado) {
        "por_confirmar" => ["#fef3c7", "#92400e"],
        "revisao_manual" => ["#ffedd5", "#c2410c"],
        "aprovado" => ["#dcfce7", "#15803d"],
        "rejeitado" => ["#fee2e2", "#b91c1c"],
        "duplicado" => ["#f3f4f6", "#374151"],
        default => ["#f3f4f6", "#374151"],
    };
};
$linkBase =
    "app.php?page=relatorios" .
    ($fonteAtiva !== "" ? "&fonte=" . urlencode($fonteAtiva) : "");
$docWeb = "";
$docFs = "";
if ($det) {
    $docWeb = $det["ficheiro_path"] ?? "";
    $docFs = $docWeb ? dirname(__DIR__, 2) . "/" . ltrim($docWeb, "/") : "";
    if ($docWeb && !is_file($docFs)) {
        $altFs = __DIR__ . "/" . ltrim($docWeb, "/");
        if (is_file($altFs)) {
            $docFs = $altFs;
        }
    }
}
?>

<?php if ($det): ?>
<div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px; flex-wrap:wrap;">
    <a class="btn btn-grey" href="<?= e(
        $linkBase,
    ) ?>" onclick="nvVoltar(event)"><i class="bi bi-arrow-left"></i> Voltar à lista</a>
    <span style="font-size:13px; color:#6b7280;"><?= e(
        $det["ficheiro_nome"] ?? "",
    ) ?></span>
</div>
<!-- Detalhe: documento original (esquerda) + validação (direita) -->
<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; align-items:start; margin-bottom:20px;">
   <div class="panel" style="height:100%;">
      <h4 style="margin-bottom:16px;"><i class="bi bi-file-earmark-pdf" style="margin-right:6px; color:#c9a14a;"></i>Documento Original</h4>
      <?php if ($docWeb && is_file($docFs)): ?>
          <iframe src="<?= e(
              $docWeb,
          ) ?>#toolbar=0&navpanes=0&scrollbar=0" title="Documento original" style="width:100%; height:620px; border:1px solid #e5e9ef; border-radius:10px; background:#f8fafc;"></iframe>
          <div style="margin-top:10px;">
              <a class="btn btn-grey" href="<?= e(
                  $docWeb,
              ) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Abrir em nova janela</a>
          </div>
      <?php else: ?>
          <div style="text-align:center; padding:34px 20px; color:#6b7280; background:#f8fafc; border:1px dashed #e5e9ef; border-radius:10px;">
              <i class="bi bi-file-earmark-x" style="font-size:28px; display:block; margin-bottom:10px; color:#cbd5e1;"></i>
              Documento original não disponível para este relatório.
          </div>
      <?php endif; ?>
   </div>
    <div class="panel" style="height:100%;">
        <h4 style="margin-bottom:16px;">
            <i class="bi bi-clipboard-check" style="margin-right:6px; color:#c9a14a;"></i>
            <?= $det ? "Validação do Relatório" : "Relatórios" ?>
        </h4>

        <?php if ($det): ?>
            <?php [$bgEstado, $fgEstado] = $corEstado($det["estado"]); ?>
            <div class="rel-info-grid">
                <div class="rel-info-item">
                    <span class="rel-info-label">Estado</span>
                    <span class="rel-info-val"><span style="display:inline-block; padding:2px 10px; border-radius:20px; font-size:12px; font-weight:600; background:<?= $bgEstado ?>; color:<?= $fgEstado ?>;"><?= e(
                        $det["estado"],
                    ) ?></span></span>
                </div>
                <div class="rel-info-item">
                    <span class="rel-info-label">PAT associado</span>
                    <span class="rel-info-val"><?= e($det["pat_numero"] ?: "—") ?></span>
                </div>
                <div class="rel-info-item">
                    <span class="rel-info-label">Cliente</span>
                    <span class="rel-info-val"><?= e($det["cliente_detect"] ?: "—") ?></span>
                </div>
                <div class="rel-info-item">
                    <span class="rel-info-label">Parceiro</span>
                    <span class="rel-info-val"><?= e(
                        $fontesInfo[$det["fonte"]]["nome"] ?? $det["fonte"],
                    ) ?></span>
                </div>
            </div>

            <?php
            $relTotalPecas = count($linhas);
            $relSemDadosUteis = !$det["pat_id"] && $relTotalPecas === 0;
            ?>
            <?php if ($relSemDadosUteis): ?>
            <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:12px 14px; margin:16px 0; font-size:13px; color:#991b1b; display:flex; gap:10px; align-items:flex-start;">
                <i class="bi bi-exclamation-triangle-fill" style="font-size:16px; margin-top:1px;"></i>
                <div>
                    <strong>Este relatório não teve nenhum PAT nem nenhuma peça identificados.</strong>
                    É muito provável que a leitura automática não tenha conseguido extrair os dados deste documento. Revê cuidadosamente o documento original antes de aprovar.
                </div>
            </div>
            <?php endif; ?>

            <?php if (!$det["pat_id"] && !empty($det["pat_numero"])): ?>
            <form method="post" action="app.php?page=relatorios" style="margin:14px 0;" onsubmit="return nvConfirmar(this, 'Criar o PAT ' + <?= json_encode(
                $det["pat_numero"],
            ) ?> + ' com base nos dados deste relatório?');">
                <input type="hidden" name="action" value="relatorio_criar_pat">
                <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="relatorio_id" value="<?= (int) $det["id"] ?>">
                <button type="submit" class="btn btn-grey" style="padding:7px 13px; font-size:12.5px;"><i class="bi bi-plus-lg"></i> Criar este PAT (não encontrado)</button>
            </form>
            <?php endif; ?>

            <?php if (
                $det["estado"] === "por_confirmar" ||
                $det["estado"] === "revisao_manual"
            ): ?>
            <div class="rel-checklist">
                <div class="rel-checklist-title">Checklist de verificação</div>
                <label class="rel-check-item">
                    <input type="checkbox" class="rel-check-box">
                    <span>Confirmar se as peças do relatório estão corretas</span>
                </label>
                <label class="rel-check-item">
                    <input type="checkbox" class="rel-check-box">
                    <span>Confirmar se os números de série estão coerentes</span>
                </label>
                <label class="rel-check-item">
                    <input type="checkbox" class="rel-check-box">
                    <span>Confirmar se os dados batem certo com a instalação</span>
                </label>
            </div>

            <details class="rel-avancado">
                <summary>Detalhes avançados — peças e SN
                    <span class="rel-avancado-pill"><?= count(
                        array_filter($linhas, fn($l) => $l["acao"] === "modificar"),
                    ) ?> a modificar · <?= count(
    array_filter($linhas, fn($l) => $l["acao"] === "rever"),
) ?> a rever</span>
                </summary>
                <div class="rel-avancado-body">
                <form method="post" action="app.php?page=relatorios" id="formRelatorioDecidir">
                    <input type="hidden" name="action" value="relatorio_decidir">
                    <input type="hidden" name="csrf" value="<?= e(
                        $csrfToken,
                    ) ?>">
                    <input type="hidden" name="relatorio_id" value="<?= (int) $det[
                        "id"
                    ] ?>">

                    <?php
                    if (($det["fonte"] ?? "") === "field_service"):

                        $stSug = $pdo->prepare(
                            "SELECT categoria, produto, sn
                               FROM pecas
                              WHERE estado = 'Parceiro' AND parceiro = 'Field NewVision'
                                AND sn IS NOT NULL AND sn <> ''
                              ORDER BY categoria ASC, produto ASC, sn ASC",
                        );
                        $stSug->execute();
                        $sugestoes = $stSug->fetchAll();
                        $sugPorTipo = [];
                        foreach ($sugestoes as $sg) {
                            $sugPorTipo[
                                $sg["categoria"] !== ""
                                    ? $sg["categoria"]
                                    : "Sem tipo"
                            ][] = $sg;
                        }
                        ?>
                        <div style="border:1px dashed #c9a14a; background:#fffdf5; border-radius:8px; padding:12px 14px; margin:0 0 14px;">
                            <div style="font-weight:600; color:#92400e; margin-bottom:8px;">
                                <i class="bi bi-lightbulb"></i> SN sugerido — confirmar
                                <span style="font-weight:400; color:#6b7280; font-size:12px;">(peças Field NewVision em estado &laquo;Parceiro&raquo;)</span>
                            </div>
                            <?php if (!$sugestoes): ?>
                                <div style="color:#6b7280; font-size:13px;">Não há peças Field NewVision em &laquo;Parceiro&raquo; de momento.</div>
                            <?php else:foreach (
                                    $sugPorTipo
                                    as $tipoSug => $itensSug
                                ): ?>
                                <div style="margin-bottom:8px;">
                                    <div style="font-size:12px; font-weight:600; color:#374151;"><?= e(
                                        $tipoSug,
                                    ) ?></div>
                                    <div style="display:flex; flex-wrap:wrap; gap:6px; margin-top:4px;">
                                        <?php foreach ($itensSug as $it): ?>
                                            <button type="button" class="btn btn-grey" style="padding:3px 8px; font-size:12px;"
                                                    title="Copiar SN — <?= e(
                                                        $it["produto"],
                                                    ) ?>"
                                                    onclick="if(navigator.clipboard){navigator.clipboard.writeText('<?= e(
                                                        $it["sn"],
                                                    ) ?>');} this.innerHTML='<i class=\'bi bi-check2\'></i> <?= e(
    $it["sn"],
) ?>';">
                                                <?= e(
                                                    $it["produto"],
                                                ) ?> · <?= e($it["sn"]) ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach;endif; ?>
                        </div>
                    <?php
                    endif; ?>

                    <?php foreach ($linhas as $l):
                        if ($l["acao"] !== "rever") {
                            continue;
                        } ?>
                        <div style="border:1px solid #e5e7eb; border-radius:6px; padding:10px 12px; margin:0 0 10px;">
                            <div style="margin-bottom:8px;">SN <strong><?= e(
                                $l["sn"],
                            ) ?></strong> → <?= e($l["estado_destino"]) ?></div>
                            <select name="decisoes[<?= (int) $l[
                                "id"
                            ] ?>][acao]" style="margin-bottom:8px;">
                                <option value="ignorar">Ignorar</option>
                                <option value="criar">Criar peça nova</option>
                                <option value="modificar">Modificar existente</option>
                            </select>
                            <?php if ($l["estado_destino"] === "Cliente"): ?>
                                <input type="text" name="decisoes[<?= (int) $l[
                                    "id"
                                ] ?>][cliente]"
                                       placeholder="Cliente onde foi instalada">
                            <?php endif; ?>
                        </div>
                    <?php
                    endforeach; ?>
                    <?php if (!$linhas): ?>
                        <p style="font-size:13px; color:#9ca3af; margin:0;">Sem linhas de peças detetadas neste relatório.</p>
                    <?php endif; ?>
                </form>
                </div>
            </details>

            <div class="rel-acoes-fundo">
                <?php if ($det["pat_id"]): ?>
                    <a class="btn btn-grey" href="app.php?page=pats&ver=<?= (int) $det[
                        "pat_id"
                    ] ?>"><i class="bi bi-headset"></i> Abrir PAT</a>
                <?php endif; ?>
                <button type="button" class="btn btn-grey" onclick="document.querySelector('.rel-avancado').open = true; document.querySelector('.rel-avancado').scrollIntoView({behavior:'smooth', block:'center'});"><i class="bi bi-list-check"></i> Rever</button>
                <button type="submit" form="formRelatorioDecidir" name="decisao" value="aprovar" class="btn btn-green" id="btnAprovarRelatorio" disabled>✓ Aprovar</button>
                <button type="submit" form="formRelatorioDecidir" name="decisao" value="rejeitar" class="btn btn-red" style="padding:7px 13px; font-size:12.5px;">Rejeitar</button>
            </div>

            <script>
            (function(){
                var boxes = document.querySelectorAll('.rel-check-box');
                var btn = document.getElementById('btnAprovarRelatorio');
                if (!boxes.length || !btn) return;
                function atualizar(){
                    var todasMarcadas = Array.prototype.every.call(boxes, function(b){ return b.checked; });
                    btn.disabled = !todasMarcadas;
                }
                boxes.forEach(function(b){ b.addEventListener('change', atualizar); });
                atualizar();
            })();
            </script>

            <style>
            .rel-info-grid{ display:grid; grid-template-columns:1fr 1fr; gap:14px 18px; margin-bottom:4px; }
            .rel-info-item{ display:flex; flex-direction:column; gap:3px; }
            .rel-info-label{ font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#9ca3af; }
            .rel-info-val{ font-size:14px; color:#1f2937; font-weight:600; }
            .rel-checklist{ background:#f8fafc; border:1px solid #e5e9ef; border-radius:10px; padding:14px 16px; margin:18px 0; }
            .rel-checklist-title{ font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#6b7280; margin-bottom:10px; }
            .rel-check-item{ display:flex; align-items:flex-start; gap:10px; font-size:13.5px; color:#374151; padding:6px 0; cursor:pointer; }
            .rel-check-item input[type="checkbox"]{ margin-top:2px; }
            .rel-avancado{ border:1px solid #e5e9ef; border-radius:10px; margin:14px 0; overflow:hidden; }
            .rel-avancado summary{ list-style:none; cursor:pointer; padding:11px 14px; font-size:13px; font-weight:600; color:#374151; display:flex; align-items:center; justify-content:space-between; background:#f8fafc; }
            .rel-avancado summary::-webkit-details-marker{ display:none; }
            .rel-avancado-pill{ font-size:11px; font-weight:600; color:#6b7280; background:#fff; border:1px solid #e5e9ef; border-radius:999px; padding:2px 9px; }
            .rel-avancado-body{ padding:14px; border-top:1px solid #e5e9ef; }
            .rel-acoes-fundo{ display:flex; gap:10px; flex-wrap:wrap; margin-top:18px; padding-top:16px; border-top:1px solid #eef1f5; }
            body.dark-mode .rel-info-val{ color:#e5e7eb; }
            body.dark-mode .rel-checklist{ background:#1f2937; border-color:#374151; }
            body.dark-mode .rel-check-item{ color:#d1d5db; }
            body.dark-mode .rel-avancado{ border-color:#374151; }
            body.dark-mode .rel-avancado summary{ background:#1f2937; color:#e5e7eb; }
            body.dark-mode .rel-avancado-body{ border-color:#374151; }
            body.dark-mode .rel-acoes-fundo{ border-color:#374151; }
            </style>

            <?php else: ?>
                <p style="font-size:13px; color:#6b7280;">Estado: <strong><?= e(
                    $det["estado"],
                ) ?></strong>
                    <?= $det["aprovado_por"]
                        ? " (" . e($det["aprovado_por"]) . ")"
                        : "" ?></p>
                <?php if ($linhas): ?>
                <div style="margin-top:14px; overflow-x:auto;">
                    <table class="table">
                        <thead><tr><th>SN</th><th>Ação</th><th>Estado destino</th><th>Cliente</th></tr></thead>
                        <tbody>
                        <?php foreach ($linhas as $l): ?>
                            <tr>
                                <td><?= e($l["sn"]) ?></td>
                                <td><?= e($l["acao"]) ?></td>
                                <td><?= e($l["estado_destino"]) ?></td>
                                <td><?= e($l["cliente_destino"] ?? "") ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                <?php if ($det["pat_id"]): ?>
                <div style="margin-top:16px;">
                    <a class="btn btn-grey" href="app.php?page=pats&ver=<?= (int) $det[
                        "pat_id"
                    ] ?>"><i class="bi bi-headset"></i> Abrir PAT</a>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php else: ?>
            <div style="text-align:center; padding:34px 20px; color:#6b7280;">
                <div style="width:56px; height:56px; margin:0 auto 14px; border-radius:50%; background:#fbf1da; display:flex; align-items:center; justify-content:center;">
                    <i class="bi bi-file-earmark-text" style="font-size:24px; color:#c9a14a;"></i>
                </div>
                <p style="font-size:15px; font-weight:600; color:#374151; margin-bottom:6px;">Nenhum relatório selecionado</p>
                <p style="font-size:13px; margin:0;">Escolhe um relatório na lista abaixo.</p>
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
                    <input type="hidden" name="csrf" value="<?= e(
                        $csrfToken,
                    ) ?>">
                    <label style="font-size:12px; color:#6b7280; display:block; margin-bottom:6px;">Ficheiro PDF ou EML</label>
                    <input type="file" name="relatorio" accept=".pdf,.eml" required style="display:block; width:100%; margin-bottom:10px; font-size:13px;">
                    <button type="submit" class="btn btn-blue" style="width:100%; justify-content:center;"><i class="bi bi-upload"></i> Importar</button>
                </form>
            </div>
        </details>
    </div>
    <div class="relatorios-fonte-grid" id="relatoriosFonteGrid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px;">
        <a href="app.php?page=relatorios" class="relatorios-fonte-card" data-fonte="" style="text-decoration:none; color:inherit;">
            <div style="border:2px solid <?= $fonteAtiva === ""
                ? "#c9a14a"
                : "#e5e7eb" ?>; border-radius:10px; padding:16px; text-align:center; transition:border-color .15s ease;">
                <i class="bi bi-grid" style="font-size:26px; color:#c9a14a;"></i>
                <div style="font-size:24px; font-weight:700; margin:8px 0 2px;"><?= $totalRelatorios ?></div>
                <div style="font-size:14px; color:#374151;">Todos</div>
            </div>
        </a>
        <?php foreach ($fontesInfo as $fonteChave => $info): ?>
            <a href="app.php?page=relatorios&fonte=<?= urlencode(
                $fonteChave,
            ) ?>" class="relatorios-fonte-card" data-fonte="<?= htmlspecialchars(
    $fonteChave,
) ?>" style="text-decoration:none; color:inherit;">
                <div style="border:2px solid <?= $fonteAtiva === $fonteChave
                    ? "#c9a14a"
                    : "#e5e7eb" ?>; border-radius:10px; padding:16px; text-align:center; transition:border-color .15s ease;">
                    <i class="bi <?= $info[
                        "icone"
                    ] ?>" style="font-size:26px; color:#c9a14a;"></i>
                    <div style="font-size:24px; font-weight:700; margin:8px 0 2px;"><?= $contagemFontes[
                        $fonteChave
                    ] ?></div>
                    <div style="font-size:14px; color:#374151;"><?= e(
                        $info["nome"],
                    ) ?></div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Pesquisa por baixo dos cartões de parceiros -->
<div class="quick-search-wrap" style="max-width:none; width:100%; margin-bottom:20px;">
    <i class="bi bi-search"></i>
    <input type="text" class="quick-search-input" data-table="#tabelaRelatorios" data-empty="#tabelaRelatoriosVazia" placeholder="Pesquisa por ficheiro, PAT ou cliente…">
</div>


<?php
// Opção F — meses disponíveis (para agrupar a lista e alimentar o filtro inline)
$mesesPt = [
    1 => "Janeiro",
    2 => "Fevereiro",
    3 => "Março",
    4 => "Abril",
    5 => "Maio",
    6 => "Junho",
    7 => "Julho",
    8 => "Agosto",
    9 => "Setembro",
    10 => "Outubro",
    11 => "Novembro",
    12 => "Dezembro",
];
$relMesesDisponiveis = [];
foreach ($lista as $rTmp) {
    $tsTmp = strtotime((string) $rTmp["criado_em"]);
    if ($tsTmp) {
        $relMesesDisponiveis[date("Y-m", $tsTmp)] =
            $mesesPt[(int) date("n", $tsTmp)] . " " . date("Y", $tsTmp);
    }
}
?>
<!-- ══ LINHA INFERIOR: Lista de Relatórios (largura total) ══ -->
<div class="panel" id="painelListaRelatorios">
    <div class="panel-header-row">
        <div class="panel-header-left">
            <h4 style="margin:0;">
                <i class="bi bi-list-ul" style="margin-right:6px; color:#c9a14a;"></i>Lista de Relatórios
                <?php if (
                    $fonteAtiva !== "" &&
                    isset($fontesInfo[$fonteAtiva])
                ): ?>
                    <span style="font-size:13px; font-weight:500; color:#6b7280; margin-left:8px;">— <?= e(
                        $fontesInfo[$fonteAtiva]["nome"],
                    ) ?></span>
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
    <div class="table-responsive mv-table-wrap">
        <table class="table rel-table-stacked" id="tabelaRelatorios">
            <tbody>
            <?php if (empty($lista)): ?>
                <tr id="tabelaRelatoriosVazia" data-no-filter><td class="envios-vazio">Nenhum relatório registado.</td></tr>
            <?php else: ?>
                <?php
                $mesAtual = null;
                foreach ($lista as $r): ?>
                    <?php
                    [$bg, $fg] = $corEstado($r["estado"]);
                    $tsR = strtotime((string) $r["criado_em"]);
                    $mesKey = $tsR ? date("Y-m", $tsR) : "0000-00";
                    $mesLbl = $tsR
                        ? $mesesPt[(int) date("n", $tsR)] .
                            " " .
                            date("Y", $tsR)
                        : "Sem data";
                    if ($mesKey !== $mesAtual):
                        $mesAtual = $mesKey; ?>
                        <tr class="rel-month-row" data-mes="<?= $mesKey ?>"><td colspan="2"><i class="bi bi-calendar3"></i> <?= $mesLbl ?></td></tr>
                    <?php
                    endif;
                    ?>
                    <tr data-mes="<?= $mesKey ?>">
                        <td class="rel-row-info">
                            <div class="rel-row-file"><?= e($r["ficheiro_nome"]) ?></div>
                            <div class="rel-row-line">Fonte: <?= e(
                                $fontesInfo[$r["fonte"]]["nome"] ?? $r["fonte"],
                            ) ?> &nbsp;|&nbsp; PAT: <?= e($r["pat_numero"] ?: "—") ?></div>
                            <div class="rel-row-line">Cliente: <?= e($r["cliente_detect"] ?: "—") ?></div>
                            <div class="rel-row-line">
                                Estado:
                                <span style="display:inline-block; padding:1px 9px; border-radius:20px; font-size:11px; font-weight:600; background:<?= $bg ?>; color:<?= $fg ?>;">
                                    <?= e($r["estado"]) ?>
                                </span>
                                &nbsp;|&nbsp; Data: <?= e($r["criado_em"]) ?>
                            </div>
                        </td>
                        <td class="rel-row-actions-cell">
                            <div class="rel-row-actions">
                            <a class="btn btn-yellow" href="<?= e(
                                $linkBase,
                            ) ?>&ver=<?= (int) $r["id"] ?>" title="Editar" aria-label="Editar"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="app.php?page=relatorios" onsubmit="return nvConfirmar(this, 'Apagar definitivamente este relatório? Esta ação é irreversível.');">
                                <input type="hidden" name="action" value="relatorio_apagar">
                                <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="relatorio_id" value="<?= (int) $r["id"] ?>">
                                <button type="submit" class="btn btn-red" title="Apagar" aria-label="Apagar"><i class="bi bi-trash3"></i></button>
                            </form>
                            <?php if (!empty($r["ficheiro_path"])): ?>
                            <a class="btn btn-grey" href="<?= e(
                                $r["ficheiro_path"],
                            ) ?>" target="_blank" rel="noopener" title="Visualizar PDF" aria-label="Visualizar PDF"><i class="bi bi-eye"></i></a>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach;
                ?>
                <tr id="tabelaRelatoriosVazia" data-no-filter style="display:none;"><td colspan="2" class="envios-vazio">Sem resultados para esta pesquisa.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div><!-- /.mv-table-wrap -->

<!-- ── Relatórios · Cards mobile (≤640px) ── -->
<div class="mv-cards">
<?php if (empty($lista)): ?>
    <div class="mv-cards-empty"><i class="bi bi-inbox"></i>Nenhum relatório registado.</div>
<?php else: ?>
    <?php foreach ($lista as $r):
        [$bg, $fg] = $corEstado($r["estado"]); ?>
    <div class="mv-card">
        <div class="mv-card-header">
            <div>
                <div class="mv-card-title" style="font-size:13px;word-break:break-all;"><?= e(
                    $r["ficheiro_nome"],
                ) ?></div>
                <div class="mv-card-sub mv-card-sub-text"><?= e(
                    $fontesInfo[$r["fonte"]]["nome"] ?? $r["fonte"],
                ) ?></div>
            </div>
            <span style="padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600;white-space:nowrap;flex-shrink:0;background:<?= $bg ?>;color:<?= $fg ?>;"><?= e(
    $r["estado"],
) ?></span>
        </div>
        <?php if ($r["pat_numero"]): ?>
        <div class="mv-card-row">
            <span class="mv-card-row-label">PAT</span>
            <span class="mv-card-row-val"><?= e($r["pat_numero"]) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($r["cliente_detect"]): ?>
        <div class="mv-card-row">
            <span class="mv-card-row-label">Cliente</span>
            <span class="mv-card-row-val" style="font-size:11.5px;"><?= e(
                $r["cliente_detect"],
            ) ?></span>
        </div>
        <?php endif; ?>
        <div class="mv-card-row">
            <span class="mv-card-row-label">Data</span>
            <span class="mv-card-row-val"><?= e(
                substr($r["criado_em"], 0, 10),
            ) ?></span>
        </div>
        <div class="mv-card-footer">
            <a class="btn btn-yellow" href="<?= e(
                $linkBase,
            ) ?>&ver=<?= (int) $r["id"] ?>"><i class="bi bi-eye"></i> Ver</a>
        </div>
    </div>
    <?php
    endforeach; ?>
<?php endif; ?>
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

/* Lista de Relatórios — linhas empilhadas (mais próximo do wireframe) */
.rel-table-stacked{ border-collapse:separate; border-spacing:0; }
.rel-table-stacked tbody tr:not(.rel-month-row){ border-bottom:1px solid #f1f3f6; }
.rel-table-stacked tbody tr:not(.rel-month-row):last-child{ border-bottom:none; }
.rel-row-info{ padding:14px 16px !important; vertical-align:top; border:none !important; }
.rel-row-file{ font-weight:700; font-size:14px; color:#1f2937; margin-bottom:4px; }
.rel-row-line{ font-size:12.5px; color:#6b7280; line-height:1.7; }
.rel-row-actions-cell{ padding:14px 16px !important; vertical-align:middle; border:none !important; width:56px; }
.rel-row-actions{ display:flex; flex-direction:column; gap:6px; align-items:center; }
.rel-row-actions form{ margin:0; }
.rel-row-actions .btn{ width:34px; height:34px; padding:0 !important; display:flex; align-items:center; justify-content:center; font-size:13px; margin:0 !important; }
body.dark-mode .rel-table-stacked tbody tr:not(.rel-month-row){ border-bottom-color:#374151; }
body.dark-mode .rel-row-file{ color:#f3f4f6; }
body.dark-mode .rel-row-line{ color:#9ca3af; }
</style>
<script>
window.nvRelFiltrarMes = function(sel){
  var mes = sel.value;
  var panel = sel.closest('.panel'); if(!panel) return;
  var tbody = panel.querySelector('tbody'); if(!tbody) return;
  tbody.querySelectorAll('tr[data-mes]').forEach(function(tr){
    var show = !mes || tr.getAttribute('data-mes') === mes;
    tr.style.display = show ? '' : 'none';
    if (tr.classList.contains('rel-month-row') && show) {
      var next = tr.nextElementSibling;
      var hasVisible = false;
      while (next && !next.classList.contains('rel-month-row')) {
        if (next.hasAttribute('data-mes') && next.style.display !== 'none') { hasVisible = true; break; }
        next = next.nextElementSibling;
      }
      if (mes) tr.style.display = hasVisible ? '' : 'none';
    }
  });
};
</script>
