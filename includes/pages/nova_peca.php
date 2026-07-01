<?php

$nvVoltarDestino = $_GET['voltar'] ?? ($_POST['voltar'] ?? '');
if (!str_starts_with($nvVoltarDestino, 'app.php?page=inventario')) {
    $nvVoltarDestino = 'app.php?page=inventario';
}

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$pecaEdit = null;

if ($page === 'nova_peca' && $editId > 0) {
    $stmt = $pdo->prepare("SELECT p.*, c.account_name AS cliente_nome FROM pecas p LEFT JOIN clientes c ON c.id = p.cliente_id WHERE p.id = ?");
    $stmt->execute([$editId]);
    $pecaEdit = $stmt->fetch();

    if (!$pecaEdit) {
      $_SESSION['mensagem_erro'] = 'Peça não encontrada.';
      header('Location: ' . $nvVoltarDestino);
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
$valorCliente = $formNovaPeca['cliente_instalacao'] ?? ($pecaEdit['cliente_nome'] ?? '');

// PAT associado a esta peça: não existe FK direta peca->pat na BD, a
// ligação existente é feita por Número de Série em pats_componentes
// (tabela usada na Folha de Obra / Componentes Trocados de cada PAT).
// Aqui vamos buscar o PAT mais recente que tenha tocado neste SN.
$patAssociado = null;
if ($pecaEdit && !empty($pecaEdit['sn'])) {
    $stmtPatAssoc = $pdo->prepare("
        SELECT p.id, p.numero_pat, p.revisao, p.estado
        FROM pats_componentes pc
        JOIN pats p ON p.id = pc.pat_id
        WHERE pc.sn_removido = ? OR pc.sn_colocado = ?
        ORDER BY p.created_at DESC
        LIMIT 1
    ");
    $stmtPatAssoc->execute([$pecaEdit['sn'], $pecaEdit['sn']]);
    $patAssociado = $stmtPatAssoc->fetch();
}

// ============================================================
// 7. PROCESSAMENTO POST: CRIAR / EDITAR PEÇA
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'nova_peca') {
    $editId = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;

    $categoria = trim($_POST['categoria'] ?? '');
    $produto = trim($_POST['produto'] ?? '');
    $sn = trim($_POST['sn'] ?? '');
    $cod_barras = $sn; // Código de Barras deixou de ser editável: é sempre igual ao Número de Série
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

        // Nome do cliente ANTES da alteração (para o histórico legar de
        // forma legível; $pecaAntes só tem o cliente_id, não o nome).
        $clienteNomeAntes = '';
        if (!empty($pecaAntes['cliente_id'])) {
            $stCliAntes = $pdo->prepare("SELECT account_name FROM clientes WHERE id = ?");
            $stCliAntes->execute([$pecaAntes['cliente_id']]);
            $clienteNomeAntes = (string)$stCliAntes->fetchColumn();
        }

        $estadoMudou = ((string)($pecaAntes['estado'] ?? '') !== (string)$estado);
        $sqlUpd = "UPDATE pecas SET categoria = ?, produto = ?, sn = ?, cod_barras = ?, parceiro = ?, estado = ?, cliente_id = ?, cliente_pendente = ?"
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
            $clienteId,
            $clientePendente,
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

        // Cliente onde a peça foi instalada: registado à parte porque o
        // valor relevante para histórico é o NOME do cliente (legível),
        // não o cliente_id (número interno) que é o que está guardado
        // diretamente na tabela pecas.
        if ($clienteNomeAntes !== $clienteNome) {
            $stmtHistorico->execute([
                $editId,
                'cliente',
                $clienteNomeAntes,
                $clienteNome,
                $utilizador
            ]);
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
        if ($clienteNome !== '') {
            $stmtHistorico->execute([$novoId, 'cliente', '', $clienteNome, $utilizador]);
        }
    }
    
    if ($editId > 0) {
      $_SESSION['mensagem_sucesso'] = 'Peça atualizada com sucesso.';
    } else {
      $_SESSION['mensagem_sucesso'] = 'Peça criada com sucesso.';
    }

    header('Location: ' . $nvVoltarDestino);
    exit;
  }
?>

<style>
.np-form{ max-width:900px; margin:0 auto; background:transparent; box-shadow:none; padding:0; }
.np-layout{ display:grid; grid-template-columns:1.4fr 1fr; gap:20px; align-items:start; }
.np-col{ display:flex; flex-direction:column; gap:20px; }
.np-section{ display:flex; flex-direction:column; }
.np-section-head{ display:flex; align-items:center; gap:10px; margin-bottom:10px; }
.np-section-num{ width:24px; height:24px; border-radius:50%; background:#cba35c; color:#fff; font-size:12px; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.np-section-title{ font-size:15px; font-weight:700; color:#1f2937; margin:0; }
.np-section-body{ background:#fff; border:1px solid #e5e9ef; border-radius:12px; padding:20px 22px; box-shadow:0 2px 10px rgba(0,0,0,.04); flex:1; box-sizing:border-box; }
.np-section-body .form-grid{ margin:0; gap:18px 20px; }
.np-section-body .form-grid > div + div{ margin-top:0; }
.np-full{ margin-top:18px; }
.np-col-right .np-section-body{ display:flex; flex-direction:column; gap:18px; justify-content:flex-start; }
.np-col-right .form-grid{ display:flex; flex-direction:column; gap:18px; }
.np-mini-action{ display:flex; align-items:center; justify-content:space-between; gap:10px; margin-top:18px; padding-top:16px; border-top:1px solid #eef1f5; }
.np-mini-action-label{ font-size:13px; font-weight:600; color:#374151; }
.np-mini-btn{ display:inline-flex; align-items:center; gap:6px; padding:7px 13px; font-size:12.5px; font-weight:600; border-radius:8px; background:#f3f4f6; color:#374151; text-decoration:none; border:1px solid #e5e9ef; white-space:nowrap; }
.np-mini-btn:hover{ background:#eef1f5; border-color:#cba35c; color:#92400e; }
.np-pat-rows{ display:flex; flex-direction:column; gap:14px; }
.np-pat-row{ display:flex; flex-direction:column; gap:3px; }
.np-pat-row .np-pat-label{ font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#9ca3af; }
.np-pat-row .np-pat-val{ font-size:14.5px; font-weight:600; color:#1f2937; }
.np-pat-empty{ color:#9ca3af; font-size:13.5px; text-align:center; padding:10px 0; }
.np-actions{ display:flex; justify-content:space-between; align-items:center; gap:10px; margin-top:10px; padding-right:48px; }
body.dark-mode .np-section-body{ background:#1f2937; border-color:#374151; }
body.dark-mode .np-section-title{ color:#f3f4f6; }
body.dark-mode .np-mini-action{ border-color:#374151; }
body.dark-mode .np-mini-action-label,
body.dark-mode .np-pat-row .np-pat-val{ color:#e5e7eb; }
body.dark-mode .np-mini-btn{ background:#374151; border-color:#4b5563; color:#e5e7eb; }
@media (max-width:880px){
  .np-layout{ grid-template-columns:1fr; }
}
@media (max-width:560px){
  .np-section-body{ padding:16px; }
  .np-actions{ padding-right:0; flex-direction:column-reverse; }
  .np-actions .btn{ width:100%; text-align:center; }
}
</style>

  <form method="post" class="np-form">
    <input type="hidden" name="form_type" value="nova_peca">
    <input type="hidden" name="voltar" value="<?= htmlspecialchars($nvVoltarDestino) ?>">
    <?php if ($pecaEdit): ?>
      <input type="hidden" name="edit_id" value="<?= (int)$pecaEdit['id'] ?>">
    <?php endif; ?>

    <div class="np-layout">
      <div class="np-col np-col-left">

        <!-- Cartão 1: Identificação -->
        <div class="np-section">
          <div class="np-section-head">
            <span class="np-section-num">1</span>
            <h3 class="np-section-title">Identificação</h3>
          </div>
          <div class="np-section-body">
            <div class="form-grid">
              <div>
                <label for="categoria">Categoria:*</label>
                <select name="categoria" id="categoria" required>
                  <option value="">-- Selecione a categoria --</option>
                  <?php foreach ($categorias as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>" <?= ($valorCategoria === $cat) ? 'selected' : '' ?>>
                      <?= htmlspecialchars($cat) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div>
                <label for="produto">Nome do Produto:*</label>
                <select name="produto" id="produto" required>
                  <option value="">-- Selecione o produto --</option>
                </select>
              </div>
            </div>

            <div class="np-full">
              <label for="sn">Número de Série (S_Number):*</label>
              <input type="text" name="sn" id="sn" value="<?= htmlspecialchars($valorSn) ?>" required>
            </div>
          </div>
        </div>

        <!-- Cartão 3: Instalação -->
        <div class="np-section" id="secaoInstalacao" style="display:none">
          <div class="np-section-head">
            <span class="np-section-num">3</span>
            <h3 class="np-section-title">Instalação</h3>
          </div>
          <div class="np-section-body">
            <label for="cliente_instalacao">Cliente onde foi instalada</label>
            <input type="text" name="cliente_instalacao" id="cliente_instalacao" list="clientesList" placeholder="Pesquisar cliente..." value="<?= htmlspecialchars($valorCliente) ?>">
            <datalist id="clientesList">
                <?php foreach ($pdo->query("SELECT account_name FROM clientes ORDER BY account_name") as $c): ?>
                <option value="<?= e($c['account_name']) ?>"><?php endforeach; ?>
            </datalist>
          </div>
        </div>

      </div>

      <div class="np-col np-col-right">

        <!-- Cartão 2: Estado e Parceiro -->
        <div class="np-section">
          <div class="np-section-head">
            <span class="np-section-num">2</span>
            <h3 class="np-section-title">Estado e Parceiro</h3>
          </div>
          <div class="np-section-body">
            <div class="form-grid">
              <div>
                <label for="parceiro">Parceiro:*</label>
                <select name="parceiro" id="parceiro" required>
                  <option value="">-- Selecione o parceiro --</option>
                  <?php
                  $parceiroEncontrado = false;
                  foreach ($parceiros as $parceiro):
                      if ($valorParceiro === $parceiro) $parceiroEncontrado = true;
                  ?>
                    <option value="<?= htmlspecialchars($parceiro) ?>" <?= ($valorParceiro === $parceiro) ? 'selected' : '' ?>>
                      <?= htmlspecialchars($parceiro) ?>
                    </option>
                  <?php endforeach; ?>
                  <?php if ($valorParceiro !== '' && !$parceiroEncontrado): ?>
                    <option value="<?= htmlspecialchars($valorParceiro) ?>" selected>
                      <?= htmlspecialchars($valorParceiro) ?> (fora da lista atual)
                    </option>
                  <?php endif; ?>
                </select>
              </div>

              <div>
                <label for="estado">Estado:*</label>
                <select name="estado" id="estado" required>
                  <option value="">-- Selecione o estado --</option>
                  <?php
                  $estadoEncontrado = false;
                  foreach ($estados as $estado):
                      if ($valorEstado === $estado) $estadoEncontrado = true;
                  ?>
                    <option value="<?= htmlspecialchars($estado) ?>" <?= ($valorEstado === $estado) ? 'selected' : '' ?>>
                      <?= htmlspecialchars($estado) ?>
                    </option>
                  <?php endforeach; ?>
                  <?php if ($valorEstado !== '' && !$estadoEncontrado): ?>
                    <option value="<?= htmlspecialchars($valorEstado) ?>" selected>
                      <?= htmlspecialchars($valorEstado) ?> (fora da lista atual)
                    </option>
                  <?php endif; ?>
                </select>
              </div>
            </div>

            <?php if ($patAssociado): ?>
            <div class="np-mini-action">
              <span class="np-mini-action-label">Relatório de Intervenção</span>
              <a class="np-mini-btn" href="workorder.php?id=<?= (int)$patAssociado['id'] ?>" target="_blank">
                <i class="bi bi-file-earmark-text"></i> Abrir
              </a>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Cartão 4: PAT -->
        <div class="np-section">
          <div class="np-section-head">
            <span class="np-section-num">4</span>
            <h3 class="np-section-title">PAT</h3>
          </div>
          <div class="np-section-body">
            <?php if ($patAssociado): ?>
              <div class="np-pat-rows">
                <div class="np-pat-row">
                  <span class="np-pat-label">Nº de PAT</span>
                  <span class="np-pat-val"><?= htmlspecialchars($patAssociado['numero_pat']) ?>/<?= (int)$patAssociado['revisao'] ?></span>
                </div>
                <div class="np-pat-row">
                  <span class="np-pat-label">Estado do PAT</span>
                  <span class="np-pat-val"><?= htmlspecialchars($patAssociado['estado']) ?></span>
                </div>
              </div>
              <div class="np-mini-action">
                <span class="np-mini-action-label">PAT associado</span>
                <a class="np-mini-btn" href="app.php?page=pats&ver=<?= (int)$patAssociado['id'] ?>">
                  <i class="bi bi-headset"></i> Aceder
                </a>
              </div>
            <?php else: ?>
              <div class="np-pat-empty">Sem PAT associado a esta peça.</div>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>

    <script>
        const selEstado = document.getElementById('estado');
        function toggleCliente(){
            document.getElementById('secaoInstalacao').style.display =
                (selEstado.value === 'Cliente') ? 'block' : 'none';
        }
        selEstado && selEstado.addEventListener('change', toggleCliente); toggleCliente();
    </script>

    <!-- Código de Barras deixou de ser um campo visível/editável: é sempre
         sincronizado automaticamente com o Número de Série via JS e
         enviado como campo oculto, mas o backend também garante isto
         de forma independente (ver $cod_barras = $sn; acima). -->
    <input type="hidden" name="cod_barras" id="cod_barras" value="<?= htmlspecialchars($valorSn) ?>">
    <script>
      (function(){
        const snInput = document.getElementById('sn');
        const codBarrasInput = document.getElementById('cod_barras');
        if (!snInput || !codBarrasInput) return;
        function sincronizar(){ codBarrasInput.value = snInput.value; }
        snInput.addEventListener('input', sincronizar);
        sincronizar();
      })();
    </script>

    <div class="np-actions">
      <a class="btn btn-yellow" href="<?= htmlspecialchars($nvVoltarDestino) ?>" onclick="nvVoltar(event)">← Voltar à lista</a>
      <button class="btn btn-blue" type="submit"><?= $pecaEdit
          ? "Guardar alterações"
          : "Guardar" ?></button>
    </div>
  </form>



