#!/usr/bin/env pwsh
# Script para criar releases do plugin GLPI PWA
# Uso: ./tools/create-release.ps1 [versão]
# Exemplo: ./tools/create-release.ps1 1.0.1

param(
    [string]$Version = "",
    [switch]$SkipTag = $false,
    [switch]$Help = $false
)

# Função para exibir ajuda
function Show-Help {
    Write-Host @"
Script para criar releases do plugin GLPI PWA

USO:
    ./tools/create-release.ps1 [OPÇÕES]

OPÇÕES:
    -Version <versão>    Versão do release (ex: 1.0.1)
                         Se não especificado, será lida do setup.php
    -SkipTag             Não cria tag git (apenas arquivos de distribuição)
    -Help                Exibe esta mensagem de ajuda

EXEMPLOS:
    ./tools/create-release.ps1
    ./tools/create-release.ps1 -Version 1.0.1
    ./tools/create-release.ps1 -Version 1.0.1 -SkipTag

"@
}

if ($Help) {
    Show-Help
    exit 0
}

# Verificar se estamos no diretório raiz do projeto
if (-not (Test-Path "setup.php")) {
    Write-Host "ERRO: Execute este script a partir do diretório raiz do projeto" -ForegroundColor Red
    exit 1
}

# Ler versão do setup.php se não foi especificada
if ([string]::IsNullOrEmpty($Version)) {
    $setupContent = Get-Content "setup.php" -Raw
    if ($setupContent -match "define\('PLUGIN_GLPIPWA_VERSION',\s*'([^']+)'\)") {
        $Version = $matches[1]
        Write-Host "Versão detectada do setup.php: $Version" -ForegroundColor Cyan
    } else {
        Write-Host "ERRO: Não foi possível detectar a versão no setup.php" -ForegroundColor Red
        Write-Host "Por favor, especifique a versão com -Version <versão>" -ForegroundColor Yellow
        exit 1
    }
}

# Validar formato da versão (semântico)
if ($Version -notmatch '^\d+\.\d+\.\d+') {
    Write-Host "ERRO: Versão inválida. Use o formato semântico (ex: 1.0.1)" -ForegroundColor Red
    exit 1
}

$TagName = "v$Version"
$ReleaseDir = "releases"
$TarFile = "$ReleaseDir/glpipwa-$Version.tar.gz"
$ZipFile = "$ReleaseDir/glpipwa-$Version.zip"

Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "  Criando Release $Version" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

# Verificar se a tag já existe
$TagExists = git tag -l $TagName
if ($TagExists -and -not $SkipTag) {
    Write-Host "AVISO: A tag $TagName já existe!" -ForegroundColor Yellow
    $response = Read-Host "Deseja continuar mesmo assim? (s/N)"
    if ($response -ne "s" -and $response -ne "S") {
        Write-Host "Operação cancelada." -ForegroundColor Yellow
        exit 0
    }
}

# Verificar se há mudanças não commitadas
$GitStatus = git status --porcelain
if ($GitStatus -and -not $SkipTag) {
    Write-Host "AVISO: Há mudanças não commitadas no repositório!" -ForegroundColor Yellow
    $response = Read-Host "Deseja continuar mesmo assim? (s/N)"
    if ($response -ne "s" -and $response -ne "S") {
        Write-Host "Operação cancelada. Faça commit das mudanças primeiro." -ForegroundColor Yellow
        exit 0
    }
}

# Criar tag se necessário
if (-not $SkipTag) {
    Write-Host "Criando tag git: $TagName..." -ForegroundColor Green
    
    # Verificar se o commit atual tem a tag
    $CurrentCommit = git rev-parse HEAD
    $TagAtCommit = git tag --points-at $CurrentCommit
    
    if ($TagAtCommit -notcontains $TagName) {
        $TagMessage = "Release $Version - Plugin GLPI PWA"
        git tag -a $TagName -m $TagMessage
        
        if ($LASTEXITCODE -ne 0) {
            Write-Host "ERRO: Falha ao criar tag git" -ForegroundColor Red
            exit 1
        }
        Write-Host "Tag $TagName criada com sucesso!" -ForegroundColor Green
    } else {
        Write-Host "Tag $TagName já existe para este commit." -ForegroundColor Yellow
    }
} else {
    Write-Host "Pulando criação de tag (usando HEAD)..." -ForegroundColor Yellow
    $TagName = "HEAD"
}

# Criar diretório releases se não existir
if (-not (Test-Path $ReleaseDir)) {
    New-Item -ItemType Directory -Path $ReleaseDir | Out-Null
    Write-Host "Diretório $ReleaseDir criado." -ForegroundColor Green
}

# Remover arquivos antigos se existirem
if (Test-Path $TarFile) {
    Remove-Item $TarFile -Force
    Write-Host "Arquivo antigo removido: $TarFile" -ForegroundColor Yellow
}
if (Test-Path $ZipFile) {
    Remove-Item $ZipFile -Force
    Write-Host "Arquivo antigo removido: $ZipFile" -ForegroundColor Yellow
}

# Criar arquivo tar.gz
Write-Host "`nCriando arquivo tar.gz..." -ForegroundColor Green
git archive --format=tar.gz --prefix=glpipwa-$Version/ -o $TarFile $TagName

if ($LASTEXITCODE -ne 0) {
    Write-Host "ERRO: Falha ao criar arquivo tar.gz" -ForegroundColor Red
    exit 1
}

$TarSize = (Get-Item $TarFile).Length / 1KB
Write-Host "✓ Arquivo criado: $TarFile ($([math]::Round($TarSize, 2)) KB)" -ForegroundColor Green

# Criar arquivo zip
Write-Host "Criando arquivo zip..." -ForegroundColor Green
git archive --format=zip --prefix=glpipwa-$Version/ -o $ZipFile $TagName

if ($LASTEXITCODE -ne 0) {
    Write-Host "ERRO: Falha ao criar arquivo zip" -ForegroundColor Red
    exit 1
}

$ZipSize = (Get-Item $ZipFile).Length / 1KB
Write-Host "✓ Arquivo criado: $ZipFile ($([math]::Round($ZipSize, 2)) KB)" -ForegroundColor Green

# Verificar conteúdo dos arquivos
Write-Host "`nVerificando conteúdo dos arquivos..." -ForegroundColor Green
$FileCount = (tar -tzf $TarFile | Measure-Object -Line).Lines
Write-Host "✓ Arquivo tar.gz contém $FileCount arquivos/diretórios" -ForegroundColor Green

# Resumo final
Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "  Release $Version Criado com Sucesso!" -ForegroundColor Green
Write-Host "========================================`n" -ForegroundColor Cyan

Write-Host "Arquivos criados:" -ForegroundColor Yellow
Write-Host "  - $TarFile ($([math]::Round($TarSize, 2)) KB)" -ForegroundColor White
Write-Host "  - $ZipFile ($([math]::Round($ZipSize, 2)) KB)" -ForegroundColor White

if (-not $SkipTag) {
    Write-Host "`nTag criada: $TagName" -ForegroundColor Yellow
    Write-Host "`nPara publicar o release, execute:" -ForegroundColor Cyan
    Write-Host "  git push origin $TagName" -ForegroundColor White
}

Write-Host "`nPróximos passos:" -ForegroundColor Cyan
Write-Host "  1. Testar os arquivos de distribuição" -ForegroundColor White
Write-Host "  2. Criar release no GitHub (se aplicável)" -ForegroundColor White
Write-Host "  3. Fazer upload dos arquivos para distribuição" -ForegroundColor White
Write-Host ""

