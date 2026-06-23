<?php
// ============================================================
// 8. PROCESSAMENTO POST: CRIAR / EDITAR CONTA
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'nova_conta') {
    $contaId = isset($_POST['conta_id']) ? (int)$_POST['conta_id'] : 0;
    $nome = trim($_POST['nome'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $fotografiaPath = $_POST['fotografia_atual'] ?? '';

    if ($nome === '' || $email === '' || ($contaId === 0 && $password === '')) {
        $_SESSION['mensagem_erro'] = 'Preencher todos os campos obrigatórios.';
        header('Location: app.php?page=contas' . ($contaId > 0 ? '&edit_conta=' . $contaId : ''));
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/@newvision\.pt$/i', $email)) {
        $_SESSION['mensagem_erro'] = 'O email tem de ser válido';
        header('Location: app.php?page=contas' . ($contaId > 0 ? '&edit_conta=' . $contaId : ''));
        exit;
    }
    if ($contaId > 0) {
        $stmt = $pdo->prepare("SELECT id FROM utilizadores WHERE email = ? AND id != ?");
        $stmt->execute([$email, $contaId]);
    } else {
        $stmt = $pdo->prepare("SELECT id FROM utilizadores WHERE email = ?");
        $stmt->execute([$email]);
    }

    if ($stmt->fetch()) {
      $_SESSION['mensagem_erro'] = 'Já existe uma conta associada a esse email.';
      header('Location: app.php?page=contas' . ($contaId > 0 ? '&edit_conta=' . $contaId : ''));
      exit;
    }

    if ($contaId > 0) {
      if ($password !== '') {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE utilizadores SET nome = ?, email = ?, password = ?, fotografia = ? WHERE id = ?");
        $stmt->execute([$nome, $email, $passwordHash, $fotografiaPath, $contaId]);
      } else {
        $stmt = $pdo->prepare("UPDATE utilizadores SET nome = ?, email = ?, fotografia = ? WHERE id = ?");
        $stmt->execute([$nome, $email, $fotografiaPath, $contaId]);
      }
      $_SESSION['mensagem_sucesso'] = 'Conta atualizada com sucesso.';
    } else {
      $passwordHash = password_hash($password, PASSWORD_DEFAULT);
      $stmt = $pdo->prepare("INSERT INTO utilizadores (nome, email, password, fotografia, created_at) VALUES (?, ?, ?, ?, NOW())");
      $stmt->execute([$nome, $email, $passwordHash, $fotografiaPath]);

      $_SESSION['mensagem_sucesso'] = 'Conta criada com sucesso.';
    }

  $fotoCropada = $_POST['fotografia_cropada'] ?? '';

    if ($fotoCropada !== ''){
      $uploadDir = 'uploads/';

      if (!is_dir($uploadDir)){
        mkdir($uploadDir, 0777, true);
      }

    if (preg_match('/^data:image\/jpeg;base64,/', $fotoCropada) || preg_match('/^data:image\/png;base64,/', $fotoCropada)) {
      $fotoCropada = preg_replace('/^data:image\/\w+;base64,/', '', $fotoCropada);
      $fotoCropada = base64_decode($fotoCropada);

      $fileName = 'crop_' . time() . '.jpg';
      $targetFile = $uploadDir . $fileName;

      file_put_contents($targetFile, $fotoCropada);
      $fotografiaPath = $targetFile;
    }
  }

    if (!empty($_FILES['fotografia']['name'])) {
        $uploadDir = 'uploads/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $fileName = time() . '_' . basename($_FILES['fotografia']['name']);
        $targetFile = $uploadDir . $fileName;

        if (move_uploaded_file($_FILES['fotografia']['tmp_name'], $targetFile)) {
            $fotografiaPath = $targetFile;
        }
    }

      header('Location: app.php?page=contas');
      exit;
    }

// ============================================================
// 9. PROCESSAMENTO POST: ELIMINAR CONTA
// ============================================================

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'eliminar_conta') {
    $deleteContaId = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($deleteContaId > 0) {
      $stmt = $pdo->prepare("DELETE FROM utilizadores WHERE id = ?");
      $stmt->execute([$deleteContaId]);
      $_SESSION['mensagem_sucesso'] = 'Conta eliminada com sucesso.';
    }

    header('Location: app.php?page=contas');
    exit;
  }



$contas = [];
    if ($page === 'contas') {
      $stmt = $pdo->query("SELECT id, nome, email, fotografia, created_at FROM utilizadores ORDER BY id DESC");
        $contas = $stmt->fetchAll();
      }

  $contaEdit = null;
  $editContaId = isset($_GET['edit_conta']) ? (int)$_GET['edit_conta'] : 0;

  if ($page === 'contas' && $editContaId > 0) {
    $stmt = $pdo->prepare("SELECT id, nome, email, fotografia FROM utilizadores WHERE id = ?");
      $stmt->execute([$editContaId]);
      $contaEdit = $stmt->fetch();

      if (!$contaEdit) {
        $_SESSION['mensagem_erro'] = 'Conta não encontrada.';
        header('Location: app.php?page=contas');
        exit;
      }
  }
?>


    <div class="contas-layout">

    <div class="panel">
      <h4 style="margin-bottom:18px;"><?= $contaEdit ? 'Editar Conta' : 'Criar Nova Conta' ?></h4>

      <form method="post" enctype="multipart/form-data" autocomplete="off">

        <input type="hidden" name="form_type" value="nova_conta">


        <?php if ($contaEdit): ?>
          <input type="hidden" name="conta_id" value="<?= (int)$contaEdit['id'] ?>">
          <input type="hidden" name="fotografia_atual" value="<?= htmlspecialchars($contaEdit['fotografia'] ?? '') ?>">
        <?php endif; ?>

        <div style="font-size:11px; text-transform:uppercase; letter-spacing:.05em; font-weight:700; color:#c9a14a; margin:4px 0 12px; padding-bottom:6px; border-bottom:1px solid #eef1f5;">Dados da conta</div>
        <div style="margin-bottom:14px;">
          <label>Nome</label>
            <label>
                <input type="text" name="nome" required autocomplete="off" value="<?= htmlspecialchars($contaEdit['nome'] ?? '') ?>">
            </label>
        </div>

        <div style="margin-bottom:14px;">
          <label>Email</label>
            <label>
                <input
                  type="email"
                  name="email"
                  required
                  autocomplete="off"
                  pattern=".+@newvision\.pt"
                  value="<?= htmlspecialchars($contaEdit['email'] ?? '') ?>"
                >
            </label>
        </div>

        <div style="font-size:11px; text-transform:uppercase; letter-spacing:.05em; font-weight:700; color:#c9a14a; margin:24px 0 12px; padding-bottom:6px; border-bottom:1px solid #eef1f5;">Acesso</div>
        <div style="margin-bottom:14px;">
          <label>Password<?= $contaEdit ? ' (deixar em branco para manter a atual)' : '' ?> 
            </label>
            <label>
                <input type="password" name="password" <?= $contaEdit ? '' : 'required' ?> autocomplete="new-password">
            </label>
        </div>

        <div style="font-size:11px; text-transform:uppercase; letter-spacing:.05em; font-weight:700; color:#c9a14a; margin:24px 0 12px; padding-bottom:6px; border-bottom:1px solid #eef1f5;">Fotografia</div>
        <div style="margin-bottom:18px;">
          <label>Fotografia</label>
          <label for="fotografiaInput" class="upload-pdf-box" id="fotografiaUploadBox">
              <span class="upload-pdf-icon"><i class="bi bi-camera-fill"></i></span>
              <span class="upload-pdf-text" id="fotografiaUploadText">
                  <strong>Clica para escolher uma foto</strong><br>ou arrasta a imagem para aqui
              </span>
              <span class="upload-pdf-filename" id="fotografiaUploadFilename" style="display:none;"></span>
          </label>
          <input type="file" name="fotografia" id="fotografiaInput" accept="image/*" class="upload-pdf-input">

          <input type="hidden" name="fotografia_cropada" id="fotografia_cropada">

          <div id="cropArea" style="display:none; margin-top:14px;">
            <div style="max-width:420px; border:1px solid #d6dbe1; border-radius:10px; overflow:hidden; background:#fff;">
              <img id="cropPreview" alt="Pré-visualização para recorte." style="display:block; max-width:100%;">
          </div>

          <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
            <button type="button" class="btn btn-blue" id="aplicarCropBtn">Aplicar Recorte</button>
            <button type="button" class="btn btn-grey" id="cancelarCropBtn">Cancelar</button>
        </div>
      </div>

      <?php if (!empty($contaEdit['fotografia'])): ?>
        <div style="margin-top:12px;">
          <div class="small-note">Fotografia atual</div>
          <img src="<?= htmlspecialchars($contaEdit['fotografia']) ?>" alt="Fotografia atual" 
              style="width:80px;height:80px;border-radius:10px;object-fit:cover;border:1px solid #d6dbe1;">
          </div>
      <?php endif; ?>
      </div>

      <script>
      (function () {
          const input = document.getElementById('fotografiaInput');
          const box = document.getElementById('fotografiaUploadBox');
          const texto = document.getElementById('fotografiaUploadText');
          const nomeFicheiro = document.getElementById('fotografiaUploadFilename');
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
              nomeFicheiro.innerHTML = '<i class="bi bi-image-fill"></i><span></span>';
              nomeFicheiro.querySelector('span').textContent = file.name;
          }

          input.addEventListener('change', function () {
              mostrarFicheiro(input.files && input.files[0] ? input.files[0] : null);
          });

          ['dragenter', 'dragover'].forEach(function (evt) {
              box.addEventListener(evt, function (e) {
                  e.preventDefault();
                  box.classList.add('is-dragover');
              });
          });
          ['dragleave', 'drop'].forEach(function (evt) {
              box.addEventListener(evt, function (e) {
                  e.preventDefault();
                  box.classList.remove('is-dragover');
              });
          });
          box.addEventListener('drop', function (e) {
              if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                  input.files = e.dataTransfer.files;
                  input.dispatchEvent(new Event('change'));
              }
          });
      })();
      </script>

        <button type="submit" class="btn btn-teal"><?= $contaEdit ? 'Atualizar Conta' : 'Criar Conta' ?>
        </button>
      </form>
    </div>

  <div class="panel">
    <div class="panel-header-row">
      <div class="panel-header-left">
        <h4 style="margin:0;">Contas Existentes</h4>
        <span class="panel-count-badge"><?= count($contas) ?></span>
      </div>
      <div class="panel-header-actions">
        <div class="quick-search-wrap">
          <i class="bi bi-search"></i>
          <input type="text" class="quick-search-input" data-table="#tabelaContas" data-empty="#tabelaContasVazia" placeholder="Pesquisar conta…">
        </div>
      </div>
    </div>

    <div class="table-responsive scroll-oculto">
    <table class="table" id="tabelaContas">
      <thead>
        <tr>
          <th>Conta</th>
          <th style="width:160px;">Criada em</th>
          <th class="actions" style="width:70px;">Ações</th>
        </tr>
      </thead>

  <tbody>
    <?php foreach ($contas as $c): ?>
    <tr>
      <td>
        <div style="display:flex; align-items:center; gap:10px;">
          <?php if (!empty($c['fotografia'])): ?>
            <img src="<?= htmlspecialchars($c['fotografia']) ?>" alt="Foto" style="width:38px;height:38px;border-radius:8px;object-fit:cover;flex-shrink:0;">
          <?php else: ?>
            <div style="width:38px;height:38px;border-radius:8px;background:#eef2f7;color:#9ca3af;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="bi bi-person"></i></div>
          <?php endif; ?>
          <div style="min-width:0;">
            <div style="font-weight:700; color:#1f2937;"><?= htmlspecialchars($c['nome']) ?></div>
            <div style="font-size:12px; color:#6b7280;"><?= htmlspecialchars($c['email']) ?></div>
          </div>
        </div>
      </td>
      <td style="color:#6b7280; font-size:13px;"><?= htmlspecialchars($c['created_at']) ?></td>
      <td class="actions">
        <a class="btn btn-yellow" href="app.php?page=contas&edit_conta=<?= (int)$c['id'] ?>" title="Editar" aria-label="Editar"><i class="bi bi-pencil"></i></a>
        <form method="post" style="display:inline-block;" onsubmit="return nvConfirmar(this, 'Eliminar esta conta? Esta ação é irreversível.');">
          <input type="hidden" name="form_type" value="eliminar_conta">
          <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <button type="submit" class="btn btn-red" title="Eliminar" aria-label="Eliminar"><i class="bi bi-trash3"></i></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$contas): ?><tr id="tabelaContasVazia" data-no-filter><td colspan="3" class="table-empty-state"><i class="bi bi-inbox"></i>Sem contas registadas.</td></tr><?php endif; ?>
  </tbody>
    </table>
    </div>
  </div>
  </div>



