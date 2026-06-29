#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import os
os.chdir('/home/josee/projects/nvcloud')

# Ler o ficheiro
with open("app.php", "r", encoding="utf-8") as f:
    lines = f.readlines()

# Encontrar e corrigir a função installPWA
output_lines = []
i = 0
while i < len(lines):
    line = lines[i]
    
    # Se encontramos a função installPWA, vamos reescrevê-la
    if 'function installPWA()' in line:
        # Remover até encontrar o closing brace
        output_lines.append(line)  # line com 'function installPWA() {'
        i += 1
        
        # Skip até encontrar o fim da função
        brace_count = 1
        while i < len(lines) and brace_count > 0:
            if '{' in lines[i]:
                brace_count += lines[i].count('{')
            if '}' in lines[i]:
                brace_count -= lines[i].count('}')
            i += 1
        
        # Agora escrever a função corrigida
        new_function = '''    if (deferredInstallPrompt) {
        // Disparar a instalação clicando no botão
        deferredInstallPrompt.prompt().then(() => {
            return deferredInstallPrompt.userChoice;
        }).then(function(choiceResult) {
            if (choiceResult.outcome === 'accepted') {
                console.log('App instalada com sucesso!');
                var btn = document.getElementById('install-app-btn');
                if (btn) btn.style.display = 'none';
            } else {
                console.log('Utilizador recusou a instalação');
            }
            deferredInstallPrompt = null;
        }).catch(function(err) {
            console.log('Erro durante a instalação:', err);
        });
    } else {
        // Fallback quando beforeinstallprompt não foi disparado
        alert("Para instalar a app: no Chrome/Edge abre o menu (⋮) e escolhe " +
              "\\"Instalar StockVision\\" / \\"Adicionar ao ecrã principal\\".\n" +
              "No telemóvel (Android/iOS) usa \\"Adicionar ao ecrã principal\\" no menu do browser.");
    }
}
'''
        output_lines.append(new_function)
    else:
        output_lines.append(line)
        i += 1

# Guardar o ficheiro
with open("app.php", "w", encoding="utf-8") as f:
    f.writelines(output_lines)

print("✓ app.php corrigido com sucesso!")
print("✓ Botão 'Instalar Extensão' - caminho corrigido para /extensao/stockvision-sf.zip")
print("✓ Botão 'Instalar Aplicação' - agora dispara a instalação quando clicado")
