<?php
/** Mapa de transições permitidas entre estados de peça. */
function nvFluxoPecas(): array {
	return [
		'Devolução'              => ['Laboratório', 'Abater'],
		'Laboratório'            => ['Disponível', 'PAT', 'Fornecedor (Reparação)', 'Abater'],
		'Fornecedor (Reparação)' => ['Disponível', 'Laboratório', 'Abater'],
		'PAT'                    => ['Cliente', 'Devolução', 'Laboratório'],
		'Disponível'             => ['PAT', 'OT', 'Parceiro', 'Trânsito'],
		'Parceiro'               => ['Devolução', 'Cliente', 'Trânsito'],
		'Trânsito'               => ['Parceiro', 'Cliente', 'Laboratório', 'Disponível'],
		'Cliente'                => ['Devolução'],
		'OT'                     => ['Disponível', 'Laboratório', 'Abater'],
		'Desconhecido'           => ['Laboratório', 'Disponível', 'Abater'],
		'Spares'                 => ['Disponível', 'PAT', 'Abater'],
		'Abater'                 => [],   // estado terminal
	];
}
function nvTransicaoValida(string $de, string $para): bool {
	if ($de === $para) return true;
	$fluxo = nvFluxoPecas();
	return in_array($para, $fluxo[$de] ?? [], true);
}