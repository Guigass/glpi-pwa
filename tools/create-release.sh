#!/bin/bash
# Script para criar releases do plugin GLPI PWA
# Uso: ./tools/create-release.sh [versão]
# Exemplo: ./tools/create-release.sh 1.0.1

set -e

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Função para exibir ajuda
show_help() {
    cat << EOF
Script para criar releases do plugin GLPI PWA

USO:
    ./tools/create-release.sh [OPÇÕES]

OPÇÕES:
    -v, --version <versão>    Versão do release (ex: 1.0.1)
                              Se não especificado, será lida do setup.php
    --skip-tag                Não cria tag git (apenas arquivos de distribuição)
    -h, --help                 Exibe esta mensagem de ajuda

EXEMPLOS:
    ./tools/create-release.sh
    ./tools/create-release.sh -v 1.0.1
    ./tools/create-release.sh --version 1.0.1 --skip-tag

EOF
}

# Variáveis padrão
VERSION=""
SKIP_TAG=false

# Processar argumentos
while [[ $# -gt 0 ]]; do
    case $1 in
        -v|--version)
            VERSION="$2"
            shift 2
            ;;
        --skip-tag)
            SKIP_TAG=true
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

# Verificar se estamos no diretório raiz do projeto
if [ ! -f "setup.php" ]; then
    echo -e "${RED}ERRO: Execute este script a partir do diretório raiz do projeto${NC}"
    exit 1
fi

# Ler versão do setup.php se não foi especificada
if [ -z "$VERSION" ]; then
    if grep -q "define('PLUGIN_GLPIPWA_VERSION'" setup.php; then
        VERSION=$(grep "define('PLUGIN_GLPIPWA_VERSION'" setup.php | sed "s/.*'\([^']*\)'.*/\1/")
        echo -e "${CYAN}Versão detectada do setup.php: $VERSION${NC}"
    else
        echo -e "${RED}ERRO: Não foi possível detectar a versão no setup.php${NC}"
        echo -e "${YELLOW}Por favor, especifique a versão com -v <versão>${NC}"
        exit 1
    fi
fi

# Validar formato da versão (semântico)
if ! echo "$VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+'; then
    echo -e "${RED}ERRO: Versão inválida. Use o formato semântico (ex: 1.0.1)${NC}"
    exit 1
fi

TAG_NAME="v$VERSION"
RELEASE_DIR="releases"
TAR_FILE="$RELEASE_DIR/glpipwa-$VERSION.tar.gz"
ZIP_FILE="$RELEASE_DIR/glpipwa-$VERSION.zip"

echo ""
echo -e "${CYAN}========================================${NC}"
echo -e "${CYAN}  Criando Release $VERSION${NC}"
echo -e "${CYAN}========================================${NC}"
echo ""

# Verificar se a tag já existe
if git rev-parse "$TAG_NAME" >/dev/null 2>&1 && [ "$SKIP_TAG" = false ]; then
    echo -e "${YELLOW}AVISO: A tag $TAG_NAME já existe!${NC}"
    read -p "Deseja continuar mesmo assim? (s/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Ss]$ ]]; then
        echo -e "${YELLOW}Operação cancelada.${NC}"
        exit 0
    fi
fi

# Verificar se há mudanças não commitadas
if [ -n "$(git status --porcelain)" ] && [ "$SKIP_TAG" = false ]; then
    echo -e "${YELLOW}AVISO: Há mudanças não commitadas no repositório!${NC}"
    read -p "Deseja continuar mesmo assim? (s/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Ss]$ ]]; then
        echo -e "${YELLOW}Operação cancelada. Faça commit das mudanças primeiro.${NC}"
        exit 0
    fi
fi

# Criar tag se necessário
if [ "$SKIP_TAG" = false ]; then
    echo -e "${GREEN}Criando tag git: $TAG_NAME...${NC}"
    
    # Verificar se o commit atual tem a tag
    CURRENT_COMMIT=$(git rev-parse HEAD)
    TAG_AT_COMMIT=$(git tag --points-at "$CURRENT_COMMIT" 2>/dev/null || true)
    
    if ! echo "$TAG_AT_COMMIT" | grep -q "$TAG_NAME"; then
        TAG_MESSAGE="Release $VERSION - Plugin GLPI PWA"
        git tag -a "$TAG_NAME" -m "$TAG_MESSAGE"
        
        if [ $? -ne 0 ]; then
            echo -e "${RED}ERRO: Falha ao criar tag git${NC}"
            exit 1
        fi
        echo -e "${GREEN}Tag $TAG_NAME criada com sucesso!${NC}"
    else
        echo -e "${YELLOW}Tag $TAG_NAME já existe para este commit.${NC}"
    fi
else
    echo -e "${YELLOW}Pulando criação de tag (usando HEAD)...${NC}"
    TAG_NAME="HEAD"
fi

# Criar diretório releases se não existir
if [ ! -d "$RELEASE_DIR" ]; then
    mkdir -p "$RELEASE_DIR"
    echo -e "${GREEN}Diretório $RELEASE_DIR criado.${NC}"
fi

# Remover arquivos antigos se existirem
if [ -f "$TAR_FILE" ]; then
    rm -f "$TAR_FILE"
    echo -e "${YELLOW}Arquivo antigo removido: $TAR_FILE${NC}"
fi
if [ -f "$ZIP_FILE" ]; then
    rm -f "$ZIP_FILE"
    echo -e "${YELLOW}Arquivo antigo removido: $ZIP_FILE${NC}"
fi

# Criar arquivo tar.gz
echo ""
echo -e "${GREEN}Criando arquivo tar.gz...${NC}"
git archive --format=tar.gz --prefix=glpipwa-$VERSION/ -o "$TAR_FILE" "$TAG_NAME"

if [ $? -ne 0 ]; then
    echo -e "${RED}ERRO: Falha ao criar arquivo tar.gz${NC}"
    exit 1
fi

TAR_SIZE=$(du -h "$TAR_FILE" | cut -f1)
echo -e "${GREEN}✓ Arquivo criado: $TAR_FILE ($TAR_SIZE)${NC}"

# Criar arquivo zip
echo -e "${GREEN}Criando arquivo zip...${NC}"
git archive --format=zip --prefix=glpipwa-$VERSION/ -o "$ZIP_FILE" "$TAG_NAME"

if [ $? -ne 0 ]; then
    echo -e "${RED}ERRO: Falha ao criar arquivo zip${NC}"
    exit 1
fi

ZIP_SIZE=$(du -h "$ZIP_FILE" | cut -f1)
echo -e "${GREEN}✓ Arquivo criado: $ZIP_FILE ($ZIP_SIZE)${NC}"

# Verificar conteúdo dos arquivos
echo ""
echo -e "${GREEN}Verificando conteúdo dos arquivos...${NC}"
FILE_COUNT=$(tar -tzf "$TAR_FILE" | wc -l)
echo -e "${GREEN}✓ Arquivo tar.gz contém $FILE_COUNT arquivos/diretórios${NC}"

# Resumo final
echo ""
echo -e "${CYAN}========================================${NC}"
echo -e "${GREEN}  Release $VERSION Criado com Sucesso!${NC}"
echo -e "${CYAN}========================================${NC}"
echo ""

echo -e "${YELLOW}Arquivos criados:${NC}"
echo -e "  - $TAR_FILE ($TAR_SIZE)"
echo -e "  - $ZIP_FILE ($ZIP_SIZE)"

if [ "$SKIP_TAG" = false ]; then
    echo ""
    echo -e "${YELLOW}Tag criada: $TAG_NAME${NC}"
    echo ""
    echo -e "${CYAN}Para publicar o release, execute:${NC}"
    echo -e "  git push origin $TAG_NAME"
fi

echo ""
echo -e "${CYAN}Próximos passos:${NC}"
echo -e "  1. Testar os arquivos de distribuição"
echo -e "  2. Criar release no GitHub (se aplicável)"
echo -e "  3. Fazer upload dos arquivos para distribuição"
echo ""

