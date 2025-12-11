# Changelog

Todas as mudanças notáveis neste projeto serão documentadas neste arquivo.

O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/),
e este projeto adere ao [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [1.0.4] - 2025-12-11

### Adicionado

- (A ser preenchido com as mudanças da versão)

## [1.0.3] - 2025-12-11

### Corrigido

- Correção definitiva do bug do token no servidor

### Estabilidade

- Versão estável e pronta para produção

## [1.0.2] - 2025-12-11

### Corrigido

- Correção de bug no armazenamento do token no servidor

## [1.0.1] - 2025-12-11

### Corrigido

- Arruma o script do FCM que pega o token do localstorage

## [1.0.0] - 2025-12-11

### Adicionado

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

### Conformidade

- Estrutura de diretórios conforme padrões GLPI
- Nomenclatura de plugin sem caracteres especiais (glpipwa)
- Função plugin_boot para registro de caminhos stateless
- Verificação de pré-requisitos usando array requirements
- Arquivos obrigatórios: setup.php, hook.php, README.md, LICENSE, CHANGELOG.md
