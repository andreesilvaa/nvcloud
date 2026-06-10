-- ============================================================
-- Migração do catálogo de produtos (antes hardcoded em app.php)
-- para a tabela `produtos`, ligando cada produto à sua categoria.
-- ============================================================
SET NAMES utf8mb4;

-- Categoria em falta no seed inicial (vinha só do catálogo)
INSERT INTO categorias (nome)
SELECT 'Botões' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM categorias WHERE nome = 'Botões');

-- Recomeçar limpo (re-executável sem duplicar)
TRUNCATE TABLE produtos;

INSERT INTO produtos (nome, categoria_id)
SELECT x.produto, c.id
FROM (
  SELECT 'Acetatos Prima 12 (26 UNIDADES)' AS produto, 'Acetato' AS categoria UNION ALL
  SELECT 'eGo', 'Botões' UNION ALL
  SELECT 'Botão WiFi', 'Botões WiFi' UNION ALL
  SELECT 'Box ETE3399', 'Box Android' UNION ALL
  SELECT 'Box KP8-YB1', 'Box Android' UNION ALL
  SELECT 'Box H068', 'Box Android' UNION ALL
  SELECT 'Box D039', 'Box Android' UNION ALL
  SELECT 'Prima 12', 'Cabeçote Prima' UNION ALL
  SELECT 'Prima 15', 'Cabeçote Prima' UNION ALL
  SELECT 'Proxima', 'Cabeçote Proxima' UNION ALL
  SELECT 'Proxima CGD', 'Cabeçote Proxima' UNION ALL
  SELECT 'Proxima Unilabs', 'Cabeçote Proxima' UNION ALL
  SELECT 'Proxima EPAL', 'Cabeçote Proxima' UNION ALL
  SELECT 'Proxima TML', 'Cabeçote Proxima' UNION ALL
  SELECT 'Proxima Windows', 'Cabeçote Proxima' UNION ALL
  SELECT 'Vision WiFi', 'Cabeçote Vision' UNION ALL
  SELECT 'Vision Ethernet', 'Cabeçote Vision' UNION ALL
  SELECT 'Controladora Genérica', 'Carta Controladora' UNION ALL
  SELECT 'Echarge', 'Cofre' UNION ALL
  SELECT 'WBA', 'Cofre' UNION ALL
  SELECT 'Prima Teclas Vodafone', 'Dispensadora Prima' UNION ALL
  SELECT 'Fonte/UPS', 'Fonte de Alimentação' UNION ALL
  SELECT 'Fonte Proxima', 'Fonte de Alimentação' UNION ALL
  SELECT 'Fonte 24V Prateada', 'Fonte de Alimentação' UNION ALL
  SELECT 'Nippon K3053', 'Impressora' UNION ALL
  SELECT 'Echarge 80mm', 'Impressora' UNION ALL
  SELECT 'Prima 12', 'Impressora' UNION ALL
  SELECT 'Prima 15', 'Impressora' UNION ALL
  SELECT 'Prima Teclas', 'Impressora' UNION ALL
  SELECT 'Leitor U900', 'Leitor de Cartões' UNION ALL
  SELECT 'Leitor SPU90', 'Leitor de Cartões' UNION ALL
  SELECT 'Leitor Spire', 'Leitor de Cartões' UNION ALL
  SELECT 'D039', 'Mini PC' UNION ALL
  SELECT 'N105', 'Mini PC' UNION ALL
  SELECT 'Smart Hopper Recycler', 'Moedeiro' UNION ALL
  SELECT 'Smart Hopper Validator', 'Moedeiro' UNION ALL
  SELECT 'Seleniko Touch', 'Monitor' UNION ALL
  SELECT 'LCD LD 32"', 'Monitor' UNION ALL
  SELECT 'Hisense 40"', 'Monitor' UNION ALL
  SELECT 'LCD Hisense 40"', 'Monitor' UNION ALL
  SELECT 'Hisense TV 50"', 'Monitor' UNION ALL
  SELECT 'LED 55" Profissional', 'Monitor' UNION ALL
  SELECT 'KEE Touch 17"', 'Monitor' UNION ALL
  SELECT 'MSM Box', 'Monitor' UNION ALL
  SELECT 'RVM 10"', 'Monitor' UNION ALL
  SELECT 'General Touch 17"', 'Monitor' UNION ALL
  SELECT 'KEE Touch 19"', 'Monitor' UNION ALL
  SELECT 'Hisense 43"', 'Monitor' UNION ALL
  SELECT 'UBA', 'Noteiro' UNION ALL
  SELECT 'Echarge', 'Noteiro' UNION ALL
  SELECT 'Insys KP1-AB5', 'PC Windows' UNION ALL
  SELECT 'Giada F108D', 'PC Windows' UNION ALL
  SELECT 'Hard PC', 'PC Windows' UNION ALL
  SELECT 'IP4-NB20', 'PC Windows' UNION ALL
  SELECT 'IP7-T09', 'PC Windows' UNION ALL
  SELECT 'Prima Asus 410', 'PC Windows' UNION ALL
  SELECT 'Prima Asus 610', 'PC Windows' UNION ALL
  SELECT 'Prima Intel DG41', 'PC Windows' UNION ALL
  SELECT 'U900', 'Pinpad' UNION ALL
  SELECT 'Spire', 'Pinpad' UNION ALL
  SELECT 'Ingénico', 'Pinpad' UNION ALL
  SELECT 'D-Link Eagle N300', 'Router' UNION ALL
  SELECT 'TP-Link 4G', 'Router' UNION ALL
  SELECT 'DepositVision', 'Selador 220V' UNION ALL
  SELECT '220V', 'Selador 220V' UNION ALL
  SELECT 'Fonte/UPS', 'Transformador' UNION ALL
  SELECT 'UPS/APC', 'UPS' UNION ALL
  SELECT 'Fonte/UPS', 'UPS' UNION ALL
  SELECT 'VGA', 'Vídeo Extender' UNION ALL
  SELECT 'VGA-JHA', 'Vídeo Extender' UNION ALL
  SELECT 'Digitus HDMI DS-55529', 'Vídeo Extender' UNION ALL
  SELECT 'VGA VE02ALR c/Transformador', 'Vídeo Extender'
) AS x
JOIN categorias c ON c.nome = x.categoria;
