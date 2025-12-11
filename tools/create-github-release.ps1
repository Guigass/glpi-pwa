#!/usr/bin/env pwsh
# Script para criar release no GitHub via API
# Requer token de acesso pessoal do GitHub
# Uso: ./tools/create-github-release.ps1 -Version 1.0.0 -Token <seu-token>

param(
    [Parameter(Mandatory=$true)]
    [string]$Version,
    
    [Parameter(Mandatory=$false)]
    [string]$Token = $env:GITHUB_TOKEN,
    
    [string]$Repo = "Guigass/glpi-pwa",
    
    [switch]$Draft = $false,
    
    [switch]$Prerelease = $false,
    
    [switch]$Help = $false
)

# Função para exibir ajuda
function Show-Help {
    Write-Host @"
Script para criar release no GitHub via API

USO:
    ./tools/create-github-release.ps1 -Version <versão> [OPÇÕES]

PARÂMETROS OBRIGATÓRIOS:
    -Version <versão>    Versão do release (ex: 1.0.0)

OPÇÕES:
    -Token <token>       Token de acesso pessoal do GitHub
                         (ou defina variável GITHUB_TOKEN)
    -Repo <repo>         Repositório no formato owner/repo
                         (padrão: Guigass/glpi-pwa)
    -Draft               Criar como rascunho
    -Prerelease          Marcar como pré-release
    -Help                Exibir ajuda

EXEMPLOS:
    # Usando variável de ambiente
    `$env:GITHUB_TOKEN = "seu-token"
    ./tools/create-github-release.ps1 -Version 1.0.0

    # Passando token diretamente
    ./tools/create-github-release.ps1 -Version 1.0.0 -Token "seu-token"

    # Criar como rascunho
    ./tools/create-github-release.ps1 -Version 1.0.0 -Draft

NOTAS:
    - O token precisa ter permissão 'repo' ou 'public_repo'
    - Crie um token em: https://github.com/settings/tokens
    - Os arquivos de distribuição devem estar em releases/

"@
}

if ($Help) {
    Show-Help
    exit 0
}

# Validar token
if ([string]::IsNullOrEmpty($Token)) {
    Write-Host "ERRO: Token do GitHub não fornecido!" -ForegroundColor Red
    Write-Host ""
    Write-Host "Opções:" -ForegroundColor Yellow
    Write-Host "  1. Defina a variável de ambiente: `$env:GITHUB_TOKEN = 'seu-token'" -ForegroundColor White
    Write-Host "  2. Passe o token como parâmetro: -Token 'seu-token'" -ForegroundColor White
    Write-Host ""
    Write-Host "Crie um token em: https://github.com/settings/tokens" -ForegroundColor Cyan
    exit 1
}

# Validar formato da versão
if ($Version -notmatch '^\d+\.\d+\.\d+') {
    Write-Host "ERRO: Versão inválida. Use o formato semântico (ex: 1.0.0)" -ForegroundColor Red
    exit 1
}

$TagName = "v$Version"
$ApiUrl = "https://api.github.com/repos/$Repo/releases"

Write-Host "Criando release no GitHub..." -ForegroundColor Cyan
Write-Host "  Repositório: $Repo" -ForegroundColor White
Write-Host "  Tag: $TagName" -ForegroundColor White
Write-Host "  Versão: $Version" -ForegroundColor White
Write-Host ""

# Ler CHANGELOG para usar como descrição
$ChangelogContent = ""
if (Test-Path "CHANGELOG.md") {
    $ChangelogLines = Get-Content "CHANGELOG.md"
    $InSection = $false
    $ChangelogContent = ""
    
    foreach ($line in $ChangelogLines) {
        if ($line -match "## \[$Version\]") {
            $InSection = $true
            $ChangelogContent += "$line`n"
        } elseif ($InSection -and $line -match "^## \[") {
            break
        } elseif ($InSection) {
            $ChangelogContent += "$line`n"
        }
    }
    
    if ([string]::IsNullOrWhiteSpace($ChangelogContent)) {
        $ChangelogContent = "Release $Version do Plugin GLPI PWA`n`nConsulte o CHANGELOG.md para mais detalhes."
    }
} else {
    $ChangelogContent = "Release $Version do Plugin GLPI PWA"
}

# Preparar dados do release
$ReleaseData = @{
    tag_name = $TagName
    name = "Release $Version"
    body = $ChangelogContent.Trim()
    draft = $Draft
    prerelease = $Prerelease
} | ConvertTo-Json

# Headers para autenticação
$Headers = @{
    "Authorization" = "token $Token"
    "Accept" = "application/vnd.github.v3+json"
    "Content-Type" = "application/json"
}

try {
    # Criar release
    Write-Host "Enviando requisição para GitHub API..." -ForegroundColor Green
    $Response = Invoke-RestMethod -Uri $ApiUrl -Method Post -Headers $Headers -Body $ReleaseData -ContentType "application/json"
    
    Write-Host ""
    Write-Host "✓ Release criado com sucesso!" -ForegroundColor Green
    Write-Host ""
    Write-Host "URL do release: $($Response.html_url)" -ForegroundColor Cyan
    Write-Host "ID: $($Response.id)" -ForegroundColor White
    
    # Verificar se há arquivos para upload
    $TarFile = "releases/glpipwa-$Version.tar.gz"
    $ZipFile = "releases/glpipwa-$Version.zip"
    
    $FilesToUpload = @()
    if (Test-Path $TarFile) { $FilesToUpload += $TarFile }
    if (Test-Path $ZipFile) { $FilesToUpload += $ZipFile }
    
    if ($FilesToUpload.Count -gt 0) {
        Write-Host ""
        Write-Host "Arquivos encontrados para upload:" -ForegroundColor Yellow
        foreach ($file in $FilesToUpload) {
            Write-Host "  - $file" -ForegroundColor White
        }
        Write-Host ""
        Write-Host "Para fazer upload dos arquivos, use:" -ForegroundColor Cyan
        Write-Host "  gh release upload $TagName $($FilesToUpload -join ' ')" -ForegroundColor White
        Write-Host ""
        Write-Host "Ou faça upload manualmente pela interface web:" -ForegroundColor Cyan
        Write-Host "  $($Response.html_url)" -ForegroundColor White
    }
    
} catch {
    Write-Host ""
    Write-Host "ERRO ao criar release:" -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    
    if ($_.Exception.Response) {
        $ErrorResponse = $_.Exception.Response.GetResponseStream()
        $Reader = New-Object System.IO.StreamReader($ErrorResponse)
        $ErrorBody = $Reader.ReadToEnd()
        Write-Host ""
        Write-Host "Resposta do servidor:" -ForegroundColor Yellow
        Write-Host $ErrorBody -ForegroundColor Yellow
    }
    
    exit 1
}

