# Plugin GLPI PWA

Plugin para transformar o GLPI em uma Progressive Web App (PWA) com suporte a notificações push via Firebase Cloud Messaging.

## Desenvolvimento

Este projeto é desenvolvido usando **vibe coding**.

## Características

- **Progressive Web App (PWA)**: Transforma o GLPI em um aplicativo instalável
- **Notificações Push**: Receba notificações em tempo real sobre novos chamados e atualizações
- **Service Worker**: Suporte offline limitado e cache de recursos
- **Firebase Cloud Messaging**: Integração com FCM para entrega de notificações
- **Escopo Ampliado**: Service Worker controla todo o GLPI via proxy PHP

## Requisitos

- GLPI 11.0 ou superior
- PHP 8.2 ou superior
- Extensão PHP cURL
- Extensão PHP GD (para processamento de ícones)
- Conta Firebase com Cloud Messaging configurado
- HTTPS (obrigatório para PWA)

## Instalação

1. Baixe ou clone este repositório
2. Copie a pasta `glpipwa` para o diretório `plugins` do GLPI
3. Acesse o GLPI como administrador
4. Vá em **Configuração > Plugins**
5. Instale e ative o plugin **GLPI PWA**
6. Configure o Firebase na página de configuração do plugin

## Configuração

### Firebase Cloud Messaging

1. Crie um projeto no [Firebase Console](https://console.firebase.google.com)
2. Adicione um app Web ao projeto
3. Obtenha as credenciais:
   - API Key
   - Project ID
   - Messaging Sender ID
   - App ID
   - VAPID Key (em Cloud Messaging > Web Push)
   - Server Key (em Cloud Messaging > Server Key)
4. Insira essas credenciais na página de configuração do plugin

### PWA

Configure as opções do PWA na página de configuração:

- Nome da aplicação
- Cores do tema
- Ícones (192x192 e 512x512 pixels)
- URL inicial
- Modo de exibição

## Uso

Após a configuração, os usuários podem:

1. Acessar o GLPI pelo navegador
2. Instalar o GLPI como PWA (botão de instalação aparecerá no navegador)
3. Permitir notificações quando solicitado
4. Receber notificações push sobre eventos do GLPI

## Eventos que Disparam Notificações

- Novo chamado criado
- Chamado atualizado (atribuição, status)
- Novo follow-up adicionado
- Chamado resolvido/fechado

## Estrutura do Plugin

```
glpipwa/
├── setup.php                       # Inicialização e hooks do plugin
├── hook.php                        # Funções de hook (install, uninstall, eventos)
├── composer.json                   # Dependências e metadados
├── glpipwa.xml                     # Metadados do plugin
│
├── inc/                            # Classes PHP (Backend)
│   ├── Config.php                  # Gerenciamento de configurações
│   ├── Token.php                   # Gerenciamento de tokens FCM
│   ├── NotificationPush.php        # Envio de notificações via FCM
│   ├── Manifest.php                # Geração do manifest.json
│   └── Icon.php                    # Upload e processamento de ícones
│
├── front/                          # Interface web e endpoints
│   ├── config.form.php             # Tela de configuração do admin
│   ├── manifest.php                # Endpoint do manifest PWA
│   ├── register.php                # Registro de tokens FCM (com CSRF)
│   ├── firebase-config.php         # Configuração Firebase para frontend
│   ├── sw-proxy.php                # Proxy do Service Worker (escopo ampliado)
│   ├── firebase-messaging-sw.php   # SW dedicado para Firebase Messaging
│   └── ajax/
│       └── notification.php        # Endpoints AJAX de notificação
│
├── js/                             # JavaScript (Frontend)
│   ├── register-sw.js              # Registro do SW e inicialização Firebase
│   └── sw.js                       # Service Worker (fallback)
│
├── pics/                           # Ícones e imagens
│   └── glpipwa.png                 # Ícone do plugin
│
├── locale/                         # Traduções
│   ├── pt_BR.po                    # Strings em português
│   └── pt_BR.mo                    # Arquivo compilado
│
└── tools/                          # Scripts auxiliares
    ├── compile-mo.js               # Compilador .po → .mo (Node.js)
    └── compile-mo.php              # Compilador .po → .mo (PHP)
```

## Arquitetura

```
┌─────────────────────────────────────────────────────────────┐
│                        FRONTEND                              │
├─────────────────────────────────────────────────────────────┤
│  register-sw.js                                             │
│  ├── Registra Service Worker via sw-proxy.php               │
│  ├── Carrega Firebase SDK (compat)                          │
│  ├── Solicita permissão de notificação                      │
│  └── Envia token FCM para register.php                      │
├─────────────────────────────────────────────────────────────┤
│  Service Worker (sw-proxy.php)                              │
│  ├── Cache de recursos (network-first)                      │
│  ├── Recebe notificações push                               │
│  └── Gerencia cliques em notificações                       │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                        BACKEND                               │
├─────────────────────────────────────────────────────────────┤
│  Hooks (setup.php, hook.php)                                │
│  ├── item_add: Ticket, ITILFollowup                         │
│  └── item_update: Ticket                                    │
├─────────────────────────────────────────────────────────────┤
│  NotificationPush.php                                       │
│  ├── Identifica destinatários                               │
│  ├── Busca tokens na tabela glpi_plugin_glpipwa_tokens      │
│  └── Envia para Firebase FCM                                │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                    FIREBASE FCM                              │
│  └── Distribui notificações para dispositivos               │
└─────────────────────────────────────────────────────────────┘
```

### Compilando Traduções

Para compilar arquivos `.po` para `.mo`:

```bash
# Usando Node.js
node tools/compile-mo.js

# Ou usando PHP
php tools/compile-mo.php

# Ou usando gettext (se disponível)
msgfmt -o locale/pt_BR.mo locale/pt_BR.po
```

### Padrões de Código

- PHP: PSR-12
- JavaScript: ES6+
- Nomenclatura:
  - `PascalCase` para classes
  - `camelCase` para métodos e variáveis
  - Prefixo `PluginGlpipwa` para classes do plugin

### Contribuindo

1. Faça um fork do projeto
2. Crie uma branch para sua feature (`git checkout -b feature/nova-feature`)
3. Faça commit das mudanças (`git commit -m 'Adiciona nova feature'`)
4. Push para a branch (`git push origin feature/nova-feature`)
5. Abra um Pull Request

## Licença

Este plugin é distribuído sob a licença GNU General Public License v2.0 ou posterior (GPLv2+).

## Suporte

Para problemas e sugestões, abra uma issue no repositório do projeto.
