<?php
// HANDLER: Criar / Editar PAT
// ══════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['form_type'] ?? '', ['criar_pat', 'editar_pat'])) {
    $isEdicao = ($_POST['form_type'] === 'editar_pat');
    $editId = (int)($_POST['pat_id'] ?? 0);

    $numeroPat = trim($_POST['numero_pat'] ?? '');
    $revisao = max(1, (int)($_POST['revisao'] ?? 1));
    $entidade = trim($_POST['entidade'] ?? '');
    $local = trim($_POST['local_cliente'] ?? '');
    $contacto = trim($_POST['contacto'] ?? '');
    $morada = trim($_POST['morada'] ?? '');
    // Normaliza datas: vazio -> NULL; aceita o formato "Y-m-dTH:i" do datetime-local.
    $normDt = fn($v) => ($v !== '' && strtotime($v)) ? date('Y-m-d H:i:s', strtotime($v)) : null;
    $dataRec = $normDt(trim($_POST['data_recepcao'] ?? ''));
    $dataLim = $normDt(trim($_POST['data_limite'] ?? ''));
    $garantia = isset($_POST['garantia']) ? 1 : 0;
    $contrato = isset($_POST['contrato_manutencao']) ? 1 : 0;
    $descricao = trim($_POST['descricao'] ?? '');
    $tecnico = trim($_POST['tecnico'] ?? '');
    $comentarios = trim($_POST['comentarios'] ?? '');
    $observacoes = trim($_POST['observacoes'] ?? '');

    // Se o campo tiver HTML do xdebug gravado por engano, LIMPAR
    $limparXdebug = fn(string $s): string =>
        (str_contains($s, 'xdebug-error') || str_contains($s, '<font size=')) ? '' : $s;
    $comentarios = $limparXdebug($comentarios);
    $observacoes = $limparXdebug($observacoes);
    $dataIni = $normDt(trim($_POST['data_inicio'] ?? ''));
    $dataFim = $normDt(trim($_POST['data_fim'] ?? ''));
    $tecnicos = trim($_POST['tecnicos_presentes'] ?? '');
    $prioridade = in_array($_POST['prioridade'] ?? '', ['Normal','Urgente']) ? $_POST['prioridade'] : 'Normal';
    $estado = in_array($_POST['estado'] ?? '', ['Aberto','Em Curso','Resolvido','Concluído','Cancelado']) ? $_POST['estado'] : 'Aberto';

    // Garantir UTF-8 válido em todos os campos de texto. Sob STRICT_TRANS_TABLES
    // qualquer byte inválido (ex.: dados lidos do DOM do Salesforce pela extensão)
    // faria o INSERT/UPDATE rebentar com erro 1366 (Incorrect string value).
    $utf8 = function ($s) {
        $s = (string)$s;
        return ($s === '' || mb_check_encoding($s, 'UTF-8'))
            ? $s
            : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    };
    $numeroPat   = $utf8($numeroPat);
    $entidade    = $utf8($entidade);
    $local       = $utf8($local);
    $contacto    = $utf8($contacto);
    $morada      = $utf8($morada);
    $descricao   = $utf8($descricao);
    $tecnico     = $utf8($tecnico);
    $comentarios = $utf8($comentarios);
    $observacoes = $utf8($observacoes);
    $tecnicos    = $utf8($tecnicos);

    if ($numeroPat === '') {
        $_SESSION['mensagem_erro'] = 'O número do PAT é obrigatório.';
        header('Location: app.php?page=pats' . ($isEdicao ? '&ver=' . $editId : '&acao=novo'));
        exit;
    }

    // Módulos e Componentes
    $modSolucoes = $_POST['mod_solucao'] ?? [];
    $modModelos = $_POST['mod_modelo'] ?? [];
    $modSeries = $_POST['mod_serie'] ?? [];
    $compRemovidos = $_POST['comp_removido'] ?? [];
    $comSnRem = $_POST['comp_sn_rem'] ?? [];
    $compColocados = $_POST['comp_colocado'] ?? [];
    $compSnCol = $_POST['comp_sn_col'] ?? [];
    $compQtds = $_POST['comp_qtd'] ?? [];

    $pdo->beginTransaction();
    try {
        $campos = [
            $numeroPat, $revisao, $entidade, $local, $contacto, $morada,
            $dataRec, $dataLim, $garantia, $contrato, $descricao,
            $tecnico, $comentarios, $dataIni, $dataFim, $tecnicos,
            $observacoes, $prioridade, $estado,
        ];

        if (!$isEdicao) {
            $campos[] = $_SESSION['user_nome'] ?? 'Sistema';
            $pdo->prepare("
                INSERT INTO pats
                 (numero_pat, revisao, entidade, local_cliente, contacto, morada,
                  data_recepcao, data_limite, garantia, contrato_manutencao, descricao,
                  tecnico, comentarios, data_inicio, data_fim, tecnicos_presentes,
                  observacoes, prioridade, estado, criado_por)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute($campos);
            $patId = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare("
               UPDATE pats SET
                 numero_pat=?, revisao=?, entidade=?, local_cliente=?, contacto=?, morada=?,
                 data_recepcao=?, data_limite=?, garantia=?, contrato_manutencao=?, descricao=?,
                 tecnico=?, comentarios=?, data_inicio=?, data_fim=?, tecnicos_presentes=?,
                 observacoes=?, prioridade=?, estado=?
               WHERE id=?
            ")->execute(array_merge($campos, [$editId]));
            $patId = $editId;
            $pdo->prepare("DELETE FROM pats_modulos    WHERE pat_id = ?")->execute([$patId]);
            $pdo->prepare("DELETE FROM pats_componentes WHERE pat_id = ?")->execute([$patId]);
        }

        // Reinserir módulos
        $stmtMod = $pdo->prepare("
            INSERT INTO pats_modulos (pat_id, solucao_equipamento, modelo, num_serie)
            VALUES (?, ?, ?, ?)
        ");
        foreach ($modSolucoes as $i => $sol) {
            $sol = $utf8(trim($sol));
            $mod = $utf8(trim($modModelos[$i] ?? ''));
            $ser = $utf8(trim($modSeries[$i] ?? ''));
            if ($sol === '' && $mod === '' && $ser === '') continue;
            $stmtMod->execute([$patId, $sol, $mod, $ser]);
        }

        // Reinserir componentes
        $stmtComp = $pdo->prepare("
            INSERT INTO pats_componentes (pat_id, removido, sn_removido, colocado, sn_colocado, quantidade)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($compRemovidos as $i => $rem) {
            $rem = $utf8(trim($rem));
            $snr = $utf8(trim($comSnRem[$i] ?? ''));
            $col = $utf8(trim($compColocados[$i] ?? ''));
            $snc = $utf8(trim($compSnCol[$i] ?? ''));
            $qtd = max(1, (int)($compQtds[$i] ?? 1));
            if ($rem === '' && $col === '') continue;
            $stmtComp->execute([$patId, $rem, $snr, $col, $snc, $qtd]);
        }

        $pdo->commit();
        $_SESSION['mensagem_sucesso'] = $isEdicao ? 'PAT atualizado.' : 'PAT criado com sucesso.';
        header('Location: app.php?page=pats&ver=' . $patId);
        exit;
    } catch (Throwable $e) {
        // Erro ao gravar: reverter, registar e voltar ao formulário SEM perder
        // os dados nem matar a página (antes fazia die() e parecia que o PAT
        // não era criado). A mensagem fica visível no topo da página PATs.
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Erro ao guardar PAT: ' . $e->getMessage());
        $_SESSION['mensagem_erro'] = 'Não foi possível guardar o PAT: ' . $e->getMessage();
        header('Location: app.php?page=pats' . ($isEdicao ? '&ver=' . $editId : '&acao=novo'));
        exit;
    }
}

// ══════════════════════════════════════════════
// HANDLER: Apagar PAT
// ══════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'apagar_pat') {
    $patId = (int)($_POST['pat_id'] ?? 0);
    if ($patId > 0) {
        $pdo->prepare("DELETE FROM pats WHERE id = ?")->execute([$patId]);
    }
    $_SESSION['mensagem_sucesso'] = 'PAT apagado.';
    header('Location: app.php?page=pats');
    exit;
}


$patsList = [];
$patDetalhe = null;
$patModulos = [];
$patComp = [];
$patAcao = $_GET['acao'] ?? '';
$patVerId = isset($_GET['ver']) ? (int)$_GET['ver'] : 0;
$cicloVida = [];

if ($page === 'pats') {

    //Filtros Lista
    $patFiltros = [
       'q' => trim($_GET['q']  ?? ''),
       'estado' => trim($_GET['estado'] ?? ''),
       'prioridade' => trim($_GET['prioridade'] ?? ''),
    ];

    $patWhere = [];
    $patParams = [];
    if ($patFiltros['q'] !== '') {
        $patWhere[] = '(numero_pat LIKE ? OR entidade LIKE ? OR tecnico LIKE ?)';
        $patParams[] = '%' . $patFiltros['q'] . '%';
        $patParams[] = '%' . $patFiltros['q'] . '%';
        $patParams[] = '%' . $patFiltros['q'] . '%';
    }
    if ($patFiltros['estado'] !== '') {
        $patWhere[] = 'estado = ?';
        $patParams[] = $patFiltros['estado'];
    }
    if ($patFiltros['prioridade'] !== '') {
        $patWhere[] = 'prioridade = ?';
        $patParams[] = $patFiltros['prioridade'];
    }

    $patSql = "SELECT * FROM pats"
            . ($patWhere ? ' WHERE ' . implode(' AND ', $patWhere) : '')
            . " ORDER BY created_at DESC";
    $patStmt = $pdo->prepare($patSql);
    $patStmt->execute($patParams);
    $patsList = $patStmt->fetchAll();

    // Detalhe de 1 PAT
    if ($patVerId > 0) {
        $s = $pdo->prepare("SELECT * FROM pats WHERE id = ?");
        $s->execute([$patVerId]);
        $patDetalhe = $s->fetch();
        if ($patDetalhe) {
            // Converter NULL para '' - evita erros de htmlspecialchars no PHP 8.1+
            $patDetalhe = array_map(fn($v) => is_null($v) ? '' : $v, $patDetalhe);
        }

        if ($patDetalhe) {
            $m = $pdo->prepare("SELECT * FROM pats_modulos WHERE pat_id = ? ORDER BY id");
            $m->execute([$patVerId]);
            $patModulos = $m->fetchAll();

            $sc = $pdo->prepare("SELECT * FROM pats_componentes WHERE pat_id = ? ORDER BY id");
            $sc->execute([$patVerId]);
            $patComp = $sc->fetchAll();

            // Ciclo de vida: estado atual (no inventário) de cada peça referida no PAT
            $stmtCiclo = $pdo->prepare("
                SELECT c.sn_removido, c.sn_colocado,
                       pr.estado AS estado_removido, pr.estado_desde AS desde_removido,
                       pc.estado AS estado_colocado, pc.estado_desde AS desde_colocado
                FROM pats_componentes c
                LEFT JOIN pecas pr ON pr.sn COLLATE utf8mb4_unicode_ci = c.sn_removido COLLATE utf8mb4_unicode_ci
                LEFT JOIN pecas pc ON pc.sn COLLATE utf8mb4_unicode_ci = c.sn_colocado COLLATE utf8mb4_unicode_ci
                WHERE c.pat_id = ?
            ");
            $stmtCiclo->execute([(int)$patDetalhe['id']]);
            $cicloVida = $stmtCiclo->fetchAll();
        }
    }
}

// KPIs rápidos para o topo da página
$kpiPatsTotal = countQuery($pdo, "SELECT COUNT(*) FROM pats");
$kpiPatsAbertos = countQuery($pdo, "SELECT COUNT(*) FROM pats WHERE estado='Aberto'");
$kpiPatsEmCurso = countQuery($pdo, "SELECT COUNT(*) FROM pats WHERE estado='Em Curso'");
$kpiPatsConcluidos = countQuery($pdo, "SELECT COUNT(*) FROM pats WHERE estado='Concluído'");
$kpiPatsUrgentes = countQuery($pdo, "SELECT COUNT(*) FROM pats WHERE prioridade='Urgente' AND estado NOT IN ('Resolvido','Concluído','Cancelado')");
?>

<?php if ($patVerId > 0 && $patDetalhe): ?>
    ════════════════════════════════════════════
    VISTA: DETALHE / EDIÇÃO DO PAT
    ════════════════════════════════════════════

<div style="display:flex; align-items:center; gap:12px; margin-bottom:20px; flex-wrap:wrap;">
  <a href="app.php?page=pats" class="btn btn-grey" onclick="nvVoltar(event)">← Voltar à lista</a>
  <h3 style="margin:0; font-size:17px;">
      <?= htmlspecialchars($patDetalhe['numero_pat']) ?>/<?= (int)$patDetalhe['revisao'] ?>
  </h3>
  <span style="
    padding:3px 12px; border-radius:20px; font-size:12px; font-weight:600;
    background:<?= $patDetalhe['estado']==='Aberto' ? '#dbeafe' : ($patDetalhe['estado']==='Em Curso' ? '#fef3c7' : ($patDetalhe['estado']==='Resolvido' ? '#e0e7ff' : ($patDetalhe['estado']==='Concluído' ? '#dcfce7' : '#f3f4f6'))) ?>;
    color:<?= $patDetalhe['estado']==='Aberto' ? '#1d4ed8' : ($patDetalhe['estado']==='Em Curso' ? '#92400e' : ($patDetalhe['estado']==='Resolvido' ? '#4338ca' : ($patDetalhe['estado']==='Concluído' ? '#15803d' : '#374151'))) ?>;">
    <?= htmlspecialchars($patDetalhe['estado']) ?>
  </span>
<?php if ($patDetalhe['prioridade'] === 'Urgente'): ?>
   <span style="padding:3px 12px; border-radius:20px; font-size:12px; font-weight:600; background:#fee2e2; color:#dc2626;">Urgente</span>
<?php endif; ?>
   <div style="margin-left:auto; display:flex; gap:10px;">
     <a href="workorder.php?id=<?= (int)$patDetalhe['id'] ?>" target="_blank" class="btn btn-blue">📄 Folha de Obra</a>
   </div>
</div>

<form method="post" autocomplete="off">
  <input type="hidden" name="form_type" value="editar_pat">
  <input type="hidden" name="pat_id" value="<?= (int)$patDetalhe['id'] ?>">

  <!-- Cliente -->
  <div class="panel" style="margin-bottom:18px;">
    <h4 style="margin-bottom:14px;">Cliente</h4>
     <div class="form-grid">
       <div>
         <label>Nº PAT</label>
           <label>
               <input type="text" name="numero_pat" value="<?= htmlspecialchars($patDetalhe['numero_pat']) ?>" required>
           </label>
       </div>
       <div>
         <label>Revisão</label>
           <label>
               <input type="number" name="revisao" min="1" value="<?= (int)$patDetalhe['revisao'] ?>">
           </label>
       </div>
       <div>
         <label>Entidade</label>
           <label>
               <input type="text" name="entidade" value="<?= htmlspecialchars($patDetalhe['entidade']) ?>">
           </label>
       </div>
       <div>
         <label>Local</label>
           <label>
               <input type="text" name="local_cliente" value="<?= htmlspecialchars($patDetalhe['local_cliente']) ?>">
           </label>
       </div>
       <div>
         <label>Contacto</label>
           <label>
               <input type="text" name="contacto" value="<?= htmlspecialchars($patDetalhe['contacto']) ?>">
           </label>
       </div>
       <div>
         <label>Morada</label>
           <label>
               <input type="text" name="morada" value="<?= htmlspecialchars($patDetalhe['morada']) ?>">
           </label>
       </div>
     </div>
  </div>

  <!-- Pedido -->
  <div class="panel" style="margin-bottom:18px;">
    <h4 style="margin-bottom:14px;">Pedido de Assistência</h4>
    <div class="form-grid">
      <div>
        <label>Data de Receção</label>
          <label>
              <input type="datetime-local" name="data_recepcao"
                value="<?= $patDetalhe['data_recepcao'] ? date('Y-m-d\TH:i', strtotime($patDetalhe['data_recepcao'])) : '' ?>">
          </label>
      </div>
      <div>
        <label>Data Limite</label>
          <label>
              <input type="datetime-local" name="data_limite"
                value="<?= $patDetalhe['data_limite'] ? date('Y-m-d\TH:i', strtotime($patDetalhe['data_limite'])) : '' ?>">
          </label>
      </div>
      <div style="display:flex; gap:28px; align-items:center; flex-wrap:wrap; padding-top:22px;">
        <label style="display:flex; align-items:center; gap:8px; font-weight:500; cursor:pointer;">
          <input type="checkbox" name="garantia" value="1" <?= $patDetalhe['garantia'] ? 'checked' : '' ?>>
           Ao Abrigo da Garantia
        </label>
        <label style="display:flex; align-items:center; gap:8px; font-weight:500; cursor:pointer;">
          <input type="checkbox" name="contrato_manutencao" value="1" <?= $patDetalhe['contrato_manutencao'] ? 'checked' : '' ?>>
           Ao Abrigo do Contrato de Manutenção
        </label>
      </div>
      <div style="grid-column:1/-1;">
        <label>Descrição do Pedido</label>
          <label>
              <textarea name="descricao" rows="4" style="width:100%; resize:vertical;"><?= htmlspecialchars($patDetalhe['descricao']) ?></textarea>
          </label>
      </div>
    </div>
  </div>

  <!-- NewVision -->
  <div class="panel" style="margin-bottom:18px;">
    <h4 style="margin-bottom:14px;">NewVision</h4>
    <div class="form-grid">
      <div>
        <label>Técnico Responsável</label>
          <label>
              <input type="text" name="tecnico" value="<?= htmlspecialchars($patDetalhe['tecnico']) ?>">
          </label>
      </div>
      <div>
        <label>Prioridade</label>
          <label>
              <select name="prioridade">
                  <option value="Normal"  <?= $patDetalhe['prioridade']==='Normal'  ? 'selected' : '' ?>>Normal</option>
                  <option value="Urgente" <?= $patDetalhe['prioridade']==='Urgente' ? 'selected' : '' ?>>Urgente</option>
              </select>
          </label>
      </div>
      <div>
        <label>Estado</label>
          <label>
              <select name="estado">
                <?php foreach (['Aberto','Em Curso','Resolvido','Concluído','Cancelado'] as $est): ?>
                  <option value="<?= $est ?>" <?= $patDetalhe['estado']===$est ? 'selected' : '' ?>><?= $est ?></option>
                <?php endforeach; ?>
              </select>
          </label>
      </div>
  </div>
  </div>

  <!-- Intervenção -->
  <div class="panel" style="margin-bottom:18px;">
    <h4 style="margin-bottom:14px;">Intervenção</h4>
    <div class="form-grid">
      <div>
        <label>Data / Hora Início</label>
          <label>
              <input type="datetime-local" name="data_inicio"
                 value="<?= $patDetalhe['data_inicio'] ? date('Y-m-d\TH:i', strtotime($patDetalhe['data_inicio'])) : '' ?>">
          </label>
      </div>
      <div>
        <label>Data / Hora Fim</label>
          <label>
              <input type="datetime-local" name="data_fim"
                value="<?= $patDetalhe['data_fim'] ? date('Y-m-d\TH:i', strtotime($patDetalhe['data_fim'])) : '' ?>">
          </label>
      </div>
      <div style="grid-column:1/-1;">
        <label>Técnicos Presentes</label>
          <label>
              <input type="text" name="tecnicos_presentes" value="<?= htmlspecialchars($patDetalhe['tecnicos_presentes']) ?>"
          </label>
      </div>
    </div>
  </div>

  <!-- Componentes Trocados -->
  <div class="panel" style="margin-bottom:18px;">
    <h4 style="margin-bottom:14px;">Componentes Trocados</h4>
    <div style="overflow-x:auto;">
      <table class="table" id="tabelaComponentes" style="margin-bottom:10px; min-width:700px;">
        <thead>
          <tr>
            <th>Removido</th>
            <th>Nº de Série Removido</th>
            <th>Colocado</th>
            <th>Nº de Série Colocado</th>
            <th style="width:70px;">Qtd</th>
            <th style="width:48px;"></th>
          </tr>
        </thead>
        <tbody>
          <?php
          $compParaRender = !empty($patComp) ? $pat : [['removido'=>'','sn_removido'=>'','colocado'=>'','sn_colocado'=>'','quantidade'=>1]];
          foreach ($compParaRender as $comp): ?>
            <tr>
              <td><label>
                      <input type="text" name="comp_removido[]"  value="<?= htmlspecialchars($comp['removido']) ?>"   style="width:100%;">
                  </label></td>
              <td><label>
                      <input type="text" name="comp_sn_rem[]"    value="<?= htmlspecialchars($comp['sn_removido']) ?>" style="width:100%;">
                  </label></td>
              <td><label>
                      <input type="text" name="comp_colocado[]"  value="<?= htmlspecialchars($comp['colocado']) ?>"   style="width:100%;">
                  </label></td>
              <td><label>
                      <input type="text" name="comp_sn_col[]"    value="<?= htmlspecialchars($comp['sn_colocado']) ?>" style="width:100%;">
                  </label></td>
              <td><label>
                      <input type="number" name="comp_qtd[]"     value="<?= (int)$comp['quantidade'] ?>" min="1" style="width:100%;">
                  </label></td>
              <td><button type="button" class="btn btn-red btn-remover-linha" style="padding:4px 10px;">✕</button></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <button type="button" class="btn btn-grey btn-add-comp">+ Linha</button>
  </div>

    <div class="card" style="margin-top:18px;">
        <h3>Ciclo de vida das peças</h3>
        <table class="table">
            <thead><tr>
                <th>SN removido</th><th>Estado atual</th>
                <th>SN colocado</th><th>Estado atual</th>
            </tr></thead>
            <tbody>
            <?php foreach ($cicloVida as $cv): ?>
                <tr>
                    <td><?= e($cv['sn_removido']) ?></td>
                    <td><?= $cv['sn_removido'] ? estadoBolha($cv['estado_removido'] ?? 'Desconhecido') : '—' ?></td>
                    <td><?= e($cv['sn_colocado']) ?></td>
                    <td><?= $cv['sn_colocado'] ? estadoBolha($cv['estado_colocado'] ?? 'Desconhecido') : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$cicloVida): ?>
                <tr><td colspan="4">Sem componentes registados neste PAT.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

  <!-- Observações -->
  <div class="panel" style="margin-bottom:18px;">
    <h4 style="margin-bottom:14px;">Observações</h4>
      <label>
          <textarea name="observacoes" rows="3" style="width:100%; resize:vertical;"><?= htmlspecialchars($patDetalhe['observacoes']) ?></textarea>
      </label>
  </div>

  <!-- Ações -->
    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px;">
        <button type="submit" class="btn btn-blue">💾 Guardar Alterações</button>
    </div>
</form>

        <form method="post" style="margin:0;" onsubmit="return nvConfirmar(this, 'Apagar este PAT permanentemente? Esta ação é irreversível.');">
            <input type="hidden" name="form_type" value="apagar_pat">
            <input type="hidden" name="pat_id"    value="<?= (int)$patDetalhe['id'] ?>">
            <button type="submit" class="btn btn-red">Apagar PAT</button>
        </form>

    <?php elseif ($patAcao === 'novo'): ?>
    <!-- ════════════════════════════════════════════
         VISTA: NOVO PAT
    ════════════════════════════════════════════ -->

    <div style="display:flex; align-items:center; gap:12px; margin-bottom:20px;">
        <a href="app.php?page=pats" class="btn btn-grey" onclick="nvVoltar(event)">← Voltar</a>
        <h3 style="margin:0;">Novo PAT</h3>
    </div>

    <form method="post" autocomplete="off">
        <input type="hidden" name="form_type" value="criar_pat">

        <div class="panel" style="margin-bottom:18px;">
            <h4 style="margin-bottom:14px;">Cliente</h4>
            <div class="form-grid">
                <div>
                    <label>Nº PAT <span style="color:red;">*</span></label>
                    <label>
                        <input type="text" name="numero_pat" placeholder="Ex: PAT-00102514" required>
                    </label>
                </div>
                <div>
                    <label>Revisão</label>
                    <label>
                        <input type="number" name="revisao" min="1" value="1">
                    </label>
                </div>
                <div>
                    <label>Entidade</label>
                    <label>
                        <input type="text" name="entidade" placeholder="Ex: UNILABS">
                    </label>
                </div>
                <div>
                    <label>Local</label>
                    <label>
                        <input type="text" name="local_cliente">
                    </label>
                </div>
                <div>
                    <label>Contacto</label>
                    <label>
                        <input type="text" name="contacto">
                    </label>
                </div>
                <div>
                    <label>Morada</label>
                    <label>
                        <input type="text" name="morada">
                    </label>
                </div>
            </div>
        </div>

        <div class="panel" style="margin-bottom:18px;">
            <h4 style="margin-bottom:14px;">Pedido de Assistência</h4>
            <div class="form-grid">
                <div>
                    <label>Data de Receção</label>
                    <label>
                        <input type="datetime-local" name="data_recepcao">
                    </label>
                </div>
                <div>
                    <label>Data Limite</label>
                    <label>
                        <input type="datetime-local" name="data_limite">
                    </label>
                </div>
                <div style="display:flex; gap:20px; align-items:center; padding-top:22px;">
                    <label style="display:flex; align-items:center; gap:8px; font-weight:500; cursor:pointer;">
                        <input type="checkbox" name="garantia" value="1"> Garantia
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; font-weight:500; cursor:pointer;">
                        <input type="checkbox" name="contrato_manutencao" value="1"> Contrato de Manutenção
                    </label>
                </div>
                <div style="grid-column:1/-1;">
                    <label>Descrição</label>
                    <label>
                        <textarea name="descricao" rows="4" style="width:100%; resize:vertical;"></textarea>
                    </label>
                </div>
            </div>
        </div>

        <div class="panel" style="margin-bottom:18px;">
            <h4 style="margin-bottom:14px;">NewVision</h4>
            <div class="form-grid">
                <div>
                    <label>Técnico Responsável</label>
                    <label>
                        <input type="text" name="tecnico">
                    </label>
                </div>
                <div>
                    <label>Prioridade</label>
                    <label>
                        <select name="prioridade">
                            <option value="Normal">Normal</option>
                            <option value="Urgente">Urgente</option>
                        </select>
                    </label>
                </div>
                <div>
                    <label>Estado</label>
                    <label>
                        <select name="estado">
                            <option value="Aberto">Aberto</option>
                            <option value="Em Curso">Em Curso</option>
                            <option value="Concluído">Concluído</option>
                            <option value="Cancelado">Cancelado</option>
                        </select>
                    </label>
                </div>
                <div style="grid-column:1/-1;">
                    <label>Comentários / Instruções</label>
                    <label>
                        <textarea name="comentarios" rows="3" style="width:100%; resize:vertical;"></textarea>
                    </label>
                </div>
            </div>
        </div>

        <div class="panel" style="margin-bottom:18px;">
            <h4 style="margin-bottom:14px;">Módulos para Assistência</h4>
            <table class="table" id="tabelaModulos" style="margin-bottom:10px;">
                <thead>
                <tr><th>Solução / Equipamento</th><th>Modelo</th><th>Nº de Série</th><th style="width:48px;"></th></tr>
                </thead>
                <tbody>
                <tr>
                    <td><label>
                            <input type="text" name="mod_solucao[]" style="width:100%;">
                        </label></td>
                    <td><label>
                            <input type="text" name="mod_modelo[]"  style="width:100%;">
                        </label></td>
                    <td><label>
                            <input type="text" name="mod_serie[]"   style="width:100%;">
                        </label></td>
                    <td><button type="button" class="btn btn-red btn-remover-linha" style="padding:4px 10px;">✕</button></td>
                </tr>
                </tbody>
            </table>
            <button type="button" class="btn btn-grey btn-add-modulo">+ Linha</button>
        </div>

        <div class="panel" style="margin-bottom:18px;">
            <h4 style="margin-bottom:14px;">Componentes Trocados</h4>
            <div style="overflow-x:auto;">
                <table class="table" id="tabelaComponentes" style="margin-bottom:10px; min-width:700px;">
                    <thead>
                    <tr>
                        <th>Removido</th><th>Nº Série Removido</th>
                        <th>Colocado</th><th>Nº Série Colocado</th>
                        <th style="width:70px;">Qtd</th><th style="width:48px;"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td><label>
                                <input type="text" name="comp_removido[]"  style="width:100%;">
                            </label></td>
                        <td><label>
                                <input type="text" name="comp_sn_rem[]"    style="width:100%;">
                            </label></td>
                        <td><label>
                                <input type="text" name="comp_colocado[]"  style="width:100%;">
                            </label></td>
                        <td><label>
                                <input type="text" name="comp_sn_col[]"    style="width:100%;">
                            </label></td>
                        <td><label>
                                <input type="number" name="comp_qtd[]" value="1" min="1" style="width:100%;">
                            </label></td>
                        <td><button type="button" class="btn btn-red btn-remover-linha" style="padding:4px 10px;">✕</button></td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn btn-grey btn-add-comp">+ Linha</button>
        </div>

        <div style="margin-bottom:20px;">
            <button type="submit" class="btn btn-blue">Criar PAT</button>
        </div>
    </form>

    <?php else: ?>
    <!-- ════════════════════════════════════════════
         VISTA: LISTA DE PATs
    ════════════════════════════════════════════ -->

    <!-- KPIs -->
    <div class="clientes-kpis" style="margin-bottom:20px;">
        <div class="cliente-kpi">
            <div class="label">Total</div>
            <div class="valor"><?= $kpiPatsTotal ?></div>
        </div>
        <div class="cliente-kpi">
            <div class="label">Abertos</div>
            <div class="valor" style="color:#1d4ed8;"><?= $kpiPatsAbertos ?></div>
        </div>
        <div class="cliente-kpi">
            <div class="label">Em Curso</div>
            <div class="valor" style="color:#92400e;"><?= $kpiPatsEmCurso ?></div>
        </div>
        <div class="cliente-kpi">
            <div class="label">Concluídos</div>
            <div class="valor" style="color:#15803d;"><?= $kpiPatsConcluidos ?></div>
        </div>
        <div class="cliente-kpi">
            <div class="label">Urgentes Ativos</div>
            <div class="valor" style="color:#dc2626;"><?= $kpiPatsUrgentes ?></div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="panel" style="margin-bottom:20px;">
        <form method="get">
            <input type="hidden" name="page" value="pats">
            <div class="clientes-filtros">
                <div>
                    <label>Pesquisar</label>
                    <label>
                        <input type="text" name="q" value="<?= htmlspecialchars($patFiltros['q']) ?>" placeholder="Nº PAT, entidade ou técnico">
                    </label>
                </div>
                <div>
                    <label>Estado</label>
                    <label>
                        <select name="estado">
                            <option value="">-- Todos --</option>
                            <?php foreach (['Aberto','Em Curso','Resolvido','Concluído','Cancelado'] as $est): ?>
                            <option value="<?= $est ?>" <?= $patFiltros['estado']===$est ? 'selected' : '' ?>><?= $est ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div>
                    <label>Prioridade</label>
                    <label>
                        <select name="prioridade">
                            <option value="">-- Todas --</option>
                            <option value="Normal"  <?= $patFiltros['prioridade']==='Normal'  ? 'selected' : '' ?>>Normal</option>
                            <option value="Urgente" <?= $patFiltros['prioridade']==='Urgente' ? 'selected' : '' ?>>Urgente</option>
                        </select>
                    </label>
                </div>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="submit" class="btn btn-blue">Filtrar</button>
                    <a href="app.php?page=pats" class="btn btn-grey">Limpar</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Botão novo PAT + Tabela -->
    <div class="panel">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h4 style="margin:0;">Lista de PATs</h4>
            <a href="app.php?page=pats&acao=novo" class="btn btn-blue">+ Novo PAT</a>
            <a href="exportar_pats_csv.php" class="btn btn-green" style="padding:8px 14px; font-size:13px;">
                <i class="bi bi-download"></i> Exportar CSV
            </a>
        </div>

        <div style="overflow-x:auto;">
            <table class="table">
                <thead>
                <tr>
                    <th>Nº PAT</th>
                    <th>Entidade</th>
                    <th>Técnico</th>
                    <th>Receção</th>
                    <th>Limite</th>
                    <th>Prioridade</th>
                    <th>Estado</th>
                    <th>Ações</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($patsList)): ?>
                    <tr><td colspan="8" style="text-align:center; color:#6b7280; padding:30px;">Nenhum PAT encontrado.</td></tr>
                <?php else: ?>
          <?php foreach ($patsList as $pat): ?>
            <?php
                $estCores = [
                    'Aberto'    => ['bg'=>'#dbeafe','color'=>'#1d4ed8'],
                    'Em Curso'  => ['bg'=>'#fef3c7','color'=>'#92400e'],
                    'Resolvido' => ['bg'=>'#e0e7ff','color'=>'#4338ca'],
                    'Concluído' => ['bg'=>'#dcfce7','color'=>'#15803d'],
                ];
                $estCor = $estCores[$pat['estado']] ?? ['bg'=>'#f3f4f6','color'=>'#374151'];
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($pat['numero_pat']) ?>/<?= (int)$pat['revisao'] ?></strong></td>
                    <td><?= htmlspecialchars($pat['entidade']) ?></td>
                    <td><?= htmlspecialchars($pat['tecnico']) ?></td>
                    <td><?= $pat['data_recepcao'] ? date('d/m/Y H:i', strtotime($pat['data_recepcao'])) : '—' ?></td>
                    <td><?= $pat['data_limite']   ? date('d/m/Y H:i', strtotime($pat['data_limite']))   : '—' ?></td>
                    <td>
                        <?php if ($pat['prioridade'] === 'Urgente'): ?>
                            <span style="padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600; background:#fee2e2; color:#dc2626;">Urgente</span>
                        <?php else: ?>
                            <span style="color:#6b7280; font-size:12px;">Normal</span>
                        <?php endif; ?>
                    </td>
                    <td>
                <span style="padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600;
                        background:<?= $estCor['bg'] ?>; color:<?= $estCor['color'] ?>;">
                  <?= htmlspecialchars($pat['estado']) ?>
                </span>
                    </td>
                    <td>
                      <div class="acao-wrap">
                        <button class="acao-btn" type="button" onclick="toggleAcao(this)">⋮</button>
                        <div class="acao-menu">
                          <a href="app.php?page=pats&ver=<?= (int)$pat['id'] ?>">
                            <i class="bi bi-pencil-square"></i> Editar
                          </a>
                          <a href="workorder.php?id=<?= (int)$pat['id'] ?>" target="_blank">
                             <i class="bi bi-file-earmark-text"></i> Folha de Obra
                          </a>
                        </div>
                      </div>
                    </td>
                </tr>
                <?php endforeach; ?>
        <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif;  ?>
    <!-- ════════════════════════════════════════════
         FIM VISTA: LISTA DE PATs
    ════════════════════════════════════════════ -->

