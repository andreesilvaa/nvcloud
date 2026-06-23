<?php
$clientes = [];
$clientesStats = [
    'total' => 0,
    'customers' => 0,
    'prospects' => 0,
    'partners' => 0,
    'com_parent' => 0,
    'grupos_parent' => 0
];
$clientesFiltros = [
    'q' => trim($_GET['q'] ?? ''),
    'type' => trim($_GET['type'] ?? ''),
    'hierarquia' => trim($_GET['hierarquia'] ?? '')
];
$clientesTipos = [];
$clientesPais = [];
$clientesRoots = [];
$clientesChildrenMap = [];

if ($page === 'clientes') {
    // Lista de clientes — a partir da tabela `clientes`
    // (importada do CSV via github/importar_clientes.php).
    try {
   $rows = $pdo->query("SELECT account_name, type, parent_account, last_activity, last_modified_date, activity_count FROM clientes ORDER BY account_name ASC")->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
        $clientesStats['csv_error'] = 'Tabela de clientes não encontrada. Corre o importador: php github/importar_clientes.php';
    }
    foreach ($rows as $r) {
        $accountName = (string)$r['account_name'];
        if ($accountName === '') { continue; }
        $type   = (string)($r['type'] ?? '');
        $parent = (string)($r['parent_account'] ?? '');
        $lastActivity = (string)($r['last_activity'] ?? '');
        $lastModified = (string)($r['last_modified_date'] ?? '');

        $cliente = [
            'account_name'       => $accountName,
            'type'               => $type,
            'parent_account'     => $parent,
            'last_activity'      => $lastActivity,
            'last_modified_date' => $lastModified,
            'is_child'           => $parent !== '',
            'activity_count'     => (int)($r['activity_count'] ?? 0),
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
        $clientesStats['grupos_parent']++;
    }

    $clientesIndex = [];

    foreach ($clientes as $cliente) {
        $clientesIndex[$cliente['account_name']] = $cliente;
    }

    foreach ($clientes as $cliente) {
        $nome = $cliente['account_name'];
        $temFilhos = isset($clientesChildrenMap[$nome]) && count($clientesChildrenMap[$nome]) > 0;

        $matchTexto = true;
        if ($clientesFiltros['q'] !== '') {
            $q = mb_strtolower($clientesFiltros['q']);
            $haystack = mb_strtolower(
                $cliente['account_name'] . ' ' . $cliente['type'] . ' ' . $cliente['parent_account']
            );
            $matchTexto = mb_strpos($haystack, $q) !== false;
        }

        $matchType = $clientesFiltros['type'] === '' || $cliente['type'] === $clientesFiltros['type'];

        $matchHierarquia = true;
        if ($clientesFiltros['hierarquia'] === 'com_parent') {
            $matchHierarquia = $cliente['parent_account'] !== '';
        } elseif ($clientesFiltros['hierarquia'] === 'so_pais') {
            $matchHierarquia = $temFilhos;
        } elseif ($clientesFiltros['hierarquia'] === 'so_sem_parent') {
            $matchHierarquia = $cliente['parent_account'] === '';
        }

        if (!$matchTexto || !$matchType || !$matchHierarquia) {
            continue;
        }

        // Contas-Filhas também aparecem quando o filtro de hierarquia as pede
        if ($cliente['parent_account'] === '' || $clientesFiltros['hierarquia'] === 'com_parent') {
            $clientesRoots[] = $cliente;
        }


    }

    usort($clientesRoots, static function ($a, $b) {
        $diff = ($b['activity_count'] ?? 0) - ($a['activity_count'] ?? 0);
        return $diff !== 0 ? $diff : strcasecmp($a['account_name'], $b['account_name']);
    });

    foreach ($clientesChildrenMap as $parentName => &$children) {
        usort($children, static function ($a, $b) {
            return strcasecmp($a['account_name'], $b['account_name']);
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
            $contactosMap[$ct['account_name']] = $ct;
        }
    } catch (Throwable $e) {
        $contactosMap = []; // tabela ainda não existe — ignora
    }
}
?>

<?php if (!empty($clientesStats['csv_error'])): ?>
<div class="alerta-erro" style="margin-bottom: 20px;">
    <strong>Erro ao carregar dados do CSV:</strong><br>
    <?= nl2br(htmlspecialchars($clientesStats['csv_error'])) ?>
</div>
<?php endif; ?>

<div class="clientes-kpis">
    <div class="cliente-kpi">
        <div class="label">Total de Contas</div>
        <div class="valor"><?= (int)$clientesStats['total'] ?></div>
    </div>
    <div class="cliente-kpi">
        <div class="label">Clientes</div>
        <div class="valor"><?= (int)$clientesStats['customers'] ?></div>
    </div>
    <div class="cliente-kpi">
        <div class="label">Perspetivas</div>
        <div class="valor"><?= (int)$clientesStats['prospects'] ?></div>
    </div>
    <div class="cliente-kpi">
        <div class="label">Parceiros</div>
        <div class="valor"><?= (int)$clientesStats['partners'] ?></div>
    </div>
    <div class="cliente-kpi">
        <div class="label">Contas com Conta-Mãe</div>
        <div class="valor"><?= (int)$clientesStats['com_parent'] ?></div>
    </div>
</div>

  <div class="panel" style="margin-bottom:20px;">
    <form method="get">
      <input type="hidden" name="page" value="clientes">

      <div class="clientes-filtros">
        <div class="clientes-filtro">
          <label>Pesquisar</label>
          <div class="quick-search-wrap" style="max-width:none;">
            <i class="bi bi-search"></i>
            <input type="text" name="q" value="<?= htmlspecialchars($clientesFiltros['q']) ?>" placeholder="Nome da conta, conta-mãe ou tipo">
          </div>
        </div>

        <div class="clientes-filtro">
          <label>Tipo</label>
          <select name="type">
            <option value="">-- Todos --</option>
            <?php foreach ($clientesTipos as $tipo): ?>
              <option value="<?= htmlspecialchars($tipo) ?>"
                <?= $clientesFiltros['type'] === $tipo ? 'selected' : '' ?>>
                <?= htmlspecialchars(tipoPt($tipo)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="clientes-filtro">
          <label>Hierarquia</label>
          <select name="hierarquia">
            <option value="">-- Todas --</option>
            <option value="com_parent" <?= $clientesFiltros['hierarquia'] === 'com_parent' ? 'selected' : '' ?>>Só Contas-Filhas</option>
            <option value="so_pais" <?= $clientesFiltros['hierarquia'] === 'so_pais' ? 'selected' : '' ?>>Só Contas-Mãe</option>
            <option value="so_sem_parent" <?= $clientesFiltros['hierarquia'] === 'so_sem_parent' ? 'selected' : '' ?>>Sem Conta-Mãe</option>
          </select>
        </div>

        <div class="clientes-filtros-botoes">
          <button type="submit" class="btn btn-blue">Filtrar</button>
          <a href="app.php?page=clientes" class="btn btn-grey">Limpar</a>
        </div>
      </div>
    </form>
  </div>

  <div class="panel">
    <div class="panel-header-row">
        <div class="panel-header-left">
            <h4 style="margin:0;">Lista de Clientes</h4>
            <span class="panel-count-badge"><?= count($clientesRoots) ?></span>
        </div>
        <div class="panel-header-actions">
            <button type="button" class="btn btn-grey" style="padding:8px 14px; font-size:13px;" onclick="nvClientesExpandirTudo(true)">
                <i class="bi bi-arrows-expand"></i> Expandir tudo
            </button>
            <button type="button" class="btn btn-grey" style="padding:8px 14px; font-size:13px;" onclick="nvClientesExpandirTudo(false)">
                <i class="bi bi-arrows-collapse"></i> Colapsar tudo
            </button>
        </div>
    </div>

    <div class="table-responsive">
      <style>
        .cli-contactos{ display:flex; flex-wrap:wrap; gap:4px 16px; font-size:12.5px; line-height:1.5; }
        .cli-contactos span{ display:inline-flex; align-items:center; gap:5px; color:#6b7280; }
        .cli-contactos span i{ color:#9ca3af; }
        .clientes-table td.col-contactos{ white-space:normal; }
      </style>
      <?php
        // Célula de contactos/morada em linha (horizontal: ocupa largura, pouca altura)
        $cliContactosH = function (array $map, string $nome): string {
            $c = $map[$nome] ?? null;
            if (!$c) return '<span style="color:#d1d5db;">—</span>';
            $p = [];
            if (!empty($c['emails'])) $p[] = '<span><i class="bi bi-envelope"></i> ' . htmlspecialchars($c['emails']) . '</span>';
            $tel = trim(implode(', ', array_filter([$c['phones'] ?? '', $c['mobiles'] ?? ''])));
            if ($tel !== '') $p[] = '<span><i class="bi bi-telephone"></i> ' . htmlspecialchars($tel) . '</span>';
            $mor = trim(implode(', ', array_filter([$c['street'] ?? '', $c['city'] ?? '', $c['zip'] ?? '', $c['country'] ?? ''])));
            if ($mor !== '') $p[] = '<span><i class="bi bi-geo-alt"></i> ' . htmlspecialchars($mor) . '</span>';
            return $p ? '<div class="cli-contactos">' . implode('', $p) . '</div>' : '<span style="color:#d1d5db;">—</span>';
        };
      ?>
      <table class="clientes-table">
        <thead>
          <tr>
            <th style="width:26%;">Conta</th>
            <th style="width:14%;">Tipo</th>
            <th style="width:20%;">Conta-Mãe</th>
            <th style="width:40%;">Contactos / Morada</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($clientesRoots)): ?>
            <tr>
              <td colspan="4" class="clientes-empty">Nenhum Cliente encontrado.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($clientesRoots as $i => $cliente): ?>
              <?php
                $nomeConta = $cliente['account_name'];
                $filhos = $clientesChildrenMap[$nomeConta] ?? [];
                $temFilhos = count($filhos) > 0;

                $typeClass = 'tipo-other';
                if (strcasecmp($cliente['type'], 'Customer') === 0) {
                  $typeClass = 'tipo-customer';
                } elseif (strcasecmp($cliente['type'], 'Prospect') ===0) {
                  $typeClass = 'tipo-prospect';
                } elseif (stripos($cliente['type'], 'Partner') !== false) {
                  $typeClass = 'tipo-partner';
                }

                $rowId = 'cliente-parent-' . $i; ?>
         <tr class="cliente-row-parent">
          <td>
            <?php if ($temFilhos): ?>
              <button type="button" class="cliente-toggle" data-target="<?= htmlspecialchars($rowId) ?>">+</button>
            <?php else: ?>
              <span style="display:inline-block; width:32px;"></span>
            <?php endif; ?>
              <strong><?= htmlspecialchars($cliente['account_name']) ?></strong>
          </td>
          <td>
              <?php $dotCor = $typeClass==='tipo-customer' ? '#16a34a' : ($typeClass==='tipo-prospect' ? '#2563eb' : ($typeClass==='tipo-partner' ? '#ea580c' : '#9ca3af')); ?>
              <span class="tipo-badge <?= $typeClass ?>"><span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:<?= $dotCor ?>; margin-right:7px; vertical-align:middle;"></span><?= htmlspecialchars(tipoPt($cliente['type'])) ?></span>
          </td>
          <td>
            <?php if ($cliente['parent_account'] !== ''): ?>
            <?= htmlspecialchars($cliente['parent_account']) ?>
            <?php else: ?>
              <span class="conta-principal-badge">Conta Principal</span>
            <?php endif; ?>
          </td>
          <td class="col-contactos"><?= $cliContactosH($contactosMap ?? [], $cliente['account_name']) ?></td>
        </tr>

            <?php if ($temFilhos): ?>
              <?php foreach ($filhos as $filho): ?>
                <?php $childTypeClass = 'tipo-other';
                      if (strcasecmp($filho['type'], 'Customer') === 0) {
                        $childTypeClass = 'tipo-customer';
                      } elseif (strcasecmp($filho['type'], 'Prospect') === 0) {
                        $childTypeClass = 'tipo-prospect';
                      } elseif (stripos($filho['type'], 'Partner') !== false) {
                        $childTypeClass = 'tipo-partner';
                      }
                ?>
                <tr class="cliente-row-child cliente-child-group <?= htmlspecialchars($rowId) ?>" style="display:none;">
                  <td class="cliente-child-name"><?= htmlspecialchars($filho['account_name']) ?></td>
                  <td>
                    <?php $dotCorF = $childTypeClass==='tipo-customer' ? '#16a34a' : ($childTypeClass==='tipo-prospect' ? '#2563eb' : ($childTypeClass==='tipo-partner' ? '#ea580c' : '#9ca3af')); ?>
                    <span class="tipo-badge <?= $childTypeClass ?>"><span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:<?= $dotCorF ?>; margin-right:7px; vertical-align:middle;"></span><?= htmlspecialchars(tipoPt($filho['type'])) ?></span>
                  </td>
                  <td>
                    <?php if ($filho['parent_account'] !== ''): ?>
                      <?= htmlspecialchars($filho['parent_account']) ?>
                    <?php else: ?>
                      <span class="conta-principal-badge">Conta Principal</span>
                    <?php endif; ?>
                  </td>
                  <td class="col-contactos"><?= $cliContactosH($contactosMap ?? [], $filho['account_name']) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
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


