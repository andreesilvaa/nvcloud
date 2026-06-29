#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import os
os.chdir('/home/josee/projects/nvcloud')

# Ler o ficheiro
with open("app.php", "r", encoding="utf-8") as f:
    content = f.read()

# Substituir o link da extensão - já foi feito com sed, mas vamos confirmar
if 'href="extensao/stockvision-sf.zip"' in content:
    content = content.replace(
        'href="extensao/stockvision-sf.zip"',
        'href="/extensao/stockvision-sf.zip"'
    )
    print("✓ Corrigido caminho da extensão")

# Modificar a função installPWA para melhor experiência
# Manter o prompt() mas adicionar melhor tratamento
old_installpwa = '''function installPWA() {
    if (deferredInstallPrompt) {
        deferredInstallPrompt.prompt();
        deferredInstallPrompt.userChoice.then(function(result) {
            if (result.outcome === 'accepted') {
                var btn = document.getElementById('install-app-btn');
                if (btn) btn.style.display = 'none';
            }
            deferredInstallPrompt = null;
        });
    } else {
        alert("Para instalar a app: no Chrome/Edge abre o menu (⋮) e escolhe " +
              "\\\"Instalar StockVision\\\" / \\\"Adicionar ao ecrã principal\\\".\\n" +
              "No telemóvel (Android/iOS) usa \\\"Adicionar ao ecrã principal\\\" no menu do browser.");
    }
}'''

new_installpwa = '''function installPWA() {
    if (deferredInstallPrompt) {
        // Disparar a instalação automaticamente
        deferredInstallPrompt.prompt().then(() => {
            return deferredInstallPrompt.userChoice;
        }).then(function(result) {
            if (result.outcome === 'accepted') {
                var btn = document.getElementById('install-app-btn');
                if (btn) btn.style.display = 'none';
                console.log('App instalada com sucesso!');
            } else {
                console.log('Instalação cancelada');
            }
            deferredInstallPrompt = null;
        }).catch(err => {
            console.error('Erro ao instalar:', err);
        });
    } else {
        alert("Para instalar a app: no Chrome/Edge abre o menu (⋮) e escolhe " +
              "\\\"Instalar StockVision\\\" / \\\"Adicionar ao ecrã principal\\\".\\n" +
              "No telemóvel (Android/iOS) usa \\\"Adicionar ao ecrã principal\\\" no menu do browser.");
    }
}'''

if old_installpwa in content:
    content = content.replace(old_installpwa, new_installpwa)
    print("✓ Função installPWA atualizada")
else:
    print("⚠ Função installPWA não encontrada exatamente - procurando padrão alternativo...")
    if 'function installPWA()' in content:
        print("! Função encontrada mas o formato é diferente. Verifying...")

# Guardar o ficheiro
with open("app.php", "w", encoding="utf-8") as f:
    f.write(content)

print("✓ Ficheiro app.php atualizado com sucesso!")
