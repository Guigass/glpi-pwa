# Changelog

Todas as mudanças notáveis neste projeto serão documentadas neste arquivo.

O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/),
e este projeto adere ao [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [1.1.0] - 2025-12-11

### Adicionado

- Sistema completo de gerenciamento de dispositivos PWA com rastreamento de visualização
- Classe `PluginGlpipwaDevice` para gerenciar dispositivos com device_id único
- Rastreamento de `last_seen_at`, `last_seen_ticket_id` e `last_seen_ticket_updated_at`
- Endpoint `update-last-seen.php` para atualizar status de visualização em tempo real
- Listagem completa de dispositivos registrados na página de configuração
- Exibição de informações detalhadas: usuário, device_id, plataforma e última visualização
- Função para remover dispositivos individuais ou todos de uma vez
- Migração automática de dados da tabela `glpi_plugin_glpipwa_tokens` para `glpi_plugin_glpipwa_devices`
- Rastreamento automático de visualização de tickets no frontend
- Rate-limiting para evitar spam de notificações
- Atualizações de traduções para português e inglês

### Alterado

- Sistema de notificações push agora usa rastreamento inteligente para suprimir notificações de tickets já visualizados
- Integração do `NotificationPush` com a nova classe `Device` para verificação de `last_seen_ticket_id`
- Melhorias no frontend com rastreamento automático de visualização ao navegar no GLPI
- Sistema de registro de dispositivos refatorado para usar device_id único por dispositivo
- Service Worker melhorado com melhor tratamento de notificações e validações
- `firebase-messaging-sw.php` com melhor gerenciamento de tokens
- `sw-proxy.php` com validações aprimoradas e melhor tratamento de erros
- Limpeza automática de tokens inválidos no localStorage
- Interface de configuração reorganizada com melhor organização e validações

### Refatorado

- Removidos arquivos obsoletos `register-sw.php` e `register-sw.js`
- Sistema de registro de dispositivos completamente refatorado
- Melhorias no tratamento de exceções e logs em todo o sistema
- Service Worker agora usa event target para verificação de ativação
- Carregamento de classes no `setup.php` melhorado com validações adicionais

### Documentação

- Atualização completa da documentação e configurações do plugin

## [1.0.6] - 2025-12-11

### Adicionado

- Listagem dos tokens registrados

### Alterado

- Notificar o grupo do chamado apenas na criação e não em todos os eventos

## [1.0.5] - 2025-12-11

### Corrigido

- Correção de notificação duplicada

### Alterado

- Ajustes nos ícones das notificações

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
