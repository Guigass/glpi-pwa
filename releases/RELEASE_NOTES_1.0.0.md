# Release 1.0.0 - Plugin GLPI PWA

## Adicionado

- Suporte a Progressive Web App (PWA) para GLPI
- Integração com Firebase Cloud Messaging para notificações push
- Service Worker para cache offline e gerenciamento de notificações
- Sistema de registro de tokens FCM por usuário
- Notificações automáticas para:
  - Novos chamados criados
  - Atualizações em chamados (atribuição, status)
  - Novos follow-ups adicionados
- Página de configuração para Firebase e opções PWA
- Suporte a upload de ícones personalizados (192x192 e 512x512)
- Configuração de cores do tema, modo de exibição e orientação
- Manifest.json dinâmico baseado nas configurações
- Tradução para português brasileiro

## Conformidade

- Estrutura de diretórios conforme padrões GLPI
- Nomenclatura de plugin sem caracteres especiais (glpipwa)
- Função plugin_boot para registro de caminhos stateless
- Verificação de pré-requisitos usando array requirements
- Arquivos obrigatórios: setup.php, hook.php, README.md, LICENSE, CHANGELOG.md

## Instalação

1. Baixe o arquivo `glpipwa-1.0.0.zip` ou `glpipwa-1.0.0.tar.gz`
2. Extraia o conteúdo
3. Copie a pasta `glpipwa-1.0.0` para o diretório `plugins` do seu GLPI
4. Renomeie para `glpipwa` (sem o número da versão)
5. Acesse o GLPI como administrador
6. Vá em **Configuração > Plugins**
7. Instale e ative o plugin **GLPI PWA**
8. Configure o Firebase na página de configuração do plugin

## Requisitos

- GLPI 11.0 ou superior
- PHP 8.2 ou superior
- Extensão PHP cURL
- Conta Firebase com Cloud Messaging configurado
- HTTPS (obrigatório para PWA)

