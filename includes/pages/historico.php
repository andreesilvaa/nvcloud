<?php

$historico = [];
$pecaHist = null;

if ($page === 'historico' && isset($_GET['id'])) {
    $historicoId = (int)$_GET['id'];

    $stmt = $pdo->prepare("SELECT * FROM historico WHERE peca_id = ? ORDER BY data_alteracao DESC");
    $stmt->execute([$historicoId]);
    $historico = $stmt->fetchAll();

    $stmtPecaHist = $pdo->prepare("SELECT * FROM pecas WHERE id = ?");
    $stmtPecaHist->execute([$historicoId]);
    $pecaHist = $stmtPecaHist->fetch();

    if (!$pecaHist && !empty($historico)) {
        $pecaHist = [
            'id' => $historicoId,
            'produto' => '',
            'sn' => '',
            'categoria' => '',
            'cod_barras' => '',
            'parceiro' => '',
            'estado' => ''
        ];

        foreach ($historico as $h) {
            if ($h['campo'] === 'produto' && $pecaHist['produto'] === '' && $h['antes'] !== '') {
                $pecaHist['produto'] = $h['antes'];
            }

            if ($h['campo'] === 'produto' && $pecaHist['produto'] === '' && $h['depois'] !== '') {
                $pecaHist['produto'] = $h['depois'];
            }

            if ($h['campo'] === 'sn' && $pecaHist['sn'] === '' && $h['antes'] !== '') {
                $pecaHist['sn'] = $h['antes'];
            }

            if ($h['campo'] === 'sn' && $pecaHist['sn'] === '' && $h['depois'] !== '') {
                $pecaHist['sn'] = $h['depois'];
            }

            if ($h['campo'] === 'categoria' && $pecaHist['categoria'] === '' && $h['antes'] !== '') {
                $pecaHist['categoria'] = $h['antes'];
            }

            if ($h['campo'] === 'categoria' && $pecaHist['categoria'] === '' && $h['depois'] !== '') {
                $pecaHist['categoria'] = $h['depois'];
            }

            if ($h['campo'] === 'cod_barras' && $pecaHist['cod_barras'] === '' && $h['antes'] !== '') {
                $pecaHist['cod_barras'] = $h['antes'];
            }

            if ($h['campo'] === 'cod_barras' && $pecaHist['cod_barras'] === '' && $h['depois'] !== '') {
                $pecaHist['cod_barras'] = $h['depois'];
            }

            if ($h['campo'] === 'parceiro' && $pecaHist['parceiro'] === '' && $h['antes'] !== '') {
                $pecaHist['parceiro'] = $h['antes'];
            }

            if ($h['campo'] === 'parceiro' && $pecaHist['parceiro'] === '' && $h['depois'] !== '') {
                $pecaHist['parceiro'] = $h['depois'];
            }

            if ($h['campo'] === 'estado' && $pecaHist['estado'] === '' && $h['antes'] !== '') {
                $pecaHist['estado'] = $h['antes'];
            }

            if ($h['campo'] === 'estado' && $pecaHist['estado'] === '' && $h['depois'] !== '') {
                $pecaHist['estado'] = $h['depois'];
            }
        }
    }
}
?>
  <h1 class="section-title">
  Histórico da Peça #<?= htmlspecialchars($pecaHist['id'] ?? $_GET['id'] ?? '') ?>
  <?php if (!empty($pecaHist['produto'])): ?>
    (<?= htmlspecialchars($pecaHist['produto']) ?>)
  <?php endif; ?>
</h1>

<div class="small-note">
  Número de Série (SN):
  <strong><?= htmlspecialchars($pecaHist['sn'] ?? 'Sem registo') ?></strong>
</div>

  <table class="table" style="margin-top:18px">
    <thead>
      <tr>
        <th>Data</th>
        <th>Campo</th>
        <th>Antes</th>
        <th>Depois</th>
        <th>Utilizador</th>
      </tr>
    </thead>

  <tbody>
      <?php foreach($historico as $h): ?>
        <tr>
          <td><?=date('d/m/Y H:i', strtotime($h['data_alteracao']))?></td>
          <td><?= htmlspecialchars($h['campo']) ?></td>
          <td style="color:#d9534f"><?= htmlspecialchars($h['antes']) ?></td>
          <td style="color:#28a745"><?= htmlspecialchars($h['depois']) ?></td>
          <td><?= htmlspecialchars($h['utilizador']) ?></td>
        </tr>
      <?php endforeach; ?>
  </tbody>
  </table>

  <div style="margin-top:16px">
    <a class="btn btn-yellow" href="app.php?page=inventario" onclick="nvVoltar(event)">← Voltar à lista de peças</a>
  </div>


