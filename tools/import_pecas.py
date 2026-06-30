#!/usr/bin/env python3
"""
import_pecas.py — Normaliza os 18 ficheiros TXT da pasta TOTAIS PEÇAS
e gera dois ficheiros:
  - pecas_import.sql        : INSERT pronto a executar na BD do StockVision
  - pecas_import_issues.txt : relatório de problemas encontrados

Uso (no WSL):
    cd /home/josee/projects/nvcloud
    python3 tools/import_pecas.py
"""

import glob
import os
import re
from datetime import datetime
from collections import defaultdict

# ─── Caminho dos ficheiros TXT (caminho WSL para o OneDrive) ───────────────
SRC_DIR = "/mnt/c/Users/josee/OneDrive/Ambiente de Trabalho/12.º ANO/Estágio - PAP/TOTAIS PEÇAS"

# ─── Mapa de normalização de nomes de produto ──────────────────────────────
# Corrige typos e variantes para a forma canónica a usar na BD.
NORMALIZE_PRODUTO = {
    # Botões WiFi
    "Botao Wifi":                   "Botão Wifi",
    "Botões wifi":                  "Botão Wifi",
    "botão wifi":                   "Botão Wifi",
    "botão Wifi":                   "Botão Wifi",

    # Box Android
    "Box ETE3999":                  "Box ETE3399",      # typo: 9 em vez de 3

    # Cabeçote Prima
    "Prima 15":                     "Cabeçote Prima 15",  # sem prefixo

    # Cabeçote Proxima
    "cabeçote Próxima":             "Cabeçote Proxima",   # capitalização/acento

    # Impressora
    "Nippom K3053":                 "Nippon K3053",
    "Impressora Nippon K3053":      "Nippon K3053",
    "Impressora Nippon":            "Nippon K3053",
    "Nippon 3053D":                 "Impressora Nippon 3053D",
    "Nippon 3053":                  "Nippon K3053",
    "Impressora K3053":             "Nippon K3053",
    'Impressora Prima 15"':         "Impressora Prima 15",
    "Impressora prima 12":          "Impressora Prima 12",
    "Prima 12":                     "Impressora Prima 12",
    "80mm Echarge":                 "Impressora 80mm E-charge",
    "Impressora 80mm Echarge":      "Impressora 80mm E-charge",

    # Leitor de Cartões
    "Leitor SPu90":                 "Leitor SPU90",
    "Leitor Spu90":                 "Leitor SPU90",
    "Leitor U900":                  "Leitor U900",

    # Mini PC
    "Mini-PC":                      "Mini PC",

    # Moedeiro
    "Smart Hopper Recyclerv":       "Smart Hopper Recycler",  # typo

    # Monitor
    "LCD Hisense 40\"":             "Hisense 40\"",
    "Hisense TV 40\"":              "Hisense 40\"",
    "TV Hisense 40\"":              "Hisense 40\"",

    # Router
    "Tp-Link 4G":                   "Router TP-Link 4G",

    # UPS
    "UPS/APC":                      "UPS APC",

    # PC Windows
    "PC KP1-AB5":                   "PC Insys KP1-AB5",
}


# ─── Parse ────────────────────────────────────────────────────────────────
def parse_txt_dir(src_dir):
    rows = []
    for filepath in sorted(glob.glob(os.path.join(src_dir, "*.txt"))):
        fname = os.path.basename(filepath)
        with open(filepath, encoding="utf-8") as fh:
            lines = fh.readlines()
        for line in lines:
            line = line.rstrip("\n")
            if not re.match(r"^\d+\t", line):
                continue
            parts = line.split("\t")
            if len(parts) < 9:
                continue
            rows.append({
                "file":       fname,
                "orig_id":    parts[0].strip(),
                "categoria":  parts[1].strip(),
                "produto":    parts[2].strip(),
                "sn":         parts[4].strip(),
                "cod_barras": parts[5].strip(),
                "parceiro":   parts[7].strip(),
                "estado":     parts[8].strip(),
            })
    return rows


# ─── Validações e issues ────────────────────────────────────────────────
def build_issues(rows, normalize_map):
    issues = []

    # 1. SN vazio ou inválido
    for r in rows:
        sn = r["sn"]
        if not sn or sn == "." or sn.upper() == "N/A":
            issues.append(("SN_VAZIO", r["file"], r["orig_id"], sn, r["cod_barras"], r["produto"]))

    # 2. SN ≠ Cód. Barras (possíveis typos de transcrição)
    for r in rows:
        sn = r["sn"]
        cb = r["cod_barras"]
        if sn and cb and sn != "." and cb != "." and sn.upper() != "N/A":
            if sn.upper() != cb.upper():
                issues.append(("SN_BARCODE_DIFF", r["file"], r["orig_id"], sn, cb, r["produto"]))

    # 3. SNs duplicados dentro dos ficheiros
    sn_map = defaultdict(list)
    for r in rows:
        if r["sn"] and r["sn"] != "." and r["sn"].upper() != "N/A":
            sn_map[r["sn"]].append(r)
    for sn, rs in sorted(sn_map.items()):
        if len(rs) > 1:
            for r in rs:
                issues.append((
                    "SN_DUPLICADO", r["file"], r["orig_id"], sn, r["cod_barras"],
                    f"{r['produto']} | estado={r['estado']} | parceiro={r['parceiro']}"
                ))

    # 4. Categorias suspeitas
    SUSPEITOS = {
        "Cabeçote Prima 15": "Provavelmente 'Cabeçote Prima', não 'Monitor'",
        "Cofre UBA":         "Provavelmente 'Cofre', não 'Noteiro'",
        "Proxima com pedestal Luz Oeiras": "Nome contém nome de cliente — verificar",
        "Fonte/UPS":         "Aparece tanto em 'Fonte de Alimentação' como em 'UPS' — verificar categoria",
    }
    for r in rows:
        if r["produto"] in SUSPEITOS:
            issues.append(("CATEGORIA_SUSPEITA", r["file"], r["orig_id"], r["produto"],
                           f"cat={r['categoria']}", SUSPEITOS[r["produto"]]))

    # 5. Produtos normalizados (informativo)
    for r in rows:
        if r["produto"] in normalize_map:
            issues.append(("PRODUTO_NORMALIZADO", r["file"], r["orig_id"],
                           r["produto"], "→", normalize_map[r["produto"]]))

    return issues


# ─── Geração do SQL de importação ─────────────────────────────────────────
def escape_sql(s):
    return s.replace("'", "''") if s else ""


def generate_sql(rows, normalize_map):
    lines = []
    lines.append("-- ============================================================")
    lines.append("-- StockVision — Import de peças a partir de TOTAIS PEÇAS")
    lines.append(f"-- Gerado em: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    lines.append(f"-- Total de linhas nos ficheiros: {len(rows)}")
    lines.append("-- REVÊ este ficheiro antes de correr!")
    lines.append("-- Para correr: mysql -u root nvcloud < pecas_import.sql")
    lines.append("-- ============================================================")
    lines.append("")
    lines.append("SET NAMES utf8mb4;")
    lines.append("START TRANSACTION;")
    lines.append("")

    seen_sns = {}  # sn → orig_id da 1ª ocorrência

    for r in rows:
        sn = r["sn"]

        # Ignora SNs inválidos
        if not sn or sn == "." or sn.upper() == "N/A":
            lines.append(
                f"-- [IGNORADO SN_VAZIO] orig_id={r['orig_id']} | "
                f"cat={r['categoria']} | prod={r['produto']}"
            )
            continue

        # SNs duplicados: mantém 1ª ocorrência, comenta as restantes
        if sn in seen_sns:
            lines.append(
                f"-- [IGNORADO SN_DUP] SN={sn} já inserido (orig_id={seen_sns[sn]}); "
                f"esta linha orig_id={r['orig_id']} estado={r['estado']} "
                f"parceiro={r['parceiro']}"
            )
            continue
        seen_sns[sn] = r["orig_id"]

        # Normaliza produto
        produto    = normalize_map.get(r["produto"], r["produto"])
        categoria  = r["categoria"]
        parceiro   = r["parceiro"]
        estado     = r["estado"]
        cod_barras = r["cod_barras"] if (r["cod_barras"] and r["cod_barras"] != ".") else sn

        lines.append(
            f"INSERT INTO pecas (categoria, produto, sn, cod_barras, parceiro, estado) "
            f"VALUES ("
            f"'{escape_sql(categoria)}', '{escape_sql(produto)}', '{escape_sql(sn)}', "
            f"'{escape_sql(cod_barras)}', '{escape_sql(parceiro)}', '{escape_sql(estado)}'"
            f");"
        )

    n_ignorados = len(rows) - len(seen_sns)
    lines.append("")
    lines.append("COMMIT;")
    lines.append("")
    lines.append(f"-- Peças inseridas:  {len(seen_sns)}")
    lines.append(f"-- Peças ignoradas:  {n_ignorados}  (SN vazio ou duplicado)")
    return "\n".join(lines)


# ─── Geração do relatório de issues ───────────────────────────────────────
def generate_report(issues, rows):
    lines = []
    lines.append("=" * 68)
    lines.append("RELATÓRIO DE PROBLEMAS — TOTAIS PEÇAS → StockVision Import")
    lines.append(f"Gerado em: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    lines.append(f"Total de peças nos ficheiros: {len(rows)}")
    lines.append("=" * 68)

    tipos = [
        ("SN_VAZIO",
         "SNs VAZIOS ou inválidos → estas linhas serão IGNORADAS no import"),
        ("SN_BARCODE_DIFF",
         "SN ≠ Código de Barras → import usa o SN; verifica se algum está errado"),
        ("SN_DUPLICADO",
         "SNs DUPLICADOS nos ficheiros → 1ª ocorrência inserida, restantes ignoradas"),
        ("CATEGORIA_SUSPEITA",
         "CATEGORIAS SUSPEITAS → produto parece pertencer a outra categoria"),
        ("PRODUTO_NORMALIZADO",
         "PRODUTOS NORMALIZADOS → typos/variantes corrigidas automaticamente"),
    ]

    for tipo, label in tipos:
        sub = [i for i in issues if i[0] == tipo]
        if not sub:
            continue
        lines.append(f"\n{'─' * 60}")
        lines.append(f"[{tipo}] — {len(sub)} ocorrências")
        lines.append(label)
        lines.append("─" * 60)
        for issue in sub:
            lines.append("  " + " | ".join(str(x) for x in issue[1:]))

    return "\n".join(lines)


# ─── Main ─────────────────────────────────────────────────────────────────
if __name__ == "__main__":
    script_dir  = os.path.dirname(os.path.abspath(__file__))
    # Gera os outputs na raiz do projeto (um nível acima de tools/)
    project_dir = os.path.dirname(script_dir)
    sql_path    = os.path.join(project_dir, "pecas_import.sql")
    report_path = os.path.join(project_dir, "pecas_import_issues.txt")

    print(f"A ler ficheiros de: {SRC_DIR}")
    if not os.path.isdir(SRC_DIR):
        print(f"ERRO: Pasta não encontrada: {SRC_DIR}")
        print("Garante que o WSL consegue aceder ao OneDrive via /mnt/c/")
        raise SystemExit(1)

    rows = parse_txt_dir(SRC_DIR)
    print(f"Total de linhas encontradas: {len(rows)}")

    issues = build_issues(rows, NORMALIZE_PRODUTO)
    sql    = generate_sql(rows, NORMALIZE_PRODUTO)
    report = generate_report(issues, rows)

    with open(sql_path, "w", encoding="utf-8") as f:
        f.write(sql)
    print(f"SQL gerado:       {sql_path}")

    with open(report_path, "w", encoding="utf-8") as f:
        f.write(report)
    print(f"Relatório gerado: {report_path}")

    # Resumo no terminal
    n = {t: sum(1 for i in issues if i[0] == t)
         for t in ["SN_VAZIO", "SN_DUPLICADO", "SN_BARCODE_DIFF",
                   "PRODUTO_NORMALIZADO", "CATEGORIA_SUSPEITA"]}
    print()
    print("RESUMO:")
    print(f"  SNs inválidos (ignorados no SQL):  {n['SN_VAZIO']}")
    print(f"  SNs duplicados nos ficheiros:      {n['SN_DUPLICADO']}")
    print(f"  Diferenças SN ≠ Cód.Barras:       {n['SN_BARCODE_DIFF']}")
    print(f"  Produtos normalizados (typos):     {n['PRODUTO_NORMALIZADO']}")
    print(f"  Categorias suspeitas (verificar):  {n['CATEGORIA_SUSPEITA']}")
    print()
    print("Próximos passos:")
    print("  1. Lê  pecas_import_issues.txt  e verifica os SNs duplicados")
    print("  2. Revê (e edita se precisares)  pecas_import.sql")
    print("  3. BD LOCAL:  mysql -u root nvcloud < pecas_import.sql")
    print("  4. Verifica:  http://nvcloud.test?page=inventario")
    print("  5. BD PROD:   mysql -h <host> -u <user> -p nvcloud < pecas_import.sql")
