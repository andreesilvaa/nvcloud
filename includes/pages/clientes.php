<?php
$clientes = [];
$clientesStats = [
    "total" => 0,
    "customers" => 0,
    "prospects" => 0,
    "partners" => 0,
    "com_parent" => 0,
    "grupos_parent" => 0,
];
$clientesFiltros = [
    "q" => trim($_GET["q"] ?? ""),
    "type" => trim($_GET["type"] ?? ""),
    "hierarquia" => trim($_GET["hierarquia"] ?? ""),
];
$clientesTipos = [];
$clientesPais = [];
$clientesRoots = [];
$clientesChildrenMap = [];

if ($page === "clientes") {
    // Lista de clientes — a partir da tabela `clientes`
    // (importada do CSV via github/importar_clientes.php).
    try {
        $rows = $pdo
            ->query(
                "SELECT account_name, type, parent_account, last_activity, last_modified_date, activity_count FROM clientes ORDER BY account_name ASC",
            )
            ->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
        $clientesStats["csv_error"] =
            "Tabela de clientes não encontrada. Corre o importador: php github/importar_clientes.php";
    }
    foreach ($rows as $r) {
        $accountName = (string) $r["account_name"];
        if ($accountName === "") {
            continue;
        }
        $type = (string) ($r["type"] ?? "");
        $parent = (string) ($r["parent_account"] ?? "");
        $lastActivity = (string) ($r["last_activity"] ?? "");
        $lastModified = (string) ($r["last_modified_date"] ?? "");

        $cliente = [
            "account_name" => $accountName,
            "type" => $type,
            "parent_account" => $parent,
            "last_activity" => $lastActivity,
            "last_modified_date" => $lastModified,
            "is_child" => $parent !== "",
            "activity_count" => (int) ($r["activity_count"] ?? 0),
        ];
        $clientes[] = $cliente;
        $clientesStats["total"]++;
        if (strcasecmp($type, "Customer") === 0) {
            $clientesStats["customers"]++;
        } elseif (strcasecmp($type, "Prospect") === 0) {
            $clientesStats["prospects"]++;
        } elseif (stripos($type, "Partner") !== false) {
            $clientesStats["partners"]++;
        }
        if ($parent !== "") {
            $clientesStats["com_parent"]++;
            if (!isset($clientesChildrenMap[$parent])) {
                $clientesChildrenMap[$parent] = [];
            }
            $clientesChildrenMap[$parent][] = $cliente;
        }
        if ($type !== "" && !in_array($type, $clientesTipos, true)) {
            $clientesTipos[] = $type;
        }
    }

    /* ===== BLOCO ANTIGO (leitura do CSV) — substituído pela BD acima. Mantido comentado como referência.
    $csvPath = __DIR__ . '/report1780499256737.csv';

    $clientesStats['debug'] = [
        'csv_path' => $csvPath,
        'csv_exists' => is_file($csvPath),
        'csv_readable' => is_readable($csvPath),
        'headers' => [],
        'detected_columns' => [],
        'csv_error' => '',
    ];

    if (is_file($csvPath) && is_readable($csvPath)) {
        $handle = fopen($csvPath, 'r');

        if ($handle !== false) {
            $header = fgetcsv($handle, 0, ';');

            $normalizeHeader = static function ($value) {
                $value = (string)$value;
                $value = trim($value);
                $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);

                if ($value !== '' && !mb_check_encoding($value, 'UTF-8')) {
                    $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
                }

                $value = trim($value, "\"' \t\n\r\0\x0B");
                return $value;
            };

            $normalizeCsvValue = static function ($value) {
                $value = trim((string)$value);

                if ($value === '') {
                    return '';
                }

                if (!mb_check_encoding($value, 'UTF-8')) {
                    $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
                }

                $value = trim($value, "\"' \t\n\r\0\x0B");
                return $value;
            };

            $headerMap = [];

            if (is_array($header)) {
                foreach ($header as $i => $col) {
                    $normalized = $normalizeHeader($col);
                    $headerMap[$normalized] = $i;
                    $clientesStats['debug']['headers'][] = $normalized;
                }
            }

            $findColumn = static function (array $headerMap, array $variants) {
                foreach ($variants as $variant) {
                    if (array_key_exists($variant, $headerMap)) {
                        return $headerMap[$variant];
                    }
                }
                return null;
            };

            $idxLastActivity = $findColumn($headerMap, [
                'Last Activity'
            ]);

            $idxAccountName = $findColumn($headerMap, [
                'Account Name',
                'Nome da Conta',
                'Conta'
            ]);

            $idxLastModified = $findColumn($headerMap, [
                'Last Modified Date',
                'Last Modified',
                'Data da Última Modificação',
                'Última Modificação'
            ]);

            $idxType = $findColumn($headerMap, [
                'Type',
                'Account Type',
                'Tipo'
            ]);

            $idxParent = $findColumn($headerMap, [
                'Parent Account',
                'Parent',
                'Conta Principal',
                'Conta-Mãe',
                'Conta Mae'
            ]);

            $clientesStats['debug']['detected_columns'] = [
                'Last Activity' => $idxLastActivity,
                'Account Name' => $idxAccountName,
                'Last Modified Date' => $idxLastModified,
                'Type' => $idxType,
                'Parent Account' => $idxParent,
            ];

            $csvError = [];

            if ($idxAccountName === null) {
                $csvError[] = "Coluna 'Account Name' não encontrada no CSV.";
            }

            if ($idxParent === null) {
                $csvError[] = "Coluna 'Parent Account' (ou variantes) não encontrada no CSV.";
            }

            if ($idxType === null) {
                $csvError[] = "Coluna 'Type' (ou variantes) não encontrada no CSV. A importação vai continuar, mas os tipos ficarão vazios.";
            }

            if ($idxAccountName !== null && $idxParent !== null) {
                while (($row = fgetcsv($handle, 0, ';')) !== false) {
                    $accountName = $normalizeCsvValue($row[$idxAccountName] ?? '');
                    $type = $idxType !== null ? $normalizeCsvValue($row[$idxType] ?? '') : '';
                    $parent = $normalizeCsvValue($row[$idxParent] ?? '');
                    $lastActivity = $idxLastActivity !== null ? $normalizeCsvValue($row[$idxLastActivity] ?? '') : '';
                    $lastModified = $idxLastModified !== null ? $normalizeCsvValue($row[$idxLastModified] ?? '') : '';

                    if ($accountName === '') {
                        continue;
                    }

                    $cliente = [
                        'account_name' => $accountName,
                        'type' => $type,
                        'parent_account' => $parent,
                        'last_activity' => $lastActivity,
                        'last_modified_date' => $lastModified,
                        'is_child' => $parent !== '',
                    ];

                    $clientes[] = $cliente;
                    $clientesStats['total']++;

                    if (strcasecmp($type, 'Customer') === 0) {
                        $clientesStats['customers']++;
                    } elseif (strcasecmp($type, 'Prospect') === 0) {
                        $clientesStats['prospects']++;
                    } elseif (stripos($type, 'Partner') !== false) {
                        $clientesStats['partners']++;
                    }

                    if ($parent !== '') {
                        $clientesStats['com_parent']++;

                        if (!isset($clientesChildrenMap[$parent])) {
                            $clientesChildrenMap[$parent] = [];
                        }

                        $clientesChildrenMap[$parent][] = $cliente;
                    }

                    if ($type !== '' && !in_array($type, $clientesTipos, true)) {
                        $clientesTipos[] = $type;
                    }
                }
            }

            if (!empty($csvError)) {
                $clientesStats['csv_error'] = implode("\n", $csvError);
                $clientesStats['debug']['csv_error'] = implode("\n", $csvError);
            }

            fclose($handle);
        } else {
            $clientesStats['csv_error'] = 'Não foi possível abrir o CSV.';
            $clientesStats['debug']['csv_error'] = 'Não foi possível abrir o CSV.';
        }
    } else {
        $clientesStats['csv_error'] = 'CSV não encontrado ou sem permissões de leitura.';
        $clientesStats['debug']['csv_error'] = 'CSV não encontrado ou sem permissões de leitura.';
    }
    ===== FIM BLOCO ANTIGO ===== */

    sort($clientesTipos, SORT_NATURAL | SORT_FLAG_CASE);

    foreach ($clientesChildrenMap as $parentName => $children) {
        $clientesStats["grupos_parent"]++;
    }

    $clientesIndex = [];

    foreach ($clientes as $cliente) {
        $clientesIndex[$cliente["account_name"]] = $cliente;
    }

    foreach ($clientes as $cliente) {
        $nome = $cliente["account_name"];
        $temFilhos =
            isset($clientesChildrenMap[$nome]) &&
            count($clientesChildrenMap[$nome]) > 0;

        $matchTexto = true;
        if ($clientesFiltros["q"] !== "") {
            $q = mb_strtolower($clientesFiltros["q"]);
            $haystack = mb_strtolower(
                $cliente["account_name"] .
                    " " .
                    $cliente["type"] .
                    " " .
                    $cliente["parent_account"],
            );
            $matchTexto = mb_strpos($haystack, $q) !== false;
        }

        $matchType =
            $clientesFiltros["type"] === "" ||
            $cliente["type"] === $clientesFiltros["type"];

        $matchHierarquia = true;
        if ($clientesFiltros["hierarquia"] === "com_parent") {
            $matchHierarquia = $cliente["parent_account"] !== "";
        } elseif ($clientesFiltros["hierarquia"] === "so_pais") {
            $matchHierarquia = $temFilhos;
        } elseif ($clientesFiltros["hierarquia"] === "so_sem_parent") {
            $matchHierarquia = $cliente["parent_account"] === "";
        }

        if (!$matchTexto || !$matchType || !$matchHierarquia) {
            continue;
        }

        // Contas-Filhas também aparecem quando o filtro de hierarquia as pede
        if (
            $cliente["parent_account"] === "" ||
            $clientesFiltros["hierarquia"] === "com_parent"
        ) {
            $clientesRoots[] = $cliente;
        }
    }

    usort($clientesRoots, static function ($a, $b) {
        $diff = ($b["activity_count"] ?? 0) - ($a["activity_count"] ?? 0);
        return $diff !== 0
            ? $diff
            : strcasecmp($a["account_name"], $b["account_name"]);
    });

    foreach ($clientesChildrenMap as $parentName => &$children) {
        usort($children, static function ($a, $b) {
            return strcasecmp($a["account_name"], $b["account_name"]);
        });
    }
    unset($children);

    // Contactos/moradas (do Report .xlsx importado para clientes_contactos),
    // agregados por conta (uma conta pode ter vários contactos).
    $contactosMap = [];
    try {
        $stmtCt = $pdo->query("
            SELECT account_name,
                   GROUP_CONCAT(DISTINCT NULLIF(email,'')  SEPARATOR ', ') AS emails,
                   GROUP_CONCAT(DISTINCT NULLIF(phone,'')  SEPARATOR ', ') AS phones,
                   GROUP_CONCAT(DISTINCT NULLIF(mobile,'') SEPARATOR ', ') AS mobiles,
                   MIN(NULLIF(mailing_street,''))  AS street,
                   MIN(NULLIF(mailing_city,''))    AS city,
                   MIN(NULLIF(mailing_zip,''))     AS zip,
                   MIN(NULLIF(mailing_country,'')) AS country,
                   COUNT(*) AS n
              FROM clientes_contactos
             GROUP BY account_name
        ");
        foreach ($stmtCt as $ct) {
            $contactosMap[$ct["account_name"]] = $ct;
        }
    } catch (Throwable $e) {
        $contactosMap = []; // tabela ainda não existe — ignora
    }
}
?>

<?php if (!empty($clientesStats["csv_error"])): ?>
<div class="alerta-erro" style="margin-bottom: 20px;">
    <strong>Erro ao carregar dados do CSV:</strong><br>
    <?= nl2br(htmlspecialchars($clientesStats["csv_error"])) ?>
</div>
<?php endif; ?>

<div class="clientes-kpis clientes-page-kpis">
    <div class="cliente-kpi">
        <div class="label">Total de Contas</div>
        <div class="valor"><?= (int) $clientesStats["total"] ?></div>
    </div>
    <div class="cliente-kpi">
        <div class="label">Clientes</div>
        <div class="valor"><?= (int) $clientesStats["customers"] ?></div>
    </div>
    <div class="cliente-kpi">
        <div class="label">Perspetivas</div>
        <div class="valor"><?= (int) $clientesStats["prospects"] ?></div>
    </div>
    <div class="cliente-kpi">
        <div class="label">Parceiros</div>
        <div class="valor"><?= (int) $clientesStats["partners"] ?></div>
    </div>
    <div class="cliente-kpi">
        <div class="label">Contas com Conta-Mãe</div>
        <div class="valor"><?= (int) $clientesStats["com_parent"] ?></div>
    </div>
</div>

<div class="pats-filtros">
    <form method="get" class="pats-filtros-row">
        <input type="hidden" name="page" value="clientes">
        <div class="pats-search">
            <i class="bi bi-search"></i>
            <input type="text" name="q"
                   value="<?= htmlspecialchars($clientesFiltros["q"] ?? "") ?>"
                   placeholder="Nome da conta, conta-mãe ou tipo">
        </div>
        <select name="type">
            <option value="">-- Todos os Tipos --</option>
            <?php foreach ($clientesTipos as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>"
                        <?= ($clientesFiltros["type"] ?? "") === $t
                            ? "selected"
                            : "" ?>>
                    <?= htmlspecialchars(tipoPt($t)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="hierarquia">
            <option value="">-- Hierarquia --</option>
            <option value="com_parent" <?= ($clientesFiltros["hierarquia"] ??
                "") ===
            "com_parent"
                ? "selected"
                : "" ?>>Só Contas-Filhas</option>
            <option value="so_pais"    <?= ($clientesFiltros["hierarquia"] ??
                "") ===
            "so_pais"
                ? "selected"
                : "" ?>>Só Contas-Mãe</option>
            <option value="so_sem_parent" <?= ($clientesFiltros["hierarquia"] ??
                "") ===
            "so_sem_parent"
                ? "selected"
                : "" ?>>Sem Conta-Mãe</option>
        </select>
        <button type="submit" class="btn btn-blue"><i class="bi bi-funnel"></i> Filtrar</button>
        <a href="app.php?page=clientes" class="btn btn-grey">Limpar</a>
    </form>
</div>

  <div class="panel">
    <div class="panel-header-row">
        <div class="panel-header-left">
            <h4 style="margin:0;">Lista de Clientes</h4>
            <span class="panel-count-badge"><?= count($clientesRoots) ?></span>
        </div>
    </div>

    <!-- WF3: Lista de Clientes em CARTÕES (vista desktop) — cartões novos inspirados nos .mv-card -->
    <div class="cli-cards-wrap">
      <?php
      // Célula de contactos/morada como linhas (reutilizada nos cartões)
      $cliContactosCard = function (array $map, string $nome): string {
          $c = $map[$nome] ?? null;
          if (!$c) {
              return '<div class="cli-card-semcontacto">Sem contacto / sem morada</div>';
          }
          $p = [];
          if (!empty($c["emails"])) {
              $p[] = '<span><i class="bi bi-envelope"></i> ' . htmlspecialchars($c["emails"]) . '</span>';
          }
          $tel = trim(implode(', ', array_filter([$c["phones"] ?? '', $c["mobiles"] ?? ''])));
          if ($tel !== '') {
              $p[] = '<span><i class="bi bi-telephone"></i> ' . htmlspecialchars($tel) . '</span>';
          }
          $mor = trim(implode(', ', array_filter([$c["street"] ?? '', $c["city"] ?? '', $c["zip"] ?? '', $c["country"] ?? ''])));
          if ($mor !== '') {
              $p[] = '<span><i class="bi bi-geo-alt"></i> ' . htmlspecialchars($mor) . '</span>';
          }
          return $p
              ? '<div class="cli-card-contactos">' . implode('', $p) . '</div>'
              : '<div class="cli-card-semcontacto">Sem contacto / sem morada</div>';
      };

      $cliTipoInfo = function (string $type): array {
          if (strcasecmp($type, "Customer") === 0) return ["tipo-customer", "#16a34a"];
          if (strcasecmp($type, "Prospect") === 0) return ["tipo-prospect", "#2563eb"];
          if (stripos($type, "Partner") !== false)  return ["tipo-partner", "#ea580c"];
          return ["tipo-other", "#9ca3af"];
      };
      ?>
      <?php if (empty($clientesRoots)): ?>
        <div class="clientes-empty" style="padding:34px 16px; text-align:center;"><i class="bi bi-people" style="font-size:30px; display:block; margin-bottom:8px; opacity:.4;"></i>Nenhum Cliente encontrado.</div>
      <?php else: ?>
        <?php foreach ($clientesRoots as $i => $cliente):
            $nomeConta = $cliente["account_name"];
            $filhos = $clientesChildrenMap[$nomeConta] ?? [];
            $temFilhos = count($filhos) > 0;
            [$typeClass, $dotCor] = $cliTipoInfo($cliente["type"]);
            $cardId = "cli-card-grp-" . $i;
        ?>
        <div class="cli-card">
          <div class="cli-card-top">
            <div class="cli-card-ident">
              <div class="cli-card-nome"><?= htmlspecialchars($cliente["account_name"]) ?></div>
              <div class="cli-card-meta">
                <span class="tipo-badge <?= $typeClass ?>"><span class="cli-dot" style="background:<?= $dotCor ?>;"></span><?= htmlspecialchars(tipoPt($cliente["type"])) ?></span>
                <?php if ($cliente["parent_account"] !== ""): ?>
                  <span class="cli-card-parent">↳ <?= htmlspecialchars($cliente["parent_account"]) ?></span>
                <?php else: ?>
                  <span class="conta-principal-badge">Conta Principal</span>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <?= $cliContactosCard($contactosMap ?? [], $cliente["account_name"]) ?>

          <?php if ($temFilhos): ?>
            <button type="button" class="cli-card-expand" data-target="<?= htmlspecialchars($cardId) ?>" onclick="nvCliCardToggle(this)">
              <i class="bi bi-diagram-3"></i> Expandir filiais <span class="cli-card-expand-n"><?= count($filhos) ?></span>
              <i class="bi bi-chevron-down cli-card-caret"></i>
            </button>
            <div class="cli-subcards <?= htmlspecialchars($cardId) ?>" style="display:none;">
              <?php foreach ($filhos as $filho):
                  [$childTypeClass, $childDot] = $cliTipoInfo($filho["type"]);
              ?>
                <div class="cli-subcard">
                  <div class="cli-subcard-nome"><?= htmlspecialchars($filho["account_name"]) ?></div>
                  <div class="cli-card-meta">
                    <span class="tipo-badge <?= $childTypeClass ?>"><span class="cli-dot" style="background:<?= $childDot ?>;"></span><?= htmlspecialchars(tipoPt($filho["type"])) ?></span>
                    <span class="cli-card-childbadge">Child-account</span>
                  </div>
                  <?= $cliContactosCard($contactosMap ?? [], $filho["account_name"]) ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div><!-- /.cli-cards-wrap -->

    <style>
      .cli-cards-wrap{ display:grid; grid-template-columns:repeat(auto-fill, minmax(340px, 1fr)); gap:16px; }
      .cli-card{ background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:16px 18px; box-shadow:0 1px 5px rgba(0,0,0,.05); display:flex; flex-direction:column; }
      .cli-card-top{ display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
      .cli-card-nome{ font-weight:700; font-size:15px; color:#1e293b; line-height:1.3; }
      .cli-card-meta{ display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin-top:7px; }
      .cli-dot{ display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:6px; vertical-align:middle; }
      .cli-card-parent{ font-size:12px; color:#6b7280; }
      .cli-card-childbadge{ font-size:11px; font-weight:600; color:#6b7280; background:#eef2f7; border-radius:999px; padding:2px 9px; }
      .cli-card-contactos{ display:flex; flex-direction:column; gap:6px; margin-top:12px; padding-top:12px; border-top:1px solid #f1f5f9; font-size:12.5px; }
      .cli-card-contactos span{ display:inline-flex; align-items:flex-start; gap:7px; color:#6b7280; word-break:break-word; }
      .cli-card-contactos span i{ color:#9ca3af; margin-top:2px; flex-shrink:0; }
      .cli-card-semcontacto{ margin-top:12px; padding-top:12px; border-top:1px solid #f1f5f9; font-size:12.5px; color:#b6bcc6; font-style:italic; }
      .cli-card-expand{ margin-top:14px; display:inline-flex; align-items:center; gap:8px; width:100%; justify-content:flex-start; background:#f8fafc; border:1px solid #e5e9ef; border-radius:9px; padding:9px 12px; font-size:13px; font-weight:600; color:#374151; cursor:pointer; transition:background .15s; }
      .cli-card-expand:hover{ background:#f1f5f9; }
      .cli-card-expand .cli-card-expand-n{ background:#c9a14a; color:#fff; border-radius:999px; font-size:11px; padding:1px 8px; }
      .cli-card-expand .cli-card-caret{ margin-left:auto; transition:transform .15s; color:#9ca3af; }
      .cli-card-expand.is-open .cli-card-caret{ transform:rotate(180deg); }
      .cli-subcards{ margin-top:12px; display:flex; flex-direction:column; gap:10px; }
      .cli-subcard{ background:#fbfcfe; border:1px solid #eef1f5; border-left:3px solid #c9a14a; border-radius:10px; padding:12px 14px; }
      .cli-subcard-nome{ font-weight:600; font-size:13.5px; color:#1f2937; }
      .cli-subcard .cli-card-meta{ margin-top:6px; }
      .cli-subcard .cli-card-contactos, .cli-subcard .cli-card-semcontacto{ margin-top:10px; padding-top:10px; }
      body.dark-mode .cli-card{ background:#1e2533; border-color:#374151; }
      body.dark-mode .cli-card-nome{ color:#f1f5f9; }
      body.dark-mode .cli-card-contactos, body.dark-mode .cli-card-semcontacto{ border-color:#2b3647; }
      body.dark-mode .cli-card-expand{ background:#161c27; border-color:#374151; color:#e5e7eb; }
      body.dark-mode .cli-subcard{ background:#161c27; border-color:#374151; }
      body.dark-mode .cli-subcard-nome{ color:#f1f5f9; }
      /* Em ecrãs pequenos os cartões mobile (.mv-cards) assumem; esconder a grelha desktop */
      @media (max-width:640px){ .cli-cards-wrap{ display:none; } }
    </style>

    <script>
      function nvCliCardToggle(btn){
        var target = btn.getAttribute('data-target');
        if (!target) return;
        var box = btn.parentElement.querySelector('.cli-subcards.' + CSS.escape(target));
        if (!box) return;
        var aberto = box.style.display !== 'none';
        box.style.display = aberto ? 'none' : 'flex';
        btn.classList.toggle('is-open', !aberto);
      }
      // Reescreve os helpers globais de expandir/colapsar tudo para os cartões
      function nvClientesExpandirTudo(abrir){
        document.querySelectorAll('.cli-card-expand').forEach(function(btn){
          var target = btn.getAttribute('data-target');
          if (!target) return;
          var box = btn.parentElement.querySelector('.cli-subcards.' + CSS.escape(target));
          if (!box) return;
          box.style.display = abrir ? 'flex' : 'none';
          btn.classList.toggle('is-open', abrir);
        });
      }
    </script>


<!-- ── Clientes · Cards mobile (≤640px) ── -->
<div class="mv-cards">
<?php if (empty($clientesRoots)): ?>
    <div class="mv-cards-empty"><i class="bi bi-people"></i>Nenhum cliente encontrado.</div>
<?php else: ?>
    <?php foreach ($clientesRoots as $i => $cliente):

        $nomeConta = $cliente["account_name"];
        $filhos = $clientesChildrenMap[$nomeConta] ?? [];
        $typeClass = "tipo-other";
        if (strcasecmp($cliente["type"], "Customer") === 0) {
            $typeClass = "tipo-customer";
        } elseif (strcasecmp($cliente["type"], "Prospect") === 0) {
            $typeClass = "tipo-prospect";
        } elseif (stripos($cliente["type"], "Partner") !== false) {
            $typeClass = "tipo-partner";
        }
        $dotCor =
            $typeClass === "tipo-customer"
                ? "#16a34a"
                : ($typeClass === "tipo-prospect"
                    ? "#2563eb"
                    : ($typeClass === "tipo-partner"
                        ? "#ea580c"
                        : "#9ca3af"));
        $c = $contactosMap[$nomeConta] ?? null;
        ?>
    <div class="mv-card">
        <div class="mv-card-header">
            <div>
                <div class="mv-card-title"><?= htmlspecialchars(
                    $cliente["account_name"],
                ) ?></div>
                <?php if ($cliente["parent_account"] !== ""): ?>
                    <div class="mv-card-sub mv-card-sub-text">↳ <?= htmlspecialchars(
                        $cliente["parent_account"],
                    ) ?></div>
                <?php endif; ?>
            </div>
            <span class="tipo-badge <?= $typeClass ?>">
                <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:<?= $dotCor ?>;margin-right:5px;vertical-align:middle;"></span><?= htmlspecialchars(
    tipoPt($cliente["type"]),
) ?>
            </span>
        </div>
        <?php if ($c): ?>
            <?php if (!empty($c["emails"])): ?>
            <div class="mv-card-row">
                <span class="mv-card-row-label"><i class="bi bi-envelope"></i></span>
                <span class="mv-card-row-val" style="font-size:11.5px;"><?= htmlspecialchars(
                    $c["emails"],
                ) ?></span>
            </div>
            <?php endif; ?>
            <?php
            $tel = trim(
                implode(
                    ", ",
                    array_filter([$c["phones"] ?? "", $c["mobiles"] ?? ""]),
                ),
            );
            if ($tel): ?>
            <div class="mv-card-row">
                <span class="mv-card-row-label"><i class="bi bi-telephone"></i></span>
                <span class="mv-card-row-val"><?= htmlspecialchars(
                    $tel,
                ) ?></span>
            </div>
            <?php endif;
            ?>
            <?php
            $mor = trim(
                implode(
                    ", ",
                    array_filter([$c["city"] ?? "", $c["country"] ?? ""]),
                ),
            );
            if ($mor): ?>
            <div class="mv-card-row">
                <span class="mv-card-row-label"><i class="bi bi-geo-alt"></i></span>
                <span class="mv-card-row-val" style="font-size:11.5px;"><?= htmlspecialchars(
                    $mor,
                ) ?></span>
            </div>
            <?php endif;
            ?>
        <?php endif; ?>
        <?php if (count($filhos) > 0): ?>
        <div class="mv-card-row">
            <span class="mv-card-row-label">Contas filhas</span>
            <span class="mv-card-row-val"><?= count($filhos) ?></span>
        </div>
        <?php endif; ?>
    </div>
<?php
    endforeach; ?>
<?php endif; ?>
</div>

</div>

<style>
.cli-det-btn{ padding:5px 9px !important; }
.cli-det-btn i{ transition:transform .15s; display:inline-block; }
.cli-det-btn.is-open i{ transform:rotate(180deg); }
</style>
<script>
function nvCliDetalhe(btn){
  var tr = btn.closest('tr');
  var det = tr.nextElementSibling;
  if (det && det.classList.contains('cli-det')){
    var aberto = det.style.display !== 'none';
    det.style.display = aberto ? 'none' : '';
    btn.classList.toggle('is-open', !aberto);
  }
}
</script>
<script>
    function cliToggleContacto(el) {
        var isMobile = window.innerWidth <= 768;
        var row = el.closest('tr');

        if (!isMobile) {
            // Desktop: toggle contacts inline in the 4th column
            var cont = row.querySelector('.cli-contactos');
            if (!cont) return;
            cont.style.display = cont.style.display === 'flex' ? 'none' : 'flex';
            return;
        }

        // Mobile: toggle an expanded row below with horizontal contacts
        var nextRow = row.nextElementSibling;
        if (nextRow && nextRow.classList.contains('cli-contact-expanded')) {
            nextRow.remove();
            el.classList.remove('is-expanded');
            return;
        }

        var contTd = row.querySelector('.col-contactos');
        if (!contTd) return;
        var contInner = contTd.innerHTML;

        var expandedRow = document.createElement('tr');
        expandedRow.className = 'cli-contact-expanded';
        var colspan = row.querySelectorAll('td').length - 1; // exclude hidden 4th col
        expandedRow.innerHTML = '<td colspan="' + colspan + '" class="cli-contact-expanded-cell">' + contInner + '</td>';
        row.parentNode.insertBefore(expandedRow, row.nextElementSibling);
        el.classList.add('is-expanded');
    }
</script>
