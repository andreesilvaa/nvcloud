<?php
session_start();
if (!isset($_SESSION['user_id'])) die('Sem acesso.');

require_once __DIR__ . '/config.php';
$pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET, DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

$pdftotext = 'C:\\poppler\\poppler-26.02.0\\Library\\bin\\pdftotext.exe';

$catalogoDb = $pdo->query("SELECT p.nome AS produto, c.nome AS categoria FROM produtos p JOIN categorias c ON c.id=p.categoria_id ORDER BY LENGTH(p.nome) DESC")->fetchAll();
$parceirosDb = $pdo->query("SELECT empresa FROM parceiros ORDER BY empresa ASC")->fetchAll(PDO::FETCH_COLUMN);
$prefixosDb  = [];
try { $prefixosDb = $pdo->query("SELECT prefixo,categoria,produto FROM produto_sn_prefixos ORDER BY LENGTH(prefixo) DESC")->fetchAll(); } catch(Exception $e){}

// ── Funções inline ────────────────────────────────────────────
function lT($t){$t=trim((string)$t);$t=str_replace(["\xc2\xa0","\t"],' ',$t);return trim(preg_replace('/\s+/u',' ',$t));}
function nTP($t){$t=str_replace(["\r\n","\r"],"\n",(string)$t);$t=str_replace("\t",' ',$t);return trim(preg_replace('/[ ]{2,}/u',' ',$t));}
function nM(string $s):string{$s=mb_strtolower(trim($s),'UTF-8');$f=['á','à','â','ã','é','è','ê','í','ì','î','ó','ò','ô','õ','ú','ù','û','ü','ç','Á','À','Â','Ã','É','È','Ê','Í','Ì','Î','Ó','Ò','Ô','Õ','Ú','Ù','Û','Ü','Ç'];$t=['a','a','a','a','e','e','e','i','i','i','o','o','o','o','u','u','u','u','c','a','a','a','a','e','e','e','i','i','i','o','o','o','o','u','u','u','u','c'];return str_replace($f,$t,$s);}
function mProd(string $d,array $cat):array{$r=['produto'=>$d,'categoria'=>'','score'=>0];if(empty($cat)||trim($d)==='')return $r;$dn=nM($d);foreach($cat as $i){$p=nM($i['produto']);if($p===''||strpos($dn,$p)===false)continue;$s=mb_strlen($p,'UTF-8');if($s>$r['score'])$r=['produto'=>$i['produto'],'categoria'=>$i['categoria'],'score'=>$s];}return $r;}
function mParc(string $n,array $ps):string{if($n===''||empty($ps))return $n;$nn=nM($n);foreach($ps as $p){if(nM($p)===$nn)return $p;}$m='';$ms=0;foreach($ps as $p){$pn=nM($p);$ws=preg_split('/[^a-z0-9]+/',$pn,-1,PREG_SPLIT_NO_EMPTY)?:[];$s=0;foreach($ws as $w){if(strlen($w)>=4&&strpos($nn,$w)!==false)$s+=strlen($w);}if($s>$ms){$ms=$s;$m=$p;}}return($ms>=6)?$m:$n;}
function mSN(string $sn,array $pref):array{$r=['categoria'=>'','produto'=>'','matched'=>false];if($sn===''||empty($pref))return $r;$su=strtoupper(trim($sn));foreach($pref as $p){$pk=strtoupper(trim($p['prefixo']));if($pk!==''&&strpos($su,$pk)===0)return['categoria'=>$p['categoria']??'','produto'=>$p['produto']??'','matched'=>true];}return $r;}
function ePAT($t){return(bool)preg_match('/^PAT-\d+$/i',trim($t));}
function eSN($t){$t=strtoupper(trim($t));if($t===''||ePAT($t)||strlen($t)<7||preg_match('/\s/',$t))return false;if(!preg_match('/[A-Z]/',$t)||!preg_match('/\d/',$t))return false;if(in_array($t,['IMPRESSORA','ASSISTENCIA','PC','BOTAO','BOTÃO','WIFI','BOX','VODAFONE','PORTO','COMERCIAL','LEIRIA'],true))return false;return true;}
function ePATs($t){preg_match_all('/PAT-\d+/iu',$t,$m);return array_values(array_unique(array_map('strtoupper',$m[0]??[])));}
function lTP($t){$t=lT($t);$t=preg_replace('/\bASSISTENCIA\b/iu','',$t);return trim(preg_replace('/\s{2,}/u',' ',$t));}
function lI($l){$ll=mb_strtolower(lT($l),'UTF-8');foreach(['software phc','processado por programa','documento não serve','página','pagina','atcud:','guia de transporte','local de carga','designação','designagao','qtd','nserie','nsérie'] as $b){if(strpos($ll,$b)!==false)return true;}return false;}
function eBlocoItens($t){if(preg_match('/Artigo\s+Designa[cç][aã]o\s+Qtd\.?\s+N[ºo°\.]*\s*S[ée]rie(.+?)(Software\s+PHC|Local\s+de\s+carga|P[aá]gina\s+\d+\s+de\s+\d+)/isu',$t,$m))return trim($m[1]);return '';}
function eItens($texto){$b=eBlocoItens($texto);if($b==='')return[];$ls=preg_split('/\n/u',$b)?:[];$clean=[];foreach($ls as $l){$l=lT($l);if($l===''||lI($l))continue;$clean[]=$l;}$itens=[];$buf='';foreach($clean as $l){if(preg_match('/^ASSISTENCIA\b/iu',$l)){if($buf!=='')$itens[]=$buf;$buf=$l;continue;}if($buf!=='')$buf.=' '.$l;}if($buf!=='')$itens[]=$buf;$res=[];$v=[];foreach($itens as $orig){$orig=lT($orig);if(!preg_match('/^ASSISTENCIA\b/iu',$orig))continue;$qtd=preg_match('/\s(\d+),\d{2}\s/u',$orig,$qm)?((int)$qm[1]):1;if(preg_match('/(\d+),\d{2}\s*$/u',$orig,$qe))$qtd=(int)$qe[1];$semQ=trim(preg_replace('/\s+\d+,\d{2}(\s|$)/u',' ',$orig));$tipo=lTP(explode('/',$semQ)[0]??'');$pats=ePATs($semQ);$partes=array_values(array_filter(array_map('trim',explode('/',$semQ)),fn($x)=>$x!==''));$sns=[];foreach($partes as $i=>$parte){if($i===0)continue;$pn=strtoupper(lT($parte));if(ePAT($pn))continue;if(eSN($pn)){$sns[]=$pn;continue;}preg_match_all('/\b([A-Z0-9]{7,})\b/u',$pn,$ms2);foreach($ms2[1]??[]as $c){$c=strtoupper(trim($c));if(eSN($c))$sns[]=$c;}}$sns=array_values(array_unique($sns));$pt=implode(', ',$pats);if(!empty($sns)){foreach($sns as $sn){$k='SN|'.$sn;if(isset($v[$k]))continue;$v[$k]=true;$res[]=['tipo'=>$tipo,'qtd'=>$qtd,'sn'=>$sn,'pat'=>$pt,'nome_peca'=>'','categoria'=>''];}}else{$k=$pt!==''?'PAT|'.$pt:'DESC|'.$tipo;if(!isset($v[$k])){$v[$k]=true;$res[]=['tipo'=>$tipo,'qtd'=>$qtd,'sn'=>'','pat'=>$pt,'nome_peca'=>'','categoria'=>''];}}}return $res;}
function eCabecalho(string $texto):array{
    $d=['documento'=>'','numero_documento'=>'','data_documento'=>'','destinatario_nome'=>''];
    if(preg_match('/G\.\s*Transp\s*\(said\s*fornec\)/iu',$texto))$d['documento']='G. Transp (said fornec)';
    elseif(preg_match('/G\.\s*Transp\s*\(said\s*cli\b/iu',$texto))$d['documento']='G. Transp (said cli)';
    elseif(preg_match('/Guia\s+de\s+transporte/iu',$texto))$d['documento']='Guia de Transporte';
    if(preg_match('/G\.\s*Transp\s*\(said\s*(?:fornec|cli\b)[^)]*\)\s+(\d{1,6})\s+(\d{4}-\d{2}-\d{2})/iu',$texto,$m)){$d['numero_documento']=$m[1];$d['data_documento']=$m[2];}
    else{if(preg_match('/\b(\d{4}-\d{2}-\d{2})\b/u',$texto,$m))$d['data_documento']=$m[1];if(preg_match('/N[ºo°\.]\s*Documento[^0-9]*(\d{1,6})\b/isu',$texto,$m))$d['numero_documento']=$m[1];if($d['numero_documento']===''&&preg_match('/ATCUD:[A-Z0-9]+-([0-9]+)/iu',$texto,$m))$d['numero_documento']=$m[1];}
    if(preg_match('/Exmo\(s\)\s+Senhor\(es\).*?\n+\s*([^\n]{8,}(?:LDA|S\.?\s*A\.?|SRL|UNIPESSOAL|COOPERATIVA)[^\n]*)/isu',$texto,$m))$d['destinatario_nome']=lT($m[1]);
    if($d['destinatario_nome']===''&&preg_match('/Exmo\(s\)\s+Senhor\(es\)\s+([^\n]{8,})/iu',$texto,$m)){$c=lT($m[1]);if(!preg_match('/^(?:Documento|N[ºo°]|Via\s+do)/iu',$c))$d['destinatario_nome']=$c;}
    if($d['destinatario_nome']!==''){$d['destinatario_nome']=preg_replace('/\s+(?:G\.\s*Transp|N[ºo°]\s*Fornecedor|Documento|ORIGINAL|DUPLICADO|TRIPLICADO).*/iu','',$d['destinatario_nome']);$d['destinatario_nome']=lT($d['destinatario_nome']);}
    return $d;
}

// Usar proc_open para evitar problemas com caracteres especiais no path
function runPdftotext(string $exe, string $pdfPath): string {
    $tmp = tempnam(sys_get_temp_dir(), 'pdftxt_');
    @unlink($tmp);
    $txtOut = $tmp . '.txt';
    $cmd = [$exe, '-layout', $pdfPath, $txtOut];
    $proc = proc_open($cmd, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($proc)) return '';
    fclose($pipes[0]);
    stream_get_contents($pipes[1]); fclose($pipes[1]);
    stream_get_contents($pipes[2]); fclose($pipes[2]);
    proc_close($proc);
    $text = is_file($txtOut) ? file_get_contents($txtOut) : '';
    @unlink($txtOut);
    return $text;
}

$guias = [
    'G. Transp (said fornec) nº 104' => 'C:\\Users\\josee\\OneDrive\\Ambiente de Trabalho\\12.º ANO\\PC_NEWVISION\\Guias\\G. Transp (said fornec) nº 104.pdf',
    'G. Transp (said fornec) nº 122' => 'C:\\Users\\josee\\OneDrive\\Ambiente de Trabalho\\12.º ANO\\PC_NEWVISION\\Guias\\G. Transp (said fornec) nº 122.pdf',
    'G. Transp (said fornec) nº 131' => 'C:\\Users\\josee\\OneDrive\\Ambiente de Trabalho\\12.º ANO\\PC_NEWVISION\\Guias\\G. Transp (said fornec) nº 131.pdf',
    'G. Transp (said cli) nº 197'    => 'C:\\Users\\josee\\OneDrive\\Ambiente de Trabalho\\12.º ANO\\PC_NEWVISION\\Guias\\G. Transp (said cli) nº 197.pdf',
    'G. Transp (said cli) nº 208'    => 'C:\\Users\\josee\\OneDrive\\Ambiente de Trabalho\\12.º ANO\\PC_NEWVISION\\Guias\\G. Transp (said cli) nº 208.pdf',
    'G. Transp (said fornec) nº 149' => 'C:\\Users\\josee\\OneDrive\\Ambiente de Trabalho\\12.º ANO\\PC_NEWVISION\\Guias\\G. Transp (said fornec) nº 149.pdf',
];
?><!DOCTYPE html><html lang="pt"><head><meta charset="UTF-8"><title>Diagnóstico Envios</title>
<style>
*{box-sizing:border-box}body{font-family:system-ui,sans-serif;margin:0;background:#f0f2f7;padding:20px}
h1{color:#1a1d23}.guia{background:#fff;border-radius:12px;padding:18px 22px;margin:14px 0;box-shadow:0 2px 8px rgba(0,0,0,.06)}
.guia h2{margin:0 0 12px;font-size:1rem;color:#2d3142;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.ok{color:#059669;font-weight:700}.err{color:#dc2626;font-weight:700}.warn{color:#d97706}
.pill{padding:2px 9px;border-radius:999px;font-size:.75rem;font-weight:700}
.p-ok{background:#d1fae5;color:#065f46}.p-err{background:#fee2e2;color:#991b1b}.p-warn{background:#fef3c7;color:#92400e}
table{border-collapse:collapse;width:100%;font-size:.83rem;margin-top:6px}
th{background:#f3f4f6;padding:6px 10px;text-align:left;font-size:.73rem;text-transform:uppercase;color:#6b7280}
td{padding:6px 10px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
.mono{font-family:monospace}.badge{display:inline-block;background:#eef2ff;color:#3730a3;padding:1px 7px;border-radius:999px;font-size:.76rem;font-weight:700}
pre{background:#f8f9fb;border:1px solid #e5e7eb;border-radius:6px;padding:10px;font-size:.72rem;max-height:180px;overflow:auto;white-space:pre-wrap;word-break:break-all}
.row{display:flex;gap:12px;margin:4px 0;font-size:.87rem}.row .lbl{color:#6b7280;width:150px;flex-shrink:0;font-size:.8rem;text-transform:uppercase}
.section{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#374151;margin:12px 0 6px}
</style></head><body>
<h1>🔬 Diagnóstico — Página Envios</h1>
<?php foreach($guias as $label=>$pdfPath):
    if(!file_exists($pdfPath)){echo "<div class='guia'><h2>".htmlspecialchars($label)." <span class='pill p-err'>Ficheiro não encontrado</span></h2></div>";continue;}
    $raw=runPdftotext($pdftotext,$pdfPath);
    $texto=nTP($raw);
    $bloco=eBlocoItens($texto);
    $header=eCabecalho($texto);
    $parceiro=stripos($header['documento'],'cli')!==false?'Field Service':($header['destinatario_nome']!==''?mParc($header['destinatario_nome'],$parceirosDb):'');
    $itens=eItens($texto);
    foreach($itens as &$item){$ms=mSN($item['sn']??'',$prefixosDb);if($ms['matched']){$item['categoria']=$ms['categoria'];$item['nome_peca']=$ms['produto']!==''?$ms['produto']:(mProd($item['tipo']??'',$catalogoDb)['produto']?:($item['tipo']??''));}else{$mt=mProd($item['tipo']??'',$catalogoDb);$item['nome_peca']=$mt['produto'];$item['categoria']=$mt['categoria'];}}unset($item);
    $pdfOk=$raw!=='';$hOk=$header['documento']!==''&&$header['numero_documento']!==''&&$header['data_documento']!=='';$pOk=$parceiro!=='';$iOk=!empty($itens);
?>
<div class="guia">
<h2><?=htmlspecialchars($label)?>
  <span class="pill <?=$pdfOk?'p-ok':'p-err'?>"><?=$pdfOk?'PDF ✓':'PDF ✗'?></span>
  <span class="pill <?=$hOk?'p-ok':'p-warn'?>"><?=$hOk?'Cabeçalho ✓':'Cabeçalho !'?></span>
  <span class="pill <?=$pOk?'p-ok':'p-err'?>"><?=$pOk?'Parceiro ✓':'Sem parceiro'?></span>
  <span class="pill <?=$iOk?'p-ok':'p-err'?>"><?=count($itens)?> item(s)</span>
</h2>

<div class="section">Cabeçalho</div>
<?php foreach(['Tipo de Guia'=>$header['documento'],'Nº Documento'=>$header['numero_documento'],'Data'=>$header['data_documento'],'Destinatário'=>$header['destinatario_nome'],'Parceiro Final'=>$parceiro] as $k=>$v):?>
<div class="row"><span class="lbl"><?=$k?></span><span class="mono <?=$v!==''?'ok':'err'?>"><?=htmlspecialchars($v?:'—')?></span></div>
<?php endforeach;?>

<?php if(!empty($itens)):?>
<div class="section" style="margin-top:12px">Peças (<?=count($itens)?>)</div>
<table><thead><tr><th>Tipo (raw)</th><th>Nome</th><th>Categoria</th><th>Nº Série</th><th>PAT</th><th>Qtd</th></tr></thead><tbody>
<?php foreach($itens as $item):?>
<tr>
  <td class="mono" style="font-size:.75rem"><?=htmlspecialchars($item['tipo']??'')?></td>
  <td><?=htmlspecialchars($item['nome_peca']??'')?></td>
  <td><?=!empty($item['categoria'])?"<span class='badge'>".htmlspecialchars($item['categoria'])."</span>":"<span class='err'>—</span>"?></td>
  <td class="mono <?=($item['sn']??'')!==''?'ok':'warn'?>"><?=htmlspecialchars(($item['sn']??'')?:' — ')?></td>
  <td class="mono" style="font-size:.75rem"><?=htmlspecialchars($item['pat']??'')?></td>
  <td><?=$item['qtd']??1?></td>
</tr>
<?php endforeach;?>
</tbody></table>
<?php else:?>
<p class="err">✗ Nenhum item extraído. Bloco encontrado: <?=$bloco!==''?'Sim':'Não'?></p>
<?php endif;?>

<?php if($bloco!==''):?>
<details style="margin-top:10px"><summary style="cursor:pointer;font-size:.8rem;color:#6b7280">Bloco de itens (texto bruto)</summary><pre><?=htmlspecialchars($bloco)?></pre></details>
<?php endif;?>
</div>
<?php endforeach;?>
<p style="color:#9ca3af;font-size:.8rem;margin-top:16px">⚠ Apaga: <code>C:\laragon\www\nvcloud\diagnostico_envios.php</code></p>
</body></html>
