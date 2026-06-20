<?php

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$pecaEdit = null;

if ($page === 'nova_peca' && $editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM pecas WHERE id = ?");
    $stmt->execute([$editId]);
    $pecaEdit = $stmt->fetch();

    if (!$pecaEdit) {
      $_SESSION['mensagem_erro'] = 'Peça não encontrada.';
      header('Location: app.php?page=inventario');
      exit;
    }
}


// ============================================================
// 6. VALORES TEMPORÁRIOS DOS FORMULÁRIOS
// ============================================================

$formNovaPeca = $_SESSION['form_nova_peca'] ?? [];
unset($_SESSION['form_nova_peca']);

$valorCategoria = $formNovaPeca['categoria'] ?? ($pecaEdit['categoria'] ?? '');
$valorProduto = $formNovaPeca['produto'] ?? ($pecaEdit['produto'] ?? '');
$valorParceiro = $formNovaPeca['parceiro'] ?? ($pecaEdit['parceiro'] ?? '');
$valorEstado = $formNovaPeca['estado'] ?? ($pecaEdit['estado'] ?? '');
$valorSn = $_GET['sn'] ?? ($formNovaPeca['sn'] ?? ($pecaEdit['sn'] ?? ''));
$valorCodBarras = $_GET['cod_barras'] ?? ($formNovaPeca['cod_barras'] ?? ($pecaEdit['cod_barras'] ?? ''));

// ============================================================
// 7. PROCESSAMENTO POST: CRIAR / EDITAR PEÇA
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'nova_peca') {
    $editId = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;

    $categoria = trim($_POST['categoria'] ?? '');
    $produto = trim($_POST['produto'] ?? '');
    $sn = trim($_POST['sn'] ?? '');
    $cod_barras = trim($_POST['cod_barras'] ?? '');
    $parceiro = trim($_POST['parceiro'] ?? '');
    $estado = trim($_POST['estado'] ?? '');
    $clienteNome = trim($_POST['cliente_instalacao'] ?? '');
    $clienteId = null; $clientePendente = 0;
    if ($estado === 'Cliente') {
        if ($clienteNome !== '') {
            $clienteId = nvObterOuCriarCliente($pdo, $clienteNome);
        } else {
            $clientePendente = 1; // permite gravar como "por identificar"
        }
    }
    // Reforça o fluxo apenas para utilizadores não-admin (o admin pode corrigir tudo)
    if ($editId > 0 && isset($pecaAntes) && ($_SESSION['user_role'] ?? '') !== 'admin') {
        if (!nvTransicaoValida((string)$pecaAntes['estado'], $estado)) {
            $_SESSION['mensagem_erro'] = 'Transição de estado não permitida: '
                    . e($pecaAntes['estado']) . ' → ' . e($estado);
            $_SESSION['form_nova_peca'] = $_POST;
            header('Location: app.php?page=nova_peca&edit=' . $editId);
            exit;
        }
    }
    if ($parceiro !== '' && !in_array($parceiro, $parceiros, true)) {
      $_SESSION['mensagem_erro'] = 'O parceiro selecionado não é válido.';
      $_SESSION['form_nova_peca'] = $_POST;
      header('Location: app.php?page=nova_peca' . ($editId > 0 ? '&edit=' . $editId : ''));
      exit;
    }
      if (!in_array($estado, $estados, true)){
        $_SESSION['mensagem_erro'] = 'O estado selecionado não é válido.';
        $_SESSION['form_nova_peca'] = $_POST;
        header('Location: app.php?page=nova_peca' . ($editId > 0 ? '&edit=' . $editId : ''));
        exit;
      }

    if (
        $categoria === '' ||
        !isset($catalogoProdutos[$categoria]) ||
        $produto === '' ||
        !in_array($produto, $catalogoProdutos[$categoria], true)
    ) {
        $_SESSION['mensagem_erro'] = 'A categoria e o produto selecionados não são válidos.';
        $_SESSION['form_nova_peca'] = $_POST;
         header('Location: app.php?page=nova_peca' . ($editId > 0 ? '&edit=' . $editId : ''));
          exit;
    }

    if ($editId > 0) {
        $stmtCheck = $pdo->prepare("SELECT id FROM pecas WHERE sn = ? AND id != ?");
        $stmtCheck->execute([$sn, $editId]);

        if ($sn !== '' && $stmtCheck->fetch()) {
            $_SESSION['mensagem_erro'] = 'Já existe uma peça registada com esse número de série.';
            $_SESSION['form_nova_peca'] = $_POST;
              header('Location: app.php?page=nova_peca' . ($editId > 0 ? '&edit=' . $editId : ''));
            exit;
        }
    } else {
        $stmtCheck = $pdo->prepare("SELECT id FROM pecas WHERE sn = ?");
        $stmtCheck->execute([$sn]);

        if ($sn !== '' && $stmtCheck->fetch()) {
            $_SESSION['mensagem_erro'] = 'Já existe uma peça registada com esse número de série.';
            $_SESSION['form_nova_peca'] = $_POST;
              header('Location: app.php?page=nova_peca');
            exit;
        }
    }

    if ($editId > 0) {
        $stmtAntes = $pdo->prepare("SELECT * FROM pecas WHERE id = ?");
        $stmtAntes->execute([$editId]);
        $pecaAntes = $stmtAntes->fetch();

        if (!$pecaAntes) {
            die('Peça não encontrada para atualizar.');
        }

        $estadoMudou = ((string)($pecaAntes['estado'] ?? '') !== (string)$estado);
        $sqlUpd = "UPDATE pecas SET categoria = ?, produto = ?, sn = ?, cod_barras = ?, parceiro = ?, estado = ?"
                . ($estadoMudou ? ", estado_desde = NOW()" : "")
                . " WHERE id = ?";
        $stmt = $pdo->prepare($sqlUpd);
        $stmt->execute([
            $categoria,
            $produto,
            $sn,
            $cod_barras,
            $parceiro,
            $estado,
            $editId
        ]);

        $camposAlterados = [
            'categoria' => $categoria,
            'produto' => $produto,
            'sn' => $sn,
            'cod_barras' => $cod_barras,
            'parceiro' => $parceiro,
            'estado' => $estado
        ];

        $utilizador = $_SESSION['user_nome'] ?? 'Sistema';

        $stmtHistorico = $pdo->prepare("
            INSERT INTO historico (peca_id, campo, antes, depois, utilizador, data_alteracao)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");

        foreach ($camposAlterados as $campo => $novoValor) {
            $valorAntigo = $pecaAntes[$campo] ?? '';

            if ((string)$valorAntigo !== (string)$novoValor) {
                $stmtHistorico->execute([
                    $editId,
                    $campo,
                    $valorAntigo,
                    $novoValor,
                    $utilizador
                ]);
            }
        }

 } else {
 $stmt = $pdo->prepare("INSERT INTO pecas (categoria, produto, sn, cod_barras, parceiro, estado, created_at, estado_desde) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
 $stmt->execute([
       $categoria,
       $produto,
       $sn,
       $cod_barras,
       $parceiro,
       $estado
        ]);

        $novoId = (int)$pdo->lastInsertId();
        $utilizador = $_SESSION['user_nome'] ?? 'Sistema';

        $stmtHistorico = $pdo->prepare("
            INSERT INTO historico (peca_id, campo, antes, depois, utilizador, data_alteracao)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");

        $stmtHistorico->execute([$novoId, 'criação', '', 'Peça criada', $utilizador]);
        $stmtHistorico->execute([$novoId, 'categoria', '', $categoria, $utilizador]);
        $stmtHistorico->execute([$novoId, 'produto', '', $produto, $utilizador]);
        $stmtHistorico->execute([$novoId, 'sn', '', $sn, $utilizador]);
        $stmtHistorico->execute([$novoId, 'cod_barras', '', $cod_barras, $utilizador]);
        $stmtHistorico->execute([$novoId, 'parceiro', '', $parceiro, $utilizador]);
        $stmtHistorico->execute([$novoId, 'estado', '', $estado, $utilizador]);
    }
    
    if ($editId > 0) {
      $_SESSION['mensagem_sucesso'] = 'Peça atualizada com sucesso.';
    } else {
      $_SESSION['mensagem_sucesso'] = 'Peça criada com sucesso.';
    }

    header('Location: app.php?page=inventario');
    exit;
  }
?>

  <form method="post" class="panel">
    <input type="hidden" name="form_type" value="nova_peca">
    <?php if ($pecaEdit): ?>
      <input type="hidden" name="edit_id" value="<?= (int)$pecaEdit['id'] ?>">
    <?php endif; ?>

    <div class="form-grid">
      <div>
        <label>Categoria:*</label>
          <label for="categoria"></label><select name="categoria" id="categoria" required>
          <option value="">-- Selecione a categoria --</option>
          <?php foreach ($categorias as $cat): ?>
            <option value="<?= htmlspecialchars($cat) ?>" <?= ($valorCategoria === $cat) ? 'selected' : '' ?>>
              <?= htmlspecialchars($cat) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Nome do Produto:*</label>
          <label for="produto"></label><select name="produto" id="produto" required>
          <option value="">-- Selecione o produto --</option>
        </select>
      </div>

      <div>
        <label>Parceiro:*</label>
          <label>
              <select name="parceiro" required>
                <option value="">-- Selecione o parceiro --</option>
                <?php foreach ($parceiros as $parceiro): ?>
                  <option value="<?= htmlspecialchars($parceiro) ?>" <?= ($valorParceiro === $parceiro) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($parceiro) ?>
                  </option>
                <?php endforeach; ?>
              </select>
          </label>
      </div>

      <div>
        <label>Estado:*</label>
          <label>
              <select name="estado" required>
                <option value="">-- Selecione o estado --</option>
                <?php foreach ($estados as $estado): ?>
                  <option value="<?= htmlspecialchars($estado) ?>" <?= ($valorEstado === $estado) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($estado) ?>
                  </option>
                <?php endforeach; ?>
              </select>
          </label>
      </div>

        <div id="campoCliente" style="display:none">
            <label>Cliente onde foi instalada</label>
            <input type="text" name="cliente_instalacao" id="cliente_instalacao" list="clientesList">
            <datalist id="clientesList">
                <?php foreach ($pdo->query("SELECT account_name FROM clientes ORDER BY account_name") as $c): ?>
                <option value="<?= e($c['account_name']) ?>"><?php endforeach; ?>
            </datalist>
        </div>
        <script>
            const selEstado = document.querySelector('[name=estado]');
            function toggleCliente(){
                document.getElementById('campoCliente').style.display =
                    (selEstado.value === 'Cliente') ? 'block' : 'none';
            }
            selEstado && selEstado.addEventListener('change', toggleCliente); toggleCliente();
        </script>

      <div>
        <label>Número de Série (S_Number):*</label>
          <label for="sn"></label><input type="text" name="sn" id="sn" value="<?= htmlspecialchars($valorSn) ?>" required>
      </div>
      
      <div>
        <label>Código de Barras:*</label>
        <div class="barcode-copy-wrap">
            <label for="cod_barras"></label><input type="text" name="cod_barras" id="cod_barras" value="<?= htmlspecialchars($valorCodBarras) ?>" required>
          <button type="button" class="btn btn-grey btn-copy-sn" id="copiarSnBtn">Copiar SN</button>
        </div>
        <div class="small-note" id="copySnFeedback" style="display:none;">SN copiado para o Código de Barras.</div>
      </div>
        
    <div style="margin-top:20px">
      <button class="btn btn-blue" type="submit"><?= $pecaEdit ? 'Atualizar' : 'Guardar' ?></button>
      <a class="btn btn-yellow" href="app.php?page=inventario" onclick="nvVoltar(event)">← Voltar à lista de peças</a>
    </div>
  </form>



