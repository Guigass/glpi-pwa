#!/bin/bash
# Script para criar release no GitHub via API
# Requer token de acesso pessoal do GitHub
# Uso: ./tools/create-github-release.sh -v 1.0.0 -t <seu-token>

set -e

# Cores
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

# Variáveis padrão
VERSION=""
TOKEN="${GITHUB_TOKEN}"
REPO="Guigass/glpi-pwa"
DRAFT=false
PRERELEASE=false

# Função para exibir ajuda
show_help() {
    cat << EOF
Script para criar release no GitHub via API

USO:
    ./tools/create-github-release.sh -v <versão> [OPÇÕES]

PARÂMETROS OBRIGATÓRIOS:
    -v, --version <versão>    Versão do release (ex: 1.0.0)

OPÇÕES:
    -t, --token <token>       Token de acesso pessoal do GitHub
                              (ou defina variável GITHUB_TOKEN)
    -r, --repo <repo>         Repositório no formato owner/repo
                              (padrão: Guigass/glpi-pwa)
    --draft                   Criar como rascunho
    --prerelease              Marcar como pré-release
    -h, --help                Exibir ajuda

EXEMPLOS:
    # Usando variável de ambiente
    export GITHUB_TOKEN="seu-token"
    ./tools/create-github-release.sh -v 1.0.0

    # Passando token diretamente
    ./tools/create-github-release.sh -v 1.0.0 -t "seu-token"

    # Criar como rascunho
    ./tools/create-github-release.sh -v 1.0.0 --draft

NOTAS:
    - O token precisa ter permissão 'repo' ou 'public_repo'
    - Crie um token em: https://github.com/settings/tokens
    - Os arquivos de distribuição devem estar em releases/

EOF
}

# Processar argumentos
while [[ $# -gt 0 ]]; do
    case $1 in
        -v|--version)
            VERSION="$2"
            shift 2
            ;;
        -t|--token)
            TOKEN="$2"
            shift 2
            ;;
        -r|--repo)
            REPO="$2"
            shift 2
            ;;
        --draft)
            DRAFT=true
            shift
            ;;
        --prerelease)
            PRERELEASE=true
            shift
            ;;
        -h|--help)
            show_help
            exit 0
            ;;
        *)
            echo -e "${RED}ERRO: Opção desconhecida: $1${NC}"
            show_help
            exit 1
            ;;
    esac
done

# Validar versão
if [ -z "$VERSION" ]; then
    echo -e "${RED}ERRO: Versão não especificada${NC}"
    show_help
    exit 1
fi

# Validar formato da versão
if ! echo "$VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+'; then
    echo -e "${RED}ERRO: Versão inválida. Use o formato semântico (ex: 1.0.0)${NC}"
    exit 1
fi

# Validar token
if [ -z "$TOKEN" ]; then
    echo -e "${RED}ERRO: Token do GitHub não fornecido!${NC}"
    echo ""
    echo -e "${YELLOW}Opções:${NC}"
    echo -e "  1. Defina a variável de ambiente: export GITHUB_TOKEN='seu-token'"
    echo -e "  2. Passe o token como parâmetro: -t 'seu-token'"
    echo ""
    echo -e "${CYAN}Crie um token em: https://github.com/settings/tokens${NC}"
    exit 1
fi

TAG_NAME="v$VERSION"
API_URL="https://api.github.com/repos/$REPO/releases"

echo -e "${CYAN}Criando release no GitHub...${NC}"
echo -e "  Repositório: $REPO"
echo -e "  Tag: $TAG_NAME"
echo -e "  Versão: $VERSION"
echo ""

# Ler CHANGELOG para usar como descrição
CHANGELOG_CONTENT=""
if [ -f "CHANGELOG.md" ]; then
    IN_SECTION=false
    while IFS= read -r line; do
        if echo "$line" | grep -q "## \[$VERSION\]"; then
            IN_SECTION=true
            CHANGELOG_CONTENT+="$line"$'\n'
        elif [ "$IN_SECTION" = true ] && echo "$line" | grep -q "^## \["; then
            break
        elif [ "$IN_SECTION" = true ]; then
            CHANGELOG_CONTENT+="$line"$'\n'
        fi
    done < CHANGELOG.md
    
    if [ -z "$CHANGELOG_CONTENT" ]; then
        CHANGELOG_CONTENT="Release $VERSION do Plugin GLPI PWA

Consulte o CHANGELOG.md para mais detalhes."
    fi
else
    CHANGELOG_CONTENT="Release $VERSION do Plugin GLPI PWA"
fi

# Preparar dados do release
RELEASE_DATA=$(cat <<EOF
{
  "tag_name": "$TAG_NAME",
  "name": "Release $VERSION",
  "body": $(echo "$CHANGELOG_CONTENT" | jq -Rs .),
  "draft": $DRAFT,
  "prerelease": $PRERELEASE
}
EOF
)

# Criar release
echo -e "${GREEN}Enviando requisição para GitHub API...${NC}"
RESPONSE=$(curl -s -w "\n%{http_code}" -X POST \
    -H "Authorization: token $TOKEN" \
    -H "Accept: application/vnd.github.v3+json" \
    -H "Content-Type: application/json" \
    -d "$RELEASE_DATA" \
    "$API_URL")

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

if [ "$HTTP_CODE" -eq 201 ]; then
    echo ""
    echo -e "${GREEN}✓ Release criado com sucesso!${NC}"
    echo ""
    
    # Extrair URL do release (requer jq ou parsing manual)
    if command -v jq &> /dev/null; then
        RELEASE_URL=$(echo "$BODY" | jq -r '.html_url')
        RELEASE_ID=$(echo "$BODY" | jq -r '.id')
        echo -e "${CYAN}URL do release: $RELEASE_URL${NC}"
        echo -e "ID: $RELEASE_ID"
    else
        echo -e "${CYAN}Release criado com sucesso!${NC}"
        echo -e "Acesse: https://github.com/$REPO/releases/tag/$TAG_NAME"
    fi
    
    # Verificar arquivos para upload
    TAR_FILE="releases/glpipwa-$VERSION.tar.gz"
    ZIP_FILE="releases/glpipwa-$VERSION.zip"
    
    FILES_TO_UPLOAD=()
    [ -f "$TAR_FILE" ] && FILES_TO_UPLOAD+=("$TAR_FILE")
    [ -f "$ZIP_FILE" ] && FILES_TO_UPLOAD+=("$ZIP_FILE")
    
    if [ ${#FILES_TO_UPLOAD[@]} -gt 0 ]; then
        echo ""
        echo -e "${YELLOW}Arquivos encontrados para upload:${NC}"
        for file in "${FILES_TO_UPLOAD[@]}"; do
            echo -e "  - $file"
        done
        echo ""
        echo -e "${CYAN}Para fazer upload dos arquivos, use:${NC}"
        echo -e "  gh release upload $TAG_NAME ${FILES_TO_UPLOAD[*]}"
        echo ""
        echo -e "${CYAN}Ou faça upload manualmente pela interface web:${NC}"
        if command -v jq &> /dev/null; then
            echo -e "  $RELEASE_URL"
        else
            echo -e "  https://github.com/$REPO/releases/tag/$TAG_NAME"
        fi
    fi
else
    echo ""
    echo -e "${RED}ERRO ao criar release (HTTP $HTTP_CODE)${NC}"
    echo "$BODY" | jq '.' 2>/dev/null || echo "$BODY"
    exit 1
fi

