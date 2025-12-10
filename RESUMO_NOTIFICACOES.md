# Resumo do Sistema de Notificações Push - GLPI PWA

## 1. Como o Usuário é Registrado

### Fluxo de Registro

1. **Inicialização no Frontend** (`js/glpipwa.js`):

   - O script é carregado automaticamente em todas as páginas do GLPI
   - Registra o Service Worker para gerenciar notificações push
   - Carrega o Firebase SDK dinamicamente (v9 compat)
   - Solicita permissão de notificação ao usuário

2. **Obtenção do Token FCM**:

   - Após o usuário conceder permissão, o Firebase gera um token FCM único para o dispositivo/navegador
   - O token é obtido através de `messaging.getToken()` com suporte a Service Worker
   - O sistema aguarda o Service Worker estar ativo antes de solicitar o token

3. **Registro no Servidor** (`front/register.php`):

   - O token FCM é enviado via POST para `/plugins/glpipwa/front/register.php`
   - Validações de segurança:
     - Verificação de autenticação de sessão (usuário deve estar logado)
     - Validação de CSRF token (proteção contra ataques)
     - Validação de formato do token (alfanumérico com caracteres especiais)
   - O token é armazenado na tabela `glpi_plugin_glpipwa_tokens` associado ao `users_id` do usuário logado

4. **Armazenamento** (`inc/Token.php`):
   - Cada token é armazenado com:
     - `users_id`: ID do usuário no GLPI
     - `token`: Token FCM único do dispositivo
     - `user_agent`: Informações do navegador/dispositivo
     - `date_creation`: Data de criação
     - `date_mod`: Data da última modificação

### Características do Registro

- **Token Único por Dispositivo**: Cada dispositivo/navegador gera um token FCM único
- **Atualização Automática**: O sistema verifica periodicamente (a cada 5 minutos) se o token mudou
- **Renovação em Atualizações**: Quando o Service Worker é atualizado, o token é renovado automaticamente
- **Atualização de `date_mod`**: Sempre que um token existente é registrado novamente, a data de modificação é atualizada, "resetando" o contador de 90 dias para limpeza

---

## 2. Registro em Múltiplos Lugares

### Comportamento Atual

O sistema **permite e suporta** que um usuário registre múltiplos dispositivos/navegadores:

1. **Múltiplos Tokens por Usuário**:

   - Cada dispositivo/navegador registra seu próprio token FCM
   - Todos os tokens são armazenados na mesma tabela, associados ao mesmo `users_id`
   - Não há limite de tokens por usuário

2. **Atualização de Token Existente**:

   - Se o mesmo token já existe no banco (mesmo dispositivo registrando novamente):
     - O registro é atualizado (não duplicado)
     - A data de modificação (`date_mod`) é atualizada
     - O `user_agent` é atualizado se fornecido
     - Se o token mudou de usuário, o `users_id` é atualizado

3. **Envio de Notificações**:
   - **IMPORTANTE**: Quando uma notificação é enviada para um usuário, o sistema envia para **TODOS os seus tokens**, não apenas para um
   - O método `sendToUser()` em `NotificationPush.php`:
     1. Busca **todos os tokens** do usuário usando `getUserTokens($users_id)`
     2. Faz um loop `foreach` e envia a notificação para **cada token individualmente**
   - Isso permite que o usuário receba notificações em **todos os seus dispositivos simultaneamente**
   - Se o usuário tem 5 dispositivos registrados, ele receberá a mesma notificação nos 5 dispositivos

### Exemplo de Cenário

**Usuário João registra em 3 lugares:**

- Desktop Chrome: Token `abc123`
- Mobile Chrome: Token `def456`
- Tablet Firefox: Token `ghi789`

**Resultado:**

- 3 registros na tabela `glpi_plugin_glpipwa_tokens`, todos com `users_id = João`
- Quando uma notificação é enviada para João:
  - O sistema busca os 3 tokens dele
  - Envia a notificação para o token `abc123` (Desktop)
  - Envia a notificação para o token `def456` (Mobile)
  - Envia a notificação para o token `ghi789` (Tablet)
  - **Todos os 3 dispositivos recebem a notificação simultaneamente**

### Limpeza de Tokens

**NÃO, os tokens não ficam para sempre!** O sistema possui limpeza automática em dois casos:

1. **Limpeza Automática por Inatividade (Cron)**:

   - Executa **diariamente** entre 2h e 6h da manhã
   - Remove tokens que **não foram atualizados há mais de 90 dias**
   - Um token é considerado "atualizado" quando:
     - O usuário acessa o GLPI e o sistema verifica/renova o token (a cada 5 minutos)
     - O token é registrado novamente (mesmo dispositivo registrando novamente)
   - Se um usuário não acessar o GLPI por mais de 90 dias, seus tokens serão removidos

2. **Remoção Imediata de Tokens Inválidos**:
   - Quando o FCM retorna erro `NOT_FOUND` ou `UNREGISTERED` (dispositivo desinstalou o app, token expirou, etc.)
   - O token é removido **imediatamente** durante o envio da notificação
   - Isso evita tentativas futuras de envio para tokens inválidos

---

## 3. Eventos que Enviam Notificações

O sistema está configurado para enviar notificações push em **3 eventos principais**:

### 3.1. Novo Ticket Criado

**Hook**: `plugin_glpipwa_item_add()` em `hook.php`  
**Método**: `notifyNewTicket()` em `NotificationPush.php`

**Quando dispara:**

- Quando um novo ticket é criado no GLPI
- Dispara no hook `item_add` quando o item é uma instância de `Ticket`

**Destinatários:**

- Técnico designado (`users_id_tech`)
- Membros do grupo técnico (`groups_id_tech`)
- Observadores do ticket (`Ticket_User` com tipo `OBSERVER`)
- Solicitantes do ticket (`Ticket_User` com tipo `REQUESTER`)
- **Exclui**: O criador do ticket (`users_id_recipient`)

**Conteúdo da Notificação:**

- **Título**: "New Ticket #[ID]"
- **Corpo**: "Ticket opened by [Nome] - Urgency: [Urgência]"
- **Dados adicionais**:
  - `url`: Link para o ticket
  - `ticket_id`: ID do ticket
  - `type`: "new_ticket"

### 3.2. Ticket Atualizado

**Hook**: `plugin_glpipwa_item_update()` em `hook.php`  
**Método**: `notifyTicketUpdate()` em `NotificationPush.php`

**Quando dispara:**

- Quando um ticket existente é atualizado no GLPI
- Dispara no hook `item_update` quando o item é uma instância de `Ticket`

**Destinatários:**

- Técnico designado (`users_id_tech`)
- Membros do grupo técnico (`groups_id_tech`)
- Observadores do ticket (`Ticket_User` com tipo `OBSERVER`)
- Solicitantes do ticket (`Ticket_User` com tipo `REQUESTER`)
- **Não exclui ninguém** (inclui quem fez a atualização)

**Conteúdo da Notificação:**

- **Título**: "Ticket #[ID] Updated"
- **Corpo**: "The ticket has been updated"
- **Dados adicionais**:
  - `url`: Link para o ticket
  - `ticket_id`: ID do ticket
  - `type`: "ticket_update"

### 3.3. Novo Follow-up (Comentário)

**Hook**: `plugin_glpipwa_followup_add()` em `hook.php`  
**Método**: `notifyNewFollowup()` em `NotificationPush.php`

**Quando dispara:**

- Quando um novo follow-up (comentário) é adicionado a um ticket
- Dispara no hook `followup_add` quando o item é uma instância de `ITILFollowup`
- Apenas para follow-ups de tickets (não para outros tipos de itens ITIL)

**Destinatários:**

- Técnico designado (`users_id_tech`)
- Membros do grupo técnico (`groups_id_tech`)
- Observadores do ticket (`Ticket_User` com tipo `OBSERVER`)
- Solicitantes do ticket (`Ticket_User` com tipo `REQUESTER`)
- **Exclui**: O autor do follow-up (`users_id` do follow-up)

**Conteúdo da Notificação:**

- **Título**: "New interaction on Ticket #[ID]"
- **Corpo**: "[Nome do Autor] commented: [Primeiros 100 caracteres do comentário]"
- **Dados adicionais**:
  - `url`: Link para o ticket
  - `ticket_id`: ID do ticket
  - `type`: "new_followup"

---

## 4. Fluxo de Envio de Notificações

### Processo Completo

1. **Evento no GLPI** → Hook é disparado
2. **Verificação de Configuração** → Verifica se Firebase está configurado
3. **Obtenção de Destinatários** → Busca usuários relacionados ao ticket
4. **Busca de Tokens** → Para cada destinatário, busca **TODOS os seus tokens FCM** (não apenas um)
5. **Autenticação OAuth2** → Obtém access token OAuth2 via Service Account (com cache de 1 hora)
6. **Envio via FCM v1 API** → **Envia notificação para CADA token individualmente** (loop foreach)
   - Se o usuário tem 3 dispositivos, faz 3 chamadas ao FCM (uma para cada token)
   - Todos os dispositivos recebem a mesma notificação simultaneamente
7. **Tratamento de Erros**:
   - Tokens inválidos (`NOT_FOUND`, `UNREGISTERED`) são removidos automaticamente
   - Erros de autenticação (`UNAUTHENTICATED`) tentam renovar o token e reenviar
   - Outros erros são logados mas não interrompem o processo
   - Se um token falhar, os outros tokens do mesmo usuário ainda recebem a notificação

### Tecnologias Utilizadas

- **Firebase Cloud Messaging (FCM) v1 API**: Serviço de notificações push do Google
- **OAuth2 com JWT**: Autenticação via Service Account do Firebase
- **Service Worker**: Gerencia notificações no navegador
- **Firebase SDK v9 Compat**: SDK JavaScript para integração frontend

---

## 5. Estrutura de Dados

### Tabela: `glpi_plugin_glpipwa_tokens`

```sql
- id (int, PK, AUTO_INCREMENT)
- users_id (int, FK para glpi_users)
- token (varchar(255), UNIQUE) - Token FCM do dispositivo
- user_agent (varchar(255)) - Informações do navegador
- date_creation (timestamp)
- date_mod (timestamp)
```

### Índices:

- `PRIMARY KEY (id)`
- `UNIQUE KEY (token)` - Garante que cada token seja único
- `KEY (users_id)` - Otimiza buscas por usuário
- `KEY (date_mod)` - Otimiza limpeza de tokens antigos

---

## 6. Segurança

### Medidas Implementadas

1. **Autenticação de Sessão**: Apenas usuários autenticados podem registrar tokens
2. **Validação CSRF**: Proteção contra ataques CSRF no registro de tokens
3. **Validação de Formato**: Tokens são validados antes de serem armazenados
4. **Sanitização**: Dados de entrada são sanitizados e validados
5. **OAuth2**: Autenticação segura com Firebase usando Service Account
6. **Logs de Erro**: Erros são logados para auditoria

---

## 7. Ciclo de Vida dos Tokens

### Como os Tokens são Mantidos Ativos

1. **Acesso Regular ao GLPI**:

   - Quando o usuário acessa o GLPI, o JavaScript verifica o token a cada 5 minutos
   - Se o token mudou ou precisa ser renovado, ele é atualizado no servidor
   - Isso atualiza o campo `date_mod`, mantendo o token "vivo"

2. **Registro Novamente**:
   - Se o mesmo dispositivo registra o token novamente (mesmo token FCM), o `date_mod` é atualizado
   - Isso também "reseta" o contador de 90 dias

### Quando os Tokens são Removidos

1. **Após 90 dias de inatividade**:

   - Se o token não for atualizado por 90 dias consecutivos
   - Removido pelo cron diário (executa entre 2h e 6h da manhã)

2. **Token Inválido**:

   - Dispositivo desinstalou o app/navegador
   - Token FCM expirou ou foi revogado
   - Removido imediatamente quando o FCM retorna erro

3. **Manualmente**:
   - Administrador pode remover tokens manualmente através da interface do GLPI (se implementado)

## 8. Observações Importantes

### Limitações Atuais

- Notificações apenas para **Tickets** (não para outros tipos de itens ITIL como Problemas ou Mudanças)
- Follow-ups apenas de Tickets são notificados
- Não há filtros de preferências do usuário (todos os eventos notificam)
- Tokens são removidos após 90 dias de inatividade (usuário precisa acessar o GLPI regularmente)

### Melhorias Futuras Sugeridas

- Adicionar preferências de notificação por usuário
- Suportar outros tipos de itens ITIL (Problemas, Mudanças)
- Adicionar filtros por urgência/prioridade
- Dashboard de estatísticas de notificações
- Histórico de notificações enviadas

---

**Data do Resumo**: Dezembro 2024  
**Versão do Plugin**: Em fase de testes
