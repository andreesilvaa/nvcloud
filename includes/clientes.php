<?php
/**
 * Helpers de clientes.
 *
 * NOTA: a tabela `clientes` é a lista oficial de contas da empresa (~3567 registos),
 * com a coluna `account_name`. Existe ainda uma coluna gerada `nome_normalizado`
 * (= LOWER(account_name)) para matching rápido. NÃO criamos contas novas a partir
 * de parsing de relatórios para não poluir a lista oficial — na importação usamos
 * apenas correspondência (match); se não houver, a peça fica "cliente_pendente".
 */

/** Normaliza um nome para comparação: minúsculas, sem acentos, espaços colapsados. */
function nvNormalizarCliente(string $nome): string
{
	$nome = trim($nome);
	if ($nome === '') return '';
	$nome = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome) ?: $nome;
	$nome = strtolower($nome);
	$nome = preg_replace('/[^a-z0-9]+/', ' ', $nome);
	return trim(preg_replace('/\s+/', ' ', $nome));
}

/**
 * Faz APENAS correspondência (não cria) contra a lista oficial de clientes.
 * Tenta, por ordem (cada passo só avança se o anterior não encontrar nada):
 * (1) match exato literal (string igual, ignorando maiúsc./minúsc. e espaços);
 * (2) match exato "normalizado" (compara versões sem acentos/pontuação de
 *     ambos os lados, de forma determinística, evitando escolher "o
 *     primeiro que aparecer" quando há vários clientes parecidos — ex.:
 *     "EDP Comercial - Ovar Agente Exclusivo" vs "EDP Comercial Amarante");
 * (3) frase das 2 primeiras palavras (aproximado);
 * (4) palavra significativa mais longa (aproximado, último recurso).
 * Devolve o id ou null.
 */
function nvMatchCliente(PDO $pdo, string $nome): ?int
{
	$nomeOriginal = trim($nome);
	if ($nomeOriginal === '') return null;

	// 1) Match exato literal (ex.: seleção direta da datalist no formulário,
	//    onde o valor é sempre exatamente igual ao account_name na BD).
	$st = $pdo->prepare("SELECT id FROM clientes WHERE LOWER(TRIM(account_name)) = LOWER(?) LIMIT 1");
	$st->execute([$nomeOriginal]);
	$id = $st->fetchColumn();
	if ($id) return (int)$id;

	$norm = nvNormalizarCliente($nomeOriginal);
	if ($norm === '') return null;

	$esc = fn(string $s): string => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);

	// 2) Match exato normalizado: vai buscar um conjunto de candidatos
	//    razoável (por uma palavra significativa) e compara, em PHP, a
	//    versão normalizada de cada candidato com $norm — só aceita se
	//    houver UMA correspondência exata, nunca "o primeiro parecido".
	$palavras = preg_split('/\s+/u', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
	$tokensOrdenados = $palavras;
	usort($tokensOrdenados, fn($a, $b) => mb_strlen($b) - mb_strlen($a));
	$tokenPrincipal = $tokensOrdenados[0] ?? '';
	if ($tokenPrincipal !== '' && mb_strlen($tokenPrincipal) >= 3) {
		$st = $pdo->prepare("SELECT id, account_name FROM clientes WHERE account_name LIKE ? LIMIT 200");
		$st->execute(['%' . $esc($tokenPrincipal) . '%']);
		$candidatos = $st->fetchAll();
		$matchExatoId = null;
		foreach ($candidatos as $cand) {
			if (nvNormalizarCliente((string)$cand['account_name']) === $norm) {
				// Mais do que um candidato normaliza para o mesmo texto —
				// não é seguro escolher à sorte, fica para a pesquisa aproximada.
				if ($matchExatoId !== null) { $matchExatoId = null; break; }
				$matchExatoId = (int)$cand['id'];
			}
		}
		if ($matchExatoId !== null) return $matchExatoId;
	}

	// 3) Frase das 2 primeiras palavras (aproximado)
	$frase = implode(' ', array_slice($palavras, 0, 2));
	if ($frase !== '') {
		$st = $pdo->prepare("SELECT id FROM clientes WHERE LOWER(account_name) LIKE ? ORDER BY id ASC LIMIT 1");
		$st->execute(['%' . $esc($frase) . '%']);
		$id = $st->fetchColumn();
		if ($id) return (int)$id;
	}

	// 4) Palavra significativa mais longa (aproximado, último recurso)
	$tokens = array_values(array_filter($palavras, fn($w) => mb_strlen($w) >= 4));
	usort($tokens, fn($a, $b) => mb_strlen($b) - mb_strlen($a));
	foreach ($tokens as $w) {
		$st = $pdo->prepare("SELECT id FROM clientes WHERE LOWER(account_name) LIKE ? ORDER BY id ASC LIMIT 1");
		$st->execute(['%' . $esc($w) . '%']);
		$id = $st->fetchColumn();
		if ($id) return (int)$id;
	}

	return null;
}

/**
 * Para uso MANUAL (Inventário / fluxo PAT): faz match e, se não existir,
 * cria a conta na lista oficial. Usar só quando o utilizador confirma o nome.
 */
function nvObterOuCriarCliente(PDO $pdo, string $nome): ?int
{
	$id = nvMatchCliente($pdo, $nome);
	if ($id) return $id;

	$nome = trim($nome);
	if ($nome === '') return null;

	$ins = $pdo->prepare("INSERT INTO clientes (account_name, type) VALUES (?, 'Customer')");
	$ins->execute([$nome]);
	return (int)$pdo->lastInsertId();
}
