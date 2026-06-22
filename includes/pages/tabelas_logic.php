<?php
// TABELAS DE GESTÃO
// (categorias, estados, parceiros, fabricantes, produtos)
// ============================================================

// ---- Handlers POST: guardar / eliminar ----
// Helpers de validação das tabelas de gestão
function tabNomeDuplicado(PDO $pdo, string $tabela, string $coluna, string $valor, int $excluirId = 0, ?int $catId = -1): bool {
    $sql = "SELECT COUNT(*) FROM `$tabela` WHERE `$coluna` = ?";
    $params = [$valor];
    if ($catId !== -1) { $sql .= " AND categoria_id <=> ?"; $params[] = $catId; }
    if ($excluirId > 0) { $sql .= " AND id <> ?"; $params[] = $excluirId; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}
function tabContar(PDO $pdo, string $sql, array $params): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}
function tabValor(PDO $pdo, string $sql, array $params): string {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (string)$stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ft = $_POST['form_type'] ?? '';

    // Apenas administradores podem gerir categorias, estados, fabricantes, produtos e parceiros
    $acoesDeGestao = [
        'guardar_categoria', 'eliminar_categoria',
        'guardar_estado', 'eliminar_estado',
        'guardar_produto', 'eliminar_produto',
        'guardar_parceiro', 'eliminar_parceiro',
    ];
    if (in_array($ft, $acoesDeGestao, true)) {
        exigirAdmin(); // bloqueia e redireciona se não for admin
    }

    // ----- CATEGORIAS -----
    if ($ft === 'guardar_categoria') {
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        if ($nome === '') {
            flashError('O nome da categoria é obrigatório.');
            redirectTo('app.php?page=categorias&' . ($id ? "edit=$id" : 'nova=1'));
        }
        if (tabNomeDuplicado($pdo, 'categorias', 'nome', $nome, $id)) {
            flashError('Já existe uma categoria com esse nome.');
            redirectTo('app.php?page=categorias&' . ($id ? "edit=$id" : 'nova=1'));
        }
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE categorias SET nome = ? WHERE id = ?");
            $stmt->execute([$nome, $id]);
            flashSuccess('Categoria atualizada com sucesso.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO categorias (nome) VALUES (?)");
            $stmt->execute([$nome]);
            flashSuccess('Categoria criada com sucesso.');
        }
        redirectTo('app.php?page=categorias');
    }
    if ($ft === 'eliminar_categoria') {
        $id = (int)($_POST['id'] ?? 0);
        $nomeCat = tabValor($pdo, "SELECT nome FROM categorias WHERE id = ?", [$id]);
        $emUso = tabContar($pdo, "SELECT COUNT(*) FROM pecas WHERE categoria = ?", [$nomeCat])
               + tabContar($pdo, "SELECT COUNT(*) FROM produtos WHERE categoria_id = ?", [$id]);
        if ($emUso > 0) {
            flashError('Não é possível eliminar esta categoria: está a ser usada em peças ou produtos.');
            redirectTo('app.php?page=categorias');
        }
        $stmt = $pdo->prepare("DELETE FROM categorias WHERE id = ?");
        $stmt->execute([$id]);
        flashSuccess('Categoria eliminada com sucesso.');
        redirectTo('app.php?page=categorias');
    }

    // ----- ESTADOS -----
    if ($ft === 'guardar_estado') {
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        if ($nome === '') {
            flashError('O nome do estado é obrigatório.');
            redirectTo('app.php?page=estados&' . ($id ? "edit=$id" : 'nova=1'));
        }
        if (tabNomeDuplicado($pdo, 'estados', 'nome', $nome, $id)) {
            flashError('Já existe um estado com esse nome.');
            redirectTo('app.php?page=estados&' . ($id ? "edit=$id" : 'nova=1'));
        }
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE estados SET nome = ?, descricao = ? WHERE id = ?");
            $stmt->execute([$nome, ($descricao !== '' ? $descricao : null), $id]);
            flashSuccess('Estado atualizado com sucesso.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO estados (nome, descricao) VALUES (?, ?)");
            $stmt->execute([$nome, ($descricao !== '' ? $descricao : null)]);
            flashSuccess('Estado criado com sucesso.');
        }
        redirectTo('app.php?page=estados');
    }
    if ($ft === 'eliminar_estado') {
        $id = (int)($_POST['id'] ?? 0);
        $nomeEst = tabValor($pdo, "SELECT nome FROM estados WHERE id = ?", [$id]);
        if (tabContar($pdo, "SELECT COUNT(*) FROM pecas WHERE estado = ?", [$nomeEst]) > 0) {
            flashError('Não é possível eliminar este estado: está a ser usado em peças.');
            redirectTo('app.php?page=estados');
        }
        $stmt = $pdo->prepare("DELETE FROM estados WHERE id = ?");
        $stmt->execute([$id]);
        flashSuccess('Estado eliminado com sucesso.');
        redirectTo('app.php?page=estados');
    }

    // ----- PRODUTOS -----
    if ($ft === 'guardar_produto') {
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        $catId = (int)($_POST['categoria_id'] ?? 0) ?: null;
        if ($nome === '') {
            flashError('O nome do produto é obrigatório.');
            redirectTo('app.php?page=produtos&' . ($id ? "edit=$id" : 'nova=1'));
        }
        if (tabNomeDuplicado($pdo, 'produtos', 'nome', $nome, $id, $catId)) {
            flashError('Já existe um produto com esse nome nessa categoria.');
            redirectTo('app.php?page=produtos&' . ($id ? "edit=$id" : 'nova=1'));
        }
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE produtos SET nome = ?, categoria_id = ? WHERE id = ?");
            $stmt->execute([$nome, $catId, $id]);
            flashSuccess('Produto atualizado com sucesso.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO produtos (nome, categoria_id) VALUES (?, ?)");
            $stmt->execute([$nome, $catId]);
            flashSuccess('Produto criado com sucesso.');
        }
        redirectTo('app.php?page=produtos');
    }
    if ($ft === 'eliminar_produto') {
        $id = (int)($_POST['id'] ?? 0);
        $nomeProd = tabValor($pdo, "SELECT nome FROM produtos WHERE id = ?", [$id]);
        if (tabContar($pdo, "SELECT COUNT(*) FROM pecas WHERE produto = ?", [$nomeProd]) > 0) {
            flashError('Não é possível eliminar este produto: está a ser usado em peças.');
            redirectTo('app.php?page=produtos');
        }
        $stmt = $pdo->prepare("DELETE FROM produtos WHERE id = ?");
        $stmt->execute([$id]);
        flashSuccess('Produto eliminado com sucesso.');
        redirectTo('app.php?page=produtos');
    }

    // ----- PARCEIROS -----
    if ($ft === 'guardar_parceiro') {
        $id = (int)($_POST['id'] ?? 0);
        $empresa = trim($_POST['empresa'] ?? '');
        $campos = [
            'morada'            => trim($_POST['morada'] ?? ''),
            'contato1_nome'     => trim($_POST['contato1_nome'] ?? ''),
            'contato1_email'    => trim($_POST['contato1_email'] ?? ''),
            'contato1_telefone' => trim($_POST['contato1_telefone'] ?? ''),
            'contato2_nome'     => trim($_POST['contato2_nome'] ?? ''),
            'contato2_email'    => trim($_POST['contato2_email'] ?? ''),
            'contato2_telefone' => trim($_POST['contato2_telefone'] ?? ''),
        ];
        if ($empresa === '') {
            flashError('O nome da empresa é obrigatório.');
            redirectTo('app.php?page=parceiros&' . ($id ? "edit=$id" : 'nova=1'));
        }
        if (tabNomeDuplicado($pdo, 'parceiros', 'empresa', $empresa, $id)) {
            flashError('Já existe um parceiro com esse nome de empresa.');
            redirectTo('app.php?page=parceiros&' . ($id ? "edit=$id" : 'nova=1'));
        }
        $vals = array_map(fn($v) => ($v !== '' ? $v : null), array_values($campos));
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE parceiros SET empresa = ?, morada = ?, contato1_nome = ?, contato1_email = ?, contato1_telefone = ?, contato2_nome = ?, contato2_email = ?, contato2_telefone = ? WHERE id = ?");
            $stmt->execute(array_merge([$empresa], $vals, [$id]));
            flashSuccess('Parceiro atualizado com sucesso.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO parceiros (empresa, morada, contato1_nome, contato1_email, contato1_telefone, contato2_nome, contato2_email, contato2_telefone) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute(array_merge([$empresa], $vals));
            flashSuccess('Parceiro criado com sucesso.');
        }
        redirectTo('app.php?page=parceiros');
    }
    if ($ft === 'eliminar_parceiro') {
        $id = (int)($_POST['id'] ?? 0);
        $nomeParc = tabValor($pdo, "SELECT empresa FROM parceiros WHERE id = ?", [$id]);
        if (tabContar($pdo, "SELECT COUNT(*) FROM pecas WHERE parceiro = ?", [$nomeParc]) > 0) {
            flashError('Não é possível eliminar este parceiro: está a ser usado em peças.');
            redirectTo('app.php?page=parceiros');
        }
        $stmt = $pdo->prepare("DELETE FROM parceiros WHERE id = ?");
        $stmt->execute([$id]);
        flashSuccess('Parceiro eliminado com sucesso.');
        redirectTo('app.php?page=parceiros');
    }
}

// ---- Leitura de dados (lista + paginação + edição) ----
$tabPerPage  = 10;
$tabPag      = max(1, (int)($_GET['p'] ?? 1));
$tabOffset   = ($tabPag - 1) * $tabPerPage;
$tabListas   = [];
$tabPaginas  = 1;
$tabEdit     = null;
$parceiroVer = null;
$listaCategorias  = [];

function carregarTabela(PDO $pdo, string $sqlBase, int $perPage, int $offset, int &$paginas): array
{
    $total = (int)$pdo->query("SELECT COUNT(*) FROM ($sqlBase) t")->fetchColumn();
    $paginas = (int)ceil($total / $perPage);
    if ($paginas < 1) $paginas = 1;
    $stmt = $pdo->prepare("$sqlBase LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

if ($page === 'categorias') {
    // Lista completa numa única página (sem paginação)
    $tabListas = carregarTabela($pdo, "SELECT * FROM categorias ORDER BY nome ASC", 1000000, 0, $tabPaginas);
    if (($_GET['edit'] ?? '') !== '') {
        $stmt = $pdo->prepare("SELECT * FROM categorias WHERE id = ?");
        $stmt->execute([(int)$_GET['edit']]);
        $tabEdit = $stmt->fetch() ?: null;
    }
}
if ($page === 'estados') {
    // Lista completa numa única página (sem paginação)
    $tabListas = carregarTabela($pdo, "SELECT * FROM estados ORDER BY nome ASC", 1000000, 0, $tabPaginas);
    if (($_GET['edit'] ?? '') !== '') {
        $stmt = $pdo->prepare("SELECT * FROM estados WHERE id = ?");
        $stmt->execute([(int)$_GET['edit']]);
        $tabEdit = $stmt->fetch() ?: null;
    }
}
if ($page === 'produtos') {
    $tabListas = carregarTabela(
        $pdo,
        "SELECT p.*, c.nome AS categoria_nome
           FROM produtos p
           LEFT JOIN categorias c ON c.id = p.categoria_id
          ORDER BY p.nome ASC",
        $tabPerPage, $tabOffset, $tabPaginas
    );
    $listaCategorias  = $pdo->query("SELECT id, nome FROM categorias ORDER BY nome ASC")->fetchAll();
    if (($_GET['edit'] ?? '') !== '') {
        $stmt = $pdo->prepare("SELECT * FROM produtos WHERE id = ?");
        $stmt->execute([(int)$_GET['edit']]);
        $tabEdit = $stmt->fetch() ?: null;
    }
}
if ($page === 'parceiros') {
    $tabListas = carregarTabela($pdo, "SELECT * FROM parceiros ORDER BY empresa ASC", $tabPerPage, $tabOffset, $tabPaginas);
    if (($_GET['edit'] ?? '') !== '') {
        $stmt = $pdo->prepare("SELECT * FROM parceiros WHERE id = ?");
        $stmt->execute([(int)$_GET['edit']]);
        $tabEdit = $stmt->fetch() ?: null;
    }
    if (($_GET['ver'] ?? '') !== '') {
        $stmt = $pdo->prepare("SELECT * FROM parceiros WHERE id = ?");
        $stmt->execute([(int)$_GET['ver']]);
        $parceiroVer = $stmt->fetch() ?: null;
    }
}

// Pager numerado reutilizável (estilo das capturas)
function paginacaoTabela(string $pageName, int $totalPaginas, int $atual, string $extra = ''): void
{
    if ($totalPaginas <= 1) return;
    echo '<div style="display:flex;gap:6px;margin-top:18px;">';
    for ($i = 1; $i <= $totalPaginas; $i++) {
        $cls = $i === $atual ? 'btn btn-blue' : 'btn btn-grey';
        echo '<a class="' . $cls . '" href="app.php?page=' . $pageName . '&p=' . $i . $extra . '">' . $i . '</a>';
    }
    echo '</div>';
}
