<?php
/* Tabelas — hub master/detail.
   Coluna esquerda: tipos de tabela (Categorias / Estados / Produtos / Parceiros).
   Coluna direita: a tabela REAL do tipo ativo, embebida via require da sub-página.
   O modo "hub" já está preparado em tabelas_logic.php ($tabHubMode = page === 'tabelas'),
   que faz as sub-páginas esconderem o seu próprio cabeçalho e usarem tabUrl().
   Nada da lógica de negócio é alterado — só a apresentação. */
if ($page !== 'tabelas') return;

$tabTipoAtivo = $_GET['tab'] ?? 'categorias';
$tabsDisponiveis = [
    'categorias' => ['Categorias', 'bi-tag',         'Tipos de equipamento e peças'],
    'estados'    => ['Estados',    'bi-circle-fill', 'Estados possíveis das peças'],
    'produtos'   => ['Produtos',   'bi-box-seam',    'Catálogo de produtos por categoria'],
    'parceiros'  => ['Parceiros',  'bi-people',      'Parceiros e respetivos contactos'],
];
if (!isset($tabsDisponiveis[$tabTipoAtivo])) {
    $tabTipoAtivo = 'categorias';
}
// Em modo formulário (criar/editar/ver) escondemos a barra de pesquisa + "Novo"
// do cabeçalho do hub — a sub-página mostra o seu próprio formulário e botão Voltar.
$tabEmFormulario = isset($_GET['nova']) || ($_GET['edit'] ?? '') !== '' || ($_GET['ver'] ?? '') !== '';

// Labels singulares para o botão "Novo …"
$tabSingular = [
    'categorias' => 'Categoria',
    'estados'    => 'Estado',
    'produtos'   => 'Produto',
    'parceiros'  => 'Parceiro',
];
?>
<style>
.tabelas-hub{
    display:flex;
    flex-direction:column;
    gap:20px;
}
.tabelas-nav{
    position:static;
    width:100%;
    background:#fff;
    border:1px solid #e5e9ef;
    border-radius:14px;
    padding:10px;
    box-shadow:0 2px 10px rgba(0,0,0,.06);
    display:flex;
    align-items:center;
    gap:8px;
}
.tabelas-nav-titulo{
    font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em;
    color:#9ca3af; padding:0 10px 0 6px; flex-shrink:0; white-space:nowrap;
}
.tabelas-nav-lista{
    display:flex;
    align-items:stretch;
    gap:8px;
    flex:1;
    min-width:0;
}
.tabelas-nav a{
    display:flex; align-items:center; gap:10px;
    padding:10px 14px; border-radius:10px;
    color:#374151; text-decoration:none; font-size:14px; font-weight:600;
    transition:background .15s, color .15s;
    flex:1;
    min-width:0;
}
.tabelas-nav a .tn-ico{
    width:34px; height:34px; border-radius:9px;
    display:flex; align-items:center; justify-content:center; flex-shrink:0;
    background:#f4f6f9; color:#6b7280; font-size:16px;
    transition:background .15s, color .15s;
}
.tabelas-nav a .tn-text{ min-width:0; overflow:hidden; }
.tabelas-nav a .tn-text > span:first-child{
    display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
}
.tabelas-nav a .tn-sub{
    display:block; font-size:11px; font-weight:400; color:#9ca3af;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.tabelas-nav a:hover{ background:#f8fafc; color:#1a202c; }
.tabelas-nav a.is-active{ background:#fdf6e9; color:#1a202c; }
.tabelas-nav a.is-active .tn-ico{ background:#c9a14a; color:#fff; }
.tabelas-detalhe{ min-width:0; }
.tabelas-detalhe-head{
    display:flex; align-items:center; justify-content:space-between;
    flex-wrap:wrap; gap:12px; margin-bottom:14px;
}
.tabelas-detalhe-head .td-title{
    display:flex; align-items:center; gap:10px;
    font-size:18px; font-weight:700; color:#1f2937; margin:0;
}
.tabelas-detalhe-head .td-title i{ color:#c9a14a; }
body.dark-mode .tabelas-nav{ background:#1e2533; border-color:#374151; }
body.dark-mode .tabelas-nav a{ color:#e5e7eb; }
body.dark-mode .tabelas-nav a .tn-ico{ background:#374151; color:#9ca3af; }
body.dark-mode .tabelas-nav a:hover{ background:#2b3647; }
body.dark-mode .tabelas-nav a.is-active{ background:#3a3320; }
body.dark-mode .tabelas-detalhe-head .td-title{ color:#f3f4f6; }
@media (max-width:900px){
    .tabelas-nav{ flex-wrap:wrap; }
    .tabelas-nav-titulo{ flex-basis:100%; padding:0 0 4px; }
    .tabelas-nav-lista{ flex-wrap:wrap; }
    .tabelas-nav a{ flex:1 1 calc(50% - 8px); }
}
@media (max-width:560px){
    .tabelas-nav-lista{ overflow-x:auto; flex-wrap:nowrap; }
    .tabelas-nav a{ flex:0 0 auto; }
    .tabelas-nav a .tn-sub{ display:none; }
}
</style>

<div class="tabelas-hub">
    <div class="tabelas-nav">
        <div class="tabelas-nav-titulo">Tipos de tabela</div>
        <div class="tabelas-nav-lista">
        <?php foreach ($tabsDisponiveis as $key => $info): ?>
            <a href="app.php?page=tabelas&tab=<?= $key ?>"
               class="<?= $tabTipoAtivo === $key ? 'is-active' : '' ?>">
                <span class="tn-ico"><i class="bi <?= $info[1] ?>"></i></span>
                <span class="tn-text">
                    <span><?= htmlspecialchars($info[0]) ?></span>
                    <span class="tn-sub"><?= htmlspecialchars($info[2]) ?></span>
                </span>
            </a>
        <?php endforeach; ?>
        </div>
    </div>

    <section class="tabelas-detalhe">
        <div class="tabelas-detalhe-head">
            <h2 class="td-title">
                <i class="bi <?= $tabsDisponiveis[$tabTipoAtivo][1] ?>"></i>
                <?= htmlspecialchars($tabsDisponiveis[$tabTipoAtivo][0]) ?>
            </h2>
            <?php if (!$tabEmFormulario): ?>
            <div class="panel-header-actions">
                <div class="quick-search-wrap">
                    <i class="bi bi-search"></i>
                    <input type="text" class="quick-search-input"
                           data-table="#tabela<?= ucfirst($tabTipoAtivo) ?>"
                           placeholder="Pesquisar…">
                </div>
                <a class="btn btn-teal" href="app.php?page=tabelas&tab=<?= $tabTipoAtivo ?>&nova=1">
                    <i class="bi bi-plus-lg"></i> Novo <?= htmlspecialchars($tabSingular[$tabTipoAtivo]) ?>
                </a>
            </div>
            <?php endif; ?>
        </div>

        <?php
        // Embeber a tabela real do tipo ativo. As sub-páginas detetam $tabHubMode
        // (page === 'tabelas') e escondem o seu próprio cabeçalho, mostrando apenas
        // o painel com a tabela / formulário. A lógica de POST está em tabelas_logic.php,
        // já incluída por cada sub-página.
        require __DIR__ . '/' . $tabTipoAtivo . '.php';
        ?>
    </section>
</div>
