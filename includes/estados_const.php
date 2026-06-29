<?php
// includes/estados_const.php — nomes canónicos de estados (evita strings soltas).

final class EstadoPeca {
	const DISPONIVEL  = 'Disponível';
	const PAT         = 'PAT';
	const LABORATORIO = 'Laboratório';
	const CLIENTE     = 'Cliente';
	const DEVOLUCAO   = 'Devolução';
	const PARCEIRO    = 'Parceiro';
	const SPARES      = 'Spares';
	const ABATER      = 'Abater';
	const FORNECEDOR  = 'Fornecedor (Reparação)';
	const TRANSITO    = 'Trânsito';
	const DESCONHECIDO= 'Desconhecido';

	/** Estados "finais" que bloqueiam alterações automáticas via relatório. */
	const FINAIS = [self::ABATER];
}

final class EstadoPat {
	const ABERTO     = 'Aberto';
	const EM_CURSO   = 'Em Curso';
	const RESOLVIDO  = 'Resolvido';
	const CONCLUIDO  = 'Concluído';
	const CANCELADO  = 'Cancelado';
	const FECHADOS   = [self::RESOLVIDO, self::CONCLUIDO, self::CANCELADO];
}
