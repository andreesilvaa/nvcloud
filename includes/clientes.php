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
 * Tenta: (1) match normalizado exato, (2) LIKE pela frase das 2 primeiras palavras,
 * (3) palavra significativa mais longa. Devolve o id ou null.
 */
function nvMatchCliente(PDO $pdo, string $nome): ?int
{
	$norm = nvNormalizarCliente($nome);
	if ($norm === '') return null;

	// 1) Exato (usa a coluna gerada se existir; LOWER como fallback)
	$st = $pdo->prepare("SELECT id FROM clientes WHERE LOWER(account_name) = ? LIMIT 1");
	$st->execute([$norm]);
	$id = $st->fetchColumn();
	if ($id) return (int)$id;

	// 2) Frase das 2 primeiras palavras
	$palavras = preg_split('/\s+/u', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
	$frase = implode(' ', array_slice($palavras, 0, 2));
	$esc = fn(string $s): string => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
	if ($frase !== '') {
		$st = $pdo->prepare("SELECT id FROM clientes WHERE LOWER(account_name) LIKE ? ORDER BY id ASC LIMIT 1");
		$st->execute(['%' . $esc($frase) . '%']);
		$id = $st->fetchColumn();
		if ($id) return (int)$id;
	}

	// 3) Palavra significativa mais longa (>= 4 letras)
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
