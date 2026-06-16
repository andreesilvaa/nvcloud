<?php
// Diagnóstico automático de todas as guias — apagar depois
session_start();
if (!isset($_SESSION['user_id'])) { die('Sem acesso.'); }

require_once __DIR__ . '/config.php';
$pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET, DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$pdftotext = '"C:\\poppler\\poppler-26.02.0\\Library\\bin\\pdftotext.exe"';
$pastaGuias = 'C:\\Users\\josee\\OneDrive\\Ambiente de Trabalho\\12.º ANO\\PC_NEWVISION\\Guias\\';

$catalogoDb  = $pdo->query("SELECT p.nome AS produto, c.nome AS categoria FROM produtos p JOIN categorias c ON c.id=p.categoria_id ORDER BY LENGTH(p.nome) DESC")->fetchAll();
$parceirosDb = $pdo->query("SELECT empresa FROM parceiros ORDER BY empresa ASC")->fetchAll(PDO::FETCH_COLUMN);
$prefixosDb  = $pdo->query("SELECT prefixo, categoria, produto FROM produto_sn_prefixos ORDER BY LENGTH(prefixo) DESC")->fetchAll();

// ── Funções copiadas de envios.php ──────────────────────────
function limparTexto($t){$t=trim((string)$t);$t=str_replace(["\xc2\xa0","\t"],' ',$t);$t=preg_replace('/\s+/u',' ',$t);return trim($t);}
function normalizarTextoPdf($t){$t=str_replace(["\r\n","\r"],"\n",(string)$t);$t=str_replace("\t",' ',$t);$t=preg_replace('/[ ]{2,}/u',' ',$t);return trim($t);}
function normalizarParaMatch(string $s):string{$s=mb_strtolower(trim($s),'UTF-8');$from=['á','à','â','ã','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','õ','ö','ú','ù','û','ü','ç','ñ','Á','À','Â','Ã','Ä','É','È','Ê','Ë','Í','Ì','Î','Ï','Ó','Ò','Ô','Õ','Ö','Ú','Ù','Û','Ü','Ç','Ñ'];$to=['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n','a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n'];return str_replace($from,$to,$s);}
function matchProdutoPorSN(string $sn,array $prefixos):array{$r=['categoria'=>'','produto'=>'','matched'=>false];if($sn===''||empty($prefixos))return $r;$su=strtoupper(trim($sn));foreach($prefixos as $p){$pf=strtoupper(trim($p['prefixo']));if($pf!==''&&strpos($su,$pf)===0){return['categoria'=>$p['categoria']??'','produto'=>$p['produto']??'','matched'=>true];}}return $r;}
function matchProduto(string $d,array $cat):array{$r=['produto'=>$d,'categoria'=>'','score'=>0];if(empty($cat)||trim($d)==='')return $r;$dn=normalizarParaMatch($d);foreach($cat as $item){$pn=normalizarParaMatch($item['produto']);if($pn===''||strpos($dn,$pn)===false)continue;$sc=mb_strlen($pn,'UTF-8');if($sc>$r['score'])$r=['produto'=>$item['produto'],'categoria'=>$item['categoria'],'score'=>$sc];}return $r;}
function matchParceiro(string $nome,array $parceiros):string{if($nome===''||empty($parceiros))return $nome;$nn=normalizarParaMatch($nome);foreach($parceiros as $p){if(normalizarParaMatch($p)===$nn)return $p;}$mel='';$ms=0;foreach($parceiros as $p){$pn=normalizarParaMatch($p);$pw=preg_split('/[^a-z0-9]+/',$pn,-1,PREG_SPLIT_NO_EMPTY)?:[];$sc=0;foreach($pw as $w){if(strlen($w)>=4&&strpos($nn,$w)!==false)$sc+=strlen($w);}if($sc>$ms){$ms=$sc;$mel=$p;}}return($ms>=6)?$mel:$nome;}
function linhaIgnorar($l){$ll=mb_strtolower(limparTexto($l),'UTF-8');foreach(['software phc','processado por programa certificado','documento não serve','documento nao serve','página','pagina','atcud:','guia de transporte','local de carga','designação ata','designacao ata','nº série','n.o série','n serie','v/ nº contribuinte','v/ encomenda','nº documento','data documento','via do documento','total do documento','total ilíquido','total do desconto','base de incidência','total de i.v.a.','matrícula','data de carga','data de descarga','cod. identificação at','original','duplicado','triplicado','nfornecedor','v/ncontribuinte','vencome','designagao','designação','qtd','nserie','nsérie','serie','série'] as $b){if(strpos($ll,$b)!==false)return true;}return false;}
function ehPat($t){return(bool)preg_match('/^PAT-\d+$/i',trim($t));}
function ehSnValido($t){$t=strtoupper(trim($t));if($t==='')return false;if(ehPat($t))return false;if(strlen($t)<7)return false;if(preg_match('/\s/',$t))return false;if(!preg_match('/[A-Z]/',$t))return false;if(!preg_match('/\d/',$t))return false;if(in_array($t,['IMPRESSORA','ASSISTENCIA','PC','BOTAO','BOTÃO','WIFI','BOX','VODAFONE','PORTO','MOS','MÓS','EDP','COMERCIAL','LEIRIA'],true))return false;if(preg_match('/^[A-Z]{2,}\-[A-Z0-9\-]+$/',$t)&&!preg_match('/\d{4,}/',$t))return false;return true;}
function extrairPats($t){preg_match_all('/PAT-\d+/iu',$t,$m);return array_values(array_unique(array_map('strtoupper',$m[0]??[])));}
function limparTipoPeca($t){$t=limparTexto($t);$t=preg_replace('/\bASSISTENCIA\b/iu','',$t);$t=preg_replace('/\s{2,}/u',' ',$t);$t=trim($t);$u=strtoupper($t);if(in_array($u,['TAOWIF','TAOWIFI'],true))return 'taoWifi';return $t;}
function extrairTipoPecaDaLinha($l){$l=limparTexto($l);$l=preg_replace('/\s+\d+,\d{2}\s*$/u','',$l);$p=array_values(array_filter(array_map('trim',explode('/',$l)),fn($v)=>$v!==''));if(empty($p))return '';return limparTipoPeca($p[0]);}
function extrairQuantidadeDaLinha($l){if(preg_match('/(\d+),\d{2}\s*$/u',limparTexto($l),$m))return(int)$m[1];return 1;}

function extrairBlocoItens($texto){
    if(preg_match('/Artigo\s+Designa[cç][aã]o\s+Qtd\.?\s+N[ºo]\s*S[ée]rie(.+?)(Software\s+PHC|Local\s+de\s+carga|P[aá]gina\s+\d+\s+de\s+\d+)/isu',$texto,$m))return trim($m[1]);
    return '';
}

function dividirItensPorLinhas($linhas){$itens=[];$buffer='';foreach($linhas as $l){$l=limparTexto($l);if($l==='')continue;if(preg_match('/^ASSISTENCIA\b/iu',$l)){if($buffer!=='')$itens[]=$buffer;$buffer=$l;continue;}if($buffer!=='')$buffer.=' '.$l;}if($buffer!=='')$itens[]=$buffer;return $itens;}

function expandirLinhaEmItens($linha){
    $lo=limparTexto($linha);
    if($lo===''||!preg_match('/^ASSISTENCIA\b/iu',$lo))return[];
    $qtd=extrairQuantidadeDaLinha($lo);
    $semQtd=preg_replace('/\s+\d+,\d{2}\s*$/u','',$lo);
    $tipo=extrairTipoPecaDaLinha($semQtd);
    $pats=extrairPats($semQtd);
    $partes=array_values(array_filter(array_map('trim',explode('/',$semQtd)),fn($v)=>$v!==''));
    $sns=[];
    foreach($partes as $i=>$p){
        $pn=strtoupper(limparTexto($p));
        if($i===0)continue;
        if(ehPat($pn))continue;
        if(ehSnValido($pn)){$sns[]=$pn;continue;}
        // Tenta extrair SN embutido no token
        preg_match_all('/\b([A-Z0-9]{7,})\b/u',$pn,$ms);
        foreach($ms[1]??[] as $c){$c=strtoupper(trim($c));if(ehSnValido($c))$sns[]=$c;}
    }
    $sns=array_values(array_unique($sns));
    $patsTexto=!empty($pats)?implode(', ',$pats):'';
    $res=[];
    if(!empty($sns)){foreach($sns as $sn)$res[]=['tipo'=>$tipo,'quantidade'=>$qtd,'sn'=>$sn,'pat'=>$patsTexto,'nome_peca'=>'','categoria'=>'','_linha'=>$lo];}
    elseif($patsTexto!=='')$res[]=['tipo'=>$tipo,'quantidade'=>$qtd,'sn'=>'','pat'=>$patsTexto,'nome_peca'=>'','categoria'=>'','_linha'=>$lo];
    return $res;
}

function extrairCabecalhoGuia($texto){
    $d=['documento'=>'','numero_documento'=>'','data_documento'=>'','destinatario_nome'=>'','destinatario_local'=>'','fornecedor_numero'=>'','contribuinte'=>'','parceiro'=>''];
    if(preg_match('/G\.\s*Transp\s*\(said\s*fornec\)/iu',$texto))$d['documento']='G. Transp (said fornec)';
    elseif(preg_match('/G\.\s*Transp\s*\(said\s*cli\b/iu',$texto))$d['documento']='G. Transp (said cli)';
    elseif(preg_match('/Guia\s+de\s+transporte/iu',$texto))$d['documento']='Guia de Transporte';
    if(preg_match('/G\.\s*Transp\s*\(said\s*(?:fornec|cli\b)[^)]*\)\s+(\d{1,6})\s+(\d{4}-\d{2}-\d{2})/iu',$texto,$m)){$d['numero_documento']=$m[1];$d['data_documento']=$m[2];}
    else{if(preg_match('/\b(\d{4}-\d{2}-\d{2})\b/u',$texto,$m))$d['data_documento']=$m[1];if(preg_match('/N[\xba\xb0o\.?]\s*Documento[^0-9]*(\d{1,6})\b/isu',$texto,$m))$d['numero_documento']=$m[1];if($d['numero_documento']===''&&preg_match('/ATCUD:[A-Z0-9]+-([0-9]+)/iu',$texto,$m))$d['numero_documento']=$m[1];}
    // Destinatário Padrão 1
    if(preg_match('/Exmo\(s\)\s+Senhor\(es\).*?\n+\s*([^\n]{8,}(?:LDA|S\.?\s*A\.?|SRL|UNIPESSOAL|COOPERATIVA)[^\n]*)/isu',$texto,$m)){$c=limparTexto($m[1]);if(!preg_match('/^GT\s+\d/i',$c))$d['destinatario_nome']=$c;}
    // Padrão 2
    if($d['destinatario_nome']===''&&preg_match('/Exmo\(s\)\s+Senhor\(es\)\s*\n+\s*([^\n]{8,})\n+([^\n]*(?:LDA|S\.?\s*A\.?|UNIPESSOAL|SRL|COOPERATIVA)[^\n]*)/isu',$texto,$m)){$a=limparTexto($m[1]);$b=limparTexto($m[2]);if(!preg_match('/^GT\s+\d|^Guia de|^ATCUD/i',$a)){$suf=preg_replace('/^GT\s+[A-Z0-9\/\.]+\s*/i','',$b);$suf=limparTexto($suf);$d['destinatario_nome']=$suf!==''?limparTexto($a.' '.$suf):$a;}}
    // Padrão 3
    if($d['destinatario_nome']===''&&preg_match('/Exmo\(s\)\s+Senhor\(es\)\s+([^\n]{8,})/iu',$texto,$m)){$c=limparTexto($m[1]);if(!preg_match('/^(?:Documento|N[\xba\xb0o]|Via\s+do)/iu',$c))$d['destinatario_nome']=$c;}
    // Padrão 4
    if($d['destinatario_nome']===''&&preg_match('/^([A-Z\xc0-\xff\-][^\n]{8,}(?:LDA|S\.?A\.|UNIPESSOAL|COOPERATIVA)[^\n]*)/mu',$texto,$m))$d['destinatario_nome']=limparTexto($m[1]);
    if($d['destinatario_nome']!==''){$d['destinatario_nome']=preg_replace('/\s+(?:G\.\s*Transp|N\xba\s*Fornecedor|Documento|ORIGINAL|DUPLICADO|TRIPLICADO).*/iu','',$d['destinatario_nome']);$d['destinatario_nome']=limparTexto($d['destinatario_nome']);}
    if(preg_match('/Local\s+de\s+descarga\s+(.+?)\s+(?:Total\s+il[i\xed]quido|Cod\.\s+Identifica[c\xe7][a\xe3]o)/isu',$texto,$m))$d['destinatario_local']=limparTexto($m[1]);
    elseif(preg_match('/Local\s+de\s+descarga\s+(.+?)\s+Cod\./isu',$texto,$m))$d['destinatario_local']=limparTexto($m[1]);
    if(preg_match('/N[\xba\xb0o]\s+Fornecedor[^0-9]*(\d{1,10})\b/isu',$texto,$m))$d['fornecedor_numero']=$m[1];
    elseif(preg_match('/\b2476\b/u',$texto,$m))$d['fornecedor_numero']=$m[0];
    if(preg_match('/V\/\s*N[\xba\xb0o]\s*Contribu[i\xed]nte[^0-9]*(\d{9})\b/isu',$texto,$m))$d['contribuinte']=$m[1];
    elseif(preg_match('/\b500339023\b/u',$texto,$m))$d['contribuinte']=$m[0];
    return $d;
}

function processarGuia($pdfPath, $pdftotext, $catalogoDb, $parceirosDb, $prefixosDb) {
    $tmp = tempnam(sys_get_temp_dir(), 'pdftxt_');
    @unlink($tmp);
    $cmd = $pdftotext . ' -layout ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($tmp) . ' 2>&1';
    $cmdOut = shell_exec($cmd);
    $txtFile = $tmp . '.txt';
    if (!is_file($txtFile)) return ['erro' => 'pdftotext falhou: ' . $cmdOut, 'texto' => ''];
    $texto = normalizarTextoPdf(file_get_contents($txtFile));
    @unlink($txtFile);

    $dadosGuia = extrairCabecalhoGuia($texto);
    
    // Extrair bloco de itens
    $bloco = extrairBlocoItens($texto);
    $linhas = [];
    if ($bloco !== '') {
        $linhasRaw = preg_split('/\n/u', $bloco) ?: [];
        foreach ($linhasRaw as $l) {
            $l = limparTexto($l);
            if ($l === '' || linhaIgnorar($l)) continue;
            $linhas[] = $l;
        }
    }
    $linhasItens = dividirItensPorLinhas($linhas);
    
    $itens = [];
    $vistos = [];
    foreach ($linhasItens as $li) {
        foreach (expandirLinhaEmItens($li) as $item) {
            $snKey = strtoupper(trim($item['sn']));
            $patKey = strtoupper(trim($item['pat']));
            $tipoKey = strtoupper(trim($item['tipo']));
            $chave = $snKey !== '' ? 'SN|'.$snKey : ($patKey !== '' ? 'PAT|'.$patKey : 'TIPO|'.$tipoKey.'|QTD|'.$item['quantidade']);
            if (isset($vistos[$chave])) continue;
            $vistos[$chave] = true;
            // Matching produto/categoria
            $sn = $item['sn'];
            $matchSN = matchProdutoPorSN($sn, $prefixosDb);
            if ($matchSN['matched']) {
                $item['categoria'] = $matchSN['categoria'];
                if ($matchSN['produto'] !== '') {
                    $item['nome_peca'] = $matchSN['produto'];
                } else {
                    $mt = matchProduto($item['tipo'], $catalogoDb);
                    $item['nome_peca'] = $mt['produto'] ?: $item['tipo'];
                }
            } else {
                $mt = matchProduto($item['tipo'], $catalogoDb);
                $item['nome_peca'] = $mt['produto'];
                $item['categoria'] = $mt['categoria'];
            }
            $itens[] = $item;
        }
    }

    // Parceiro
    if (stripos($dadosGuia['documento'], 'cli') !== false) {
        $dadosGuia['parceiro'] = 'Field Service';
    } elseif (!empty($dadosGuia['destinatario_nome'])) {
        $dadosGuia['parceiro'] = matchParceiro($dadosGuia['destinatario_nome'], $parceirosDb);
    }

    return [
        'dados'          => $dadosGuia,
        'itens'          => $itens,
        'num_itens_raw'  => count($linhasItens),
        'bloco_vazio'    => $bloco === '',
        'texto'          => $texto,
        'erro'           => '',
    ];
}

// Recolher PDFs
$pdfs = glob($pastaGuias . '*.pdf') ?: [];
sort($pdfs);

$guiaFiltro = $_GET['guia'] ?? '';
$mostraTexto = isset($_GET['raw']);

?><!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<title>Diagnóstico Guias</title>
<style>
body{font-family:system-ui,sans-serif;max-width:1300px;margin:20px auto;padding:0 16px;background:#f5f6fa;font-size:14px}
h1,h2{margin-top:0}
.ok{color:#059669;font-weight:700}.err{color:#dc2626;font-weight:700}.warn{color:#d97706;font-weight:700}
.card{background:#fff;border-radius:10px;padding:16px 20px;margin-bottom:14px;box-shadow:0 1px 6px rgba(0,0,0,.07)}
table{border-collapse:collapse;width:100%}th,td{border:1px solid #e5e7eb;padding:7px 9px;text-align:left;vertical-align:top}
th{background:#f3f4f6;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em}
.badge{display:inline-block;background:#eef2ff;color:#3730a3;padding:2px 8px;border-radius:999px;font-size:.75rem;font-weight:700}
.badge-red{background:#fee2e2;color:#b91c1c}
.badge-green{background:#dcfce7;color:#15803d}
pre{background:#f8f9fb;border:1px solid #e5e7eb;border-radius:6px;padding:10px;max-height:300px;overflow:auto;font-size:11px;white-space:pre-wrap;word-break:break-word}
.row-warn td{background:#fffbeb}
.row-err td{background:#fff1f2}
a{color:#2563eb;text-decoration:none}.a:hover{text-decoration:underline}
.sn{font-family:monospace;font-size:.85rem}
.nav{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px}
.nav a{padding:5px 10px;border-radius:6px;border:1px solid #d1d5db;color:#374151;background:#fff}
.nav a.active{background:#2563eb;color:#fff;border-color:#2563eb}
summary{cursor:pointer;color:#2563eb}
</style>
</head>
<body>
<h1>🔍 Diagnóstico de Guias de Transporte</h1>
<p><?= count($pdfs) ?> guias encontradas na pasta.</p>

<div class="nav">
<a href="?">Todas</a>
<?php foreach ($pdfs as $pdf): $nome = basename($pdf, '.pdf'); ?>
<a href="?guia=<?= urlencode($nome) ?>" <?= $guiaFiltro === $nome ? 'class="active"' : '' ?>><?= htmlspecialchars($nome) ?></a>
<?php endforeach; ?>
</div>

<?php
$sumario = [];

foreach ($pdfs as $pdfPath):
    $nome = basename($pdfPath, '.pdf');
    if ($guiaFiltro !== '' && $guiaFiltro !== $nome) continue;

    $r = processarGuia($pdfPath, $pdftotext, $catalogoDb, $parceirosDb, $prefixosDb);

    $tipoGuia = $r['dados']['documento'] ?? '';
    $nDoc = $r['dados']['numero_documento'] ?? '';
    $dest = $r['dados']['destinatario_nome'] ?? '';
    $parceiro = $r['dados']['parceiro'] ?? '';
    $numItens = count($r['itens'] ?? []);
    $blocoVazio = $r['bloco_vazio'] ?? false;
    $temErro = $r['erro'] !== '';

    // SNs com/sem categoria
    $snSemCat = array_filter($r['itens'] ?? [], fn($i) => $i['sn'] !== '' && $i['categoria'] === '');
    $snSemSn  = array_filter($r['itens'] ?? [], fn($i) => $i['sn'] === '');

    $issues = [];
    if ($tipoGuia === '') $issues[] = ['err','Tipo de guia não detetado'];
    if ($nDoc === '') $issues[] = ['warn','Nº documento não encontrado'];
    if ($dest === '') $issues[] = ['warn','Destinatário não encontrado'];
    if ($parceiro === '') $issues[] = ['warn','Parceiro não identificado'];
    if ($parceiro !== '' && $parceiro === $dest) $issues[] = ['warn','Parceiro = destinatário raw (não fez match com BD)'];
    if ($blocoVazio) $issues[] = ['err','Bloco de itens não encontrado (regex da tabela falhou)'];
    if ($numItens === 0 && !$blocoVazio) $issues[] = ['err','Bloco encontrado mas nenhum item extraído'];
    if ($numItens === 0 && $blocoVazio) $issues[] = ['err','Sem itens (bloco vazio)'];
    foreach ($snSemCat as $i) $issues[] = ['warn', 'SN <code>' . htmlspecialchars($i['sn']) . '</code> sem categoria (prefixo não mapeado)'];
    foreach ($snSemSn as $i) $issues[] = ['warn', 'Item "' . htmlspecialchars($i['tipo'] ?: $i['nome_peca'] ?? '?') . '" sem SN (só PAT: ' . htmlspecialchars($i['pat']) . ')'];

    $sumario[] = ['nome' => $nome, 'issues' => $issues, 'itens' => $numItens, 'tipo' => $tipoGuia, 'parceiro' => $parceiro];

    $statusClass = count(array_filter($issues, fn($i) => $i[0]==='err')) > 0 ? 'border-left:4px solid #dc2626' : (count($issues) > 0 ? 'border-left:4px solid #f59e0b' : 'border-left:4px solid #059669');
?>
<div class="card" style="<?= $statusClass ?>">
  <details <?= $guiaFiltro !== '' ? 'open' : '' ?>>
    <summary>
      <strong><?= htmlspecialchars($nome) ?></strong>
      &nbsp;
      <?php if (count(array_filter($issues,fn($i)=>$i[0]==='err'))>0): ?><span class="badge badge-red">ERRO</span><?php elseif (!empty($issues)): ?><span class="badge" style="background:#fef3c7;color:#92400e">AVISO</span><?php else: ?><span class="badge badge-green">OK</span><?php endif; ?>
      &nbsp;
      <span style="color:#6b7280"><?= htmlspecialchars($tipoGuia ?: '—') ?></span>
      | Nº <?= htmlspecialchars($nDoc ?: '?') ?>
      | Parceiro: <strong><?= htmlspecialchars($parceiro ?: '—') ?></strong>
      | <?= $numItens ?> item(s)
    </summary>

    <?php if ($temErro): ?><p class="err">❌ <?= htmlspecialchars($r['erro']) ?></p><?php endif; ?>

    <?php if (!empty($issues)): ?>
    <ul style="margin:10px 0 12px">
    <?php foreach($issues as [$lvl,$msg]): ?>
      <li class="<?= $lvl ?>">⚑ <?= $msg ?></li>
    <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
      <div><b>Tipo:</b> <?= htmlspecialchars($tipoGuia ?: '—') ?></div>
      <div><b>Nº Doc:</b> <?= htmlspecialchars($nDoc ?: '—') ?></div>
      <div><b>Data:</b> <?= htmlspecialchars($r['dados']['data_documento'] ?: '—') ?></div>
      <div><b>Destinatário:</b> <?= htmlspecialchars($dest ?: '—') ?></div>
      <div><b>Parceiro BD:</b> <strong><?= htmlspecialchars($parceiro ?: '—') ?></strong></div>
      <div><b>Local descarga:</b> <?= htmlspecialchars($r['dados']['destinatario_local'] ?: '—') ?></div>
    </div>

    <?php if (!empty($r['itens'])): ?>
    <table>
      <thead><tr><th>#</th><th>Tipo (da guia)</th><th>Nome Peça (BD)</th><th>Categoria</th><th class="sn">SN</th><th>PAT</th><th>Qtd</th></tr></thead>
      <tbody>
      <?php foreach ($r['itens'] as $idx => $item): ?>
        <tr class="<?= ($item['sn']!==''&&$item['categoria']==='') ? 'row-warn' : (($item['sn']===''&&$item['pat']==='') ? 'row-err' : '') ?>">
          <td><?= $idx+1 ?></td>
          <td style="font-size:.8rem;color:#6b7280"><?= htmlspecialchars($item['tipo']) ?></td>
          <td><?= htmlspecialchars($item['nome_peca'] ?: '—') ?></td>
          <td><?= $item['categoria'] ? '<span class="badge">'.htmlspecialchars($item['categoria']).'</span>' : '<span style="color:#dc2626">sem categoria</span>' ?></td>
          <td class="sn"><?= $item['sn'] !== '' ? htmlspecialchars($item['sn']) : '<span style="color:#9ca3af">—</span>' ?></td>
          <td style="font-size:.78rem;color:#6b7280"><?= $item['pat'] !== '' ? htmlspecialchars($item['pat']) : '—' ?></td>
          <td style="text-align:center"><?= $item['quantidade'] ?></td>
        </tr>
        <?php if ($guiaFiltro !== ''): ?>
        <tr><td colspan="7" style="background:#f8f9fb;font-size:.75rem;color:#6b7280"><code><?= htmlspecialchars($item['_linha'] ?? '') ?></code></td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <p class="err">Nenhum item extraído.</p>
    <?php endif; ?>

    <?php if ($guiaFiltro !== '' || $mostraTexto): ?>
    <details style="margin-top:12px"><summary>Texto bruto do PDF</summary>
    <pre><?= htmlspecialchars($r['texto']) ?></pre>
    </details>
    <?php endif; ?>

  </details>
</div>
<?php endforeach; ?>

<?php if ($guiaFiltro === ''): ?>
<div class="card">
<h2>Sumário geral</h2>
<table>
<thead><tr><th>Guia</th><th>Tipo</th><th>Parceiro</th><th>Itens</th><th>Issues</th></tr></thead>
<tbody>
<?php foreach ($sumario as $s): ?>
<tr class="<?= count(array_filter($s['issues'],fn($i)=>$i[0]==='err'))>0?'row-err':(count($s['issues'])>0?'row-warn':'') ?>">
  <td><a href="?guia=<?= urlencode($s['nome']) ?>"><?= htmlspecialchars($s['nome']) ?></a></td>
  <td><?= htmlspecialchars($s['tipo'] ?: '—') ?></td>
  <td><?= htmlspecialchars($s['parceiro'] ?: '—') ?></td>
  <td style="text-align:center"><?= $s['itens'] ?></td>
  <td>
  <?php if (empty($s['issues'])): ?><span class="ok">✓ OK</span>
  <?php else: foreach($s['issues'] as [$l,$m]): ?><span class="<?= $l ?>"><?= $m ?></span><br><?php endforeach; endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<p style="color:#9ca3af;font-size:.75rem;margin-top:20px">⚠ Apaga este ficheiro: <code>C:\laragon\www\nvcloud\diag_guias.php</code></p>
</body></html>
