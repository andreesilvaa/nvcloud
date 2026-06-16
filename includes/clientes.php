<?php

/** Normaliza Nomes */
function nvNormalizarCliente(string $nome): string
{
	$nome = trim($nome);
	if ($nome === '') return '';
	$nome = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome) ?: $nome;
	$nome = strtolower($nome);
	$nome = preg_replace('/[^a-z0-9]+/', ' ', $nome);
	return trim(preg_replace('/\s+/', ' ', $nome));
}

/** Devolve o ID do Cliente */
function nvObterOuCriarCliente(PDO $pdo, string $nome): ?int
{
	$norm = nvNormalizarCliente($nome);
	if ($norm === '') return null;

	$st = $pdo->prepare("SELECT id FROM clientes WHERE nome_normalizado = ? LIMIT 1");
	$st->execute([$norm]);
	$id = $st->fetchColumn();
	if ($id) return (int)$id;

	$ins = $pdo->prepare("INSERT INTO clientes (nome, nome_normalizado) VALUES (?, ?)");
	$ins->execute([trim($nome), $norm]);
	return (int)$pdo->lastInsertId();
}

/** Match do Cliente */
function nvMatchCliente(PDO $pdo, string $nome): ?int
{
	$norm = nvNormalizarCliente($nome);
	if ($norm === '') return null;
	$st = $pdo->prepare("SELECT id FROM clientes WHERE nome_normalizado = ? LIMIT 1");
	$st->execute([$norm]);
	$id = $st->fetchColumn();
	return $id ? (int)$id : null;
}