-- ============================================================
-- Tabelas de gestão (Categorias, Estados, Parceiros,
-- Fabricantes, Produtos) - NVCloud
-- Dados semeados a partir das capturas de ecrã.
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS categorias (
    id   INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS estados (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    nome      VARCHAR(150) NOT NULL,
    descricao VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS parceiros (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    empresa           VARCHAR(200) NOT NULL,
    morada            VARCHAR(255) NULL,
    contato1_nome     VARCHAR(150) NULL,
    contato1_email    VARCHAR(150) NULL,
    contato1_telefone VARCHAR(50)  NULL,
    contato2_nome     VARCHAR(150) NULL,
    contato2_email    VARCHAR(150) NULL,
    contato2_telefone VARCHAR(50)  NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fabricantes (
    id   INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS produtos (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    nome          VARCHAR(200) NOT NULL,
    categoria_id  INT NULL,
    fabricante_id INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- CATEGORIAS ----------
INSERT INTO categorias (nome) VALUES
('Acetato'),('Botões WiFi'),('Box Android'),('Cabeçote Prima'),
('Cabeçote Proxima'),('Cabeçote Vision'),('Cabo'),('Carta Controladora'),
('Cofre'),('Controladora'),('Conversor'),('Cutter'),
('Dispensadora Prima'),('Fonte de Alimentação'),('Impressora'),
('Leitor de Cartões'),('Mini PC'),('Moedeiro'),('Monitor'),('Noteiro'),
('PC Windows'),('Peças Metálicas'),('Pinpad'),('Router'),('Selador 220V'),
('Touchsscreen'),('Transformador'),('UPS'),('Vídeo Extender');

-- ---------- ESTADOS ----------
INSERT INTO estados (nome, descricao) VALUES
('Abater', NULL),('Cliente', NULL),('Desconhecido', NULL),
('Devolução', NULL),('Disponível', NULL),('Fornecedor (Reparação)', NULL),
('Laboratório', NULL),('OT', NULL),('Parceiro', NULL),('PAT', NULL),
('Spares', NULL),('Trânsito', NULL);

-- ---------- PARCEIROS ----------
INSERT INTO parceiros
(empresa, morada, contato1_nome, contato1_email, contato1_telefone,
 contato2_nome, contato2_email, contato2_telefone) VALUES
('Assistencia 35', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
('Bravantic Bragança', 'Av. Das Forças Armadas nº.49, 5300-440 Bragança', 'Jorge Paulino', 'Jorge.Paulino@bravantic.com', '936547800', 'Flavio Lemos', 'Flavio.Lemos@bravantic.com', '966037805'),
('Bravantic Lisboa', NULL, 'Jorge Paulino', 'Jorge.Paulino@bravantic.com', '936547800', 'Flavio Lemos', 'Flavio.Lemos@bravantic.com', '966037805'),
('Bravantic Oliveira de Frades', NULL, 'Jorge Paulino', 'Jorge.Paulino@bravantic.com', '936547800', 'Flavio Lemos', 'Flavio.Lemos@bravantic.com', '966037805'),
('Bravantic Porto', NULL, 'Jorge Paulino', 'Jorge.Paulino@bravantic.com', '936547800', 'Flavio Lemos', 'Flavio.Lemos@bravantic.com', '966037805'),
('Cronotécnica Figueira da Foz', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
('Cronotecnica Lisboa', NULL, 'Nuno Carvalho', NULL, '961759866', 'Rui Matos', NULL, '932181043'),
('Cronotecnica Porto', NULL, 'Nuno Carvalho', NULL, '961759866', 'Rui Matos', NULL, '932181043'),
('Field Newvision', NULL, 'Fernando Fernandes', NULL, '937916634', 'Tiago Batista', NULL, '910113343'),
('Hisense', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
('Inforlandia', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
('J H Ornelas', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
('Konica Minolta DSO Covilhã', NULL, 'Marco Ribeiro', NULL, '933750247', 'Nuno Barbosa', NULL, '962190954'),
('Konica Minolta DSO Faro', NULL, 'Marco Ribeiro', NULL, '933750247', 'Nuno Barbosa', NULL, '962190954'),
('Konica Minolta DSO Lisboa', NULL, 'Marco Ribeiro', NULL, '933750247', 'Nuno Barbosa', NULL, '962190954'),
('Konica Minolta DSO Porto', NULL, 'Marco Ribeiro', NULL, '933750247', 'Nuno Barbosa', NULL, '962190954'),
('MC Computadores', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
('Newnote', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
('NEWVISION Technology Centre', NULL, 'Artur Trindade', NULL, '917021488', 'Fernando Fernandes', NULL, '937916634'),
('SVDI - RET (Ingenico)', NULL, 'Diogo Nunes', NULL, '214165920', NULL, NULL, NULL);
