# Ferramentas do Plugin GLPI PWA

Este diretório contém ferramentas auxiliares para desenvolvimento e manutenção do plugin.

## Scripts Disponíveis

### create-release.ps1 / create-release.sh

Script para automatizar a criação de releases do plugin.

#### Funcionalidades

- Detecta automaticamente a versão do `setup.php` ou permite especificar manualmente
- Cria tag git anotada para o release
- Gera arquivos de distribuição (tar.gz e zip)
- Organiza arquivos no diretório `releases/`
- Valida formato de versão semântica
- Verifica mudanças não commitadas antes de criar tag

#### Uso

**Windows (PowerShell):**
```powershell
# Usar versão do setup.php
.\tools\create-release.ps1

# Especificar versão manualmente
.\tools\create-release.ps1 -Version 1.0.1

# Criar apenas arquivos (sem tag git)
.\tools\create-release.ps1 -Version 1.0.1 -SkipTag
```

**Linux/Mac (Bash):**
```bash
# Dar permissão de execução (primeira vez)
chmod +x tools/create-release.sh

# Usar versão do setup.php
./tools/create-release.sh

# Especificar versão manualmente
./tools/create-release.sh -v 1.0.1

# Criar apenas arquivos (sem tag git)
./tools/create-release.sh --version 1.0.1 --skip-tag
```

#### Opções

- `-Version` / `-v`: Versão do release (ex: 1.0.1). Se não especificado, será lida do `setup.php`
- `-SkipTag` / `--skip-tag`: Não cria tag git, apenas os arquivos de distribuição
- `-Help` / `-h`: Exibe ajuda

#### Exemplo de Saída

```
========================================
  Criando Release 1.0.1
========================================

Versão detectada do setup.php: 1.0.1
Criando tag git: v1.0.1...
Tag v1.0.1 criada com sucesso!

Criando arquivo tar.gz...
✓ Arquivo criado: releases/glpipwa-1.0.1.tar.gz (79.28 KB)
Criando arquivo zip...
✓ Arquivo criado: releases/glpipwa-1.0.1.zip (112.76 KB)

Verificando conteúdo dos arquivos...
✓ Arquivo tar.gz contém 51 arquivos/diretórios

========================================
  Release 1.0.1 Criado com Sucesso!
========================================

Arquivos criados:
  - releases/glpipwa-1.0.1.tar.gz (79.28 KB)
  - releases/glpipwa-1.0.1.zip (112.76 KB)

Tag criada: v1.0.1

Para publicar o release, execute:
  git push origin v1.0.1
```

### create-github-release.ps1 / create-github-release.sh

Script para criar release no GitHub via API do GitHub.

#### Funcionalidades

- Cria release no GitHub automaticamente
- Usa conteúdo do CHANGELOG.md como descrição
- Suporta rascunhos e pré-releases
- Lista arquivos disponíveis para upload

#### Uso

**Windows (PowerShell):**
```powershell
# Usando variável de ambiente
$env:GITHUB_TOKEN = "seu-token"
.\tools\create-github-release.ps1 -Version 1.0.0

# Passando token diretamente
.\tools\create-github-release.ps1 -Version 1.0.0 -Token "seu-token"

# Criar como rascunho
.\tools\create-github-release.ps1 -Version 1.0.0 -Draft
```

**Linux/Mac (Bash):**
```bash
# Usando variável de ambiente
export GITHUB_TOKEN="seu-token"
./tools/create-github-release.sh -v 1.0.0

# Passando token diretamente
./tools/create-github-release.sh -v 1.0.0 -t "seu-token"
```

#### Obter Token do GitHub

1. Acesse: https://github.com/settings/tokens
2. Clique em "Generate new token" > "Generate new token (classic)"
3. Dê um nome ao token (ex: "GLPI PWA Releases")
4. Selecione o escopo `repo` ou `public_repo`
5. Clique em "Generate token"
6. Copie o token gerado (ele só aparece uma vez!)

**Nota:** O token precisa ter permissão para criar releases no repositório.

### compile-mo-new.js / compile-mo-new.php

Scripts para compilar arquivos de tradução `.po` para `.mo`.

**Node.js:**
```bash
node tools/compile-mo-new.js
```

**PHP:**
```bash
php tools/compile-mo-new.php
```

## Estrutura de Diretórios

```
tools/
├── create-release.ps1           # Script de release (Windows PowerShell)
├── create-release.sh             # Script de release (Linux/Mac Bash)
├── create-github-release.ps1    # Script para criar release no GitHub (PowerShell)
├── create-github-release.sh     # Script para criar release no GitHub (Bash)
├── compile-mo-new.js            # Compilador de traduções (Node.js)
├── compile-mo-new.php           # Compilador de traduções (PHP)
└── README.md                     # Este arquivo
```

## Requisitos

### Para create-release

- Git instalado e configurado
- PowerShell 5.1+ (Windows) ou Bash (Linux/Mac)
- Acesso ao repositório git do projeto

### Para create-github-release

- Token de acesso pessoal do GitHub com permissão `repo` ou `public_repo`
- PowerShell 5.1+ (Windows) ou Bash com `curl` e `jq` (Linux/Mac)
- Conexão com internet para acessar a API do GitHub

### Para compile-mo

- Node.js (para compile-mo-new.js) ou PHP (para compile-mo-new.php)
- Arquivos `.po` no diretório `locales/`

## Notas

- Os scripts de release criam arquivos no diretório `releases/` (criado automaticamente se não existir)
- Os arquivos gerados incluem apenas arquivos versionados no Git (respeitando `.gitignore`)
- A tag git criada é anotada e inclui uma mensagem descritiva
- Sempre teste os arquivos gerados antes de publicar o release

