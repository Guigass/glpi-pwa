<?php
/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2024 Teclib' and contributors.
 *
 * http://glpi-project.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * GLPI is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Classe para envio de notificações push via FCM v1
 */
class PluginGlpipwaNotificationPush
{
    /**
     * Envia notificação para um token específico
     */
    public function send($token, $title, $body, $data = [])
    {
        // Converter dados para strings (FCM v1 requer strings)
        $dataStrings = [];
        foreach ($data as $key => $value) {
            $dataStrings[$key] = (string)$value;
        }

        // Obter ticket_id para tag e collapse_key
        $ticket_id = isset($data['ticket_id']) ? (string)$data['ticket_id'] : null;
        
        // Determinar TTL baseado no tipo de evento
        // TTL curto (10 minutos) para tickets/interações, maior para eventos críticos
        $ttl = 600; // 10 minutos padrão
        $eventType = isset($data['type']) ? $data['type'] : '';
        if (in_array($eventType, ['validation_requested', 'validation_added'])) {
            $ttl = 21600; // 6 horas para validações
        }

        // ESTRATÉGIA DATA-ONLY: Não enviar message.notification para evitar duplicação
        // O FCM exibe automaticamente notificações quando message.notification está presente,
        // e o Service Worker também exibe via showNotification(), causando duplicação.
        // Com data-only, apenas o Service Worker exibe a notificação, garantindo controle total.
        // Referência: https://firebase.google.com/docs/cloud-messaging/concept-options#notifications_and_data_messages
        
        // Adicionar title e body em data para o Service Worker usar
        $dataStrings['title'] = (string)$title;
        $dataStrings['body'] = (string)$body;

        $message = [
            'message' => [
                'token' => $token,
                // NOTA: message.notification foi removido - usar apenas message.data
                'data' => $dataStrings,
            ],
        ];

        // Adicionar tag para substituição de notificações (Web Push)
        if ($ticket_id) {
            $tag = "ticket-{$ticket_id}";
            // Tag é usado no Service Worker para deduplicação de notificações do mesmo ticket
            $message['message']['data']['tag'] = $tag;
        }

        // Adicionar TTL e collapse_key para Android
        if ($ticket_id) {
            $collapseKey = "ticket-{$ticket_id}";
            $message['message']['android'] = [
                'ttl' => $ttl . 's',
                'collapse_key' => $collapseKey,
            ];
        } else {
            $message['message']['android'] = [
                'ttl' => $ttl . 's',
            ];
        }

        // Adicionar apns-collapse-id para iOS
        if ($ticket_id) {
            $collapseId = "ticket-{$ticket_id}";
            $message['message']['apns'] = [
                'headers' => [
                    'apns-collapse-id' => $collapseId,
                ],
            ];
        }

        // Adicionar TTL para Web Push
        $message['message']['webpush'] = [
            'headers' => [
                'TTL' => (string)$ttl,
                'Urgency' => 'normal',
            ],
        ];

        // Adicionar link se disponível
        if (isset($data['url'])) {
            $message['message']['webpush']['fcm_options'] = [
                'link' => $data['url'],
            ];
        }

        return $this->sendToFCM($message, $token);
    }

    /**
     * Envia notificação para todos os tokens de um usuário
     * @deprecated Use sendToUsers() que agora usa dispositivos
     */
    public function sendToUser($users_id, $title, $body, $data = [])
    {
        return $this->sendToUsers([$users_id], $title, $body, $data);
    }

    /**
     * Envia notificação para múltiplos usuários
     * Agora usa dispositivos e verifica last_seen_at antes de enviar
     */
    public function sendToUsers(array $users_ids, $title, $body, $data = [])
    {
        // Filtrar e validar IDs de usuários
        $valid_users_ids = $this->filterValidUserIds($users_ids);
        
        if (empty($valid_users_ids)) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Nenhum ID de usuário válido fornecido. IDs originais: " . implode(', ', $users_ids), LOG_DEBUG);
            return false;
        }

        // Obter ticket_id e ticket_date_mod do payload para verificação
        // IMPORTANTE: O GLPI atualiza ticket.date_mod automaticamente em todos os eventos:
        // - item_add (novo ticket)
        // - item_update (atualização de ticket)
        // - followup_add (novo follow-up)
        // - task_add (nova tarefa)
        // - validation_add/update (validações)
        // Portanto, sempre buscamos o valor atual do banco para garantir precisão
        $ticket_id = isset($data['ticket_id']) ? (int)$data['ticket_id'] : null;
        $ticket_date_mod = null;

        if ($ticket_id) {
            // Buscar ticket para obter date_mod atualizado
            // O GLPI garante que date_mod é atualizado antes dos hooks serem chamados
            $ticket = new Ticket();
            if ($ticket->getFromDB($ticket_id)) {
                $ticket_date_mod = $ticket->getField('date_mod');
            }
        }

        // Se não temos ticket_id ou date_mod, usar timestamp atual (comportamento seguro)
        // Isso só acontece em casos raros onde não há ticket associado
        if (!$ticket_date_mod) {
            $ticket_date_mod = date('Y-m-d H:i:s');
        }

        // Buscar dispositivos que devem receber notificação
        // getDevicesForNotification já filtra por last_seen_at e last_seen_ticket_id
        $devices = PluginGlpipwaDevice::getDevicesForNotification($valid_users_ids, $ticket_id, $ticket_date_mod);

        if (empty($devices)) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Nenhum dispositivo encontrado que deve receber notificação para os usuários: " . implode(', ', $valid_users_ids), LOG_DEBUG);
            return false;
        }

        $totalDevices = count($devices);
        Toolbox::logInFile('glpipwa', "GLPI PWA: Enviando notificação para {$totalDevices} dispositivo(s) de " . count($valid_users_ids) . " usuário(s)", LOG_DEBUG);

        $results = [];
        $successCount = 0;
        $failureCount = 0;
        $firstDevice = true;

        foreach ($devices as $device) {
            // Adicionar delay de 100ms entre requisições para evitar rate limiting (exceto na primeira)
            if (!$firstDevice) {
                usleep(100000); // 100ms em microsegundos
            }
            $firstDevice = false;

            try {
                // Adicionar device_id ao payload para uso no Service Worker (tag)
                $deviceData = $data;
                $deviceData['device_id'] = $device['device_id'];

                $result = $this->send($device['fcm_token'], $title, $body, $deviceData);
                $results[] = $result;
                
                if ($result !== false) {
                    $successCount++;
                } else {
                    $failureCount++;
                    Toolbox::logInFile('glpipwa', "GLPI PWA: Falha ao enviar notificação para dispositivo (users_id: {$device['users_id']}, device_id: {$device['device_id']})", LOG_WARNING);
                }
            } catch (Exception $e) {
                $failureCount++;
                $results[] = false;
                Toolbox::logInFile('glpipwa', "GLPI PWA: Exceção ao enviar notificação para dispositivo (users_id: {$device['users_id']}, device_id: {$device['device_id']}): " . $e->getMessage(), LOG_ERR);
            }
        }

        Toolbox::logInFile('glpipwa', "GLPI PWA: Resumo de envio para múltiplos usuários - Sucessos: {$successCount}, Falhas: {$failureCount}, Total: {$totalDevices}", LOG_DEBUG);

        return $results;
    }

    /**
     * Envia requisição para FCM v1 API
     * 
     * @param array $message Payload no formato FCM v1
     * @param string $token Token do dispositivo (para remoção em caso de erro)
     * @param int $retryCount Contador de tentativas (para evitar loop infinito)
     * @return array|false Resposta do FCM ou false em caso de erro
     */
    private function sendToFCM(array $message, $token, $retryCount = 0)
    {
        // Proteção contra loop infinito
        if ($retryCount > 1) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Número máximo de tentativas excedido para envio FCM", LOG_ERR);
            return false;
        }

        // Obter access token OAuth2
        $accessToken = PluginGlpipwaFirebaseAuth::getAccessToken();
        
        if (!$accessToken) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Não foi possível obter access token OAuth2", LOG_ERR);
            return false;
        }

        // Obter project ID
        $projectId = PluginGlpipwaConfig::get('firebase_project_id');
        
        if (empty($projectId)) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Project ID não configurado", LOG_ERR);
            return false;
        }

        // URL do endpoint FCM v1
        $fcmUrl = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ];

        $ch = curl_init($fcmUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Timeout de 30 segundos para a requisição completa
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // Timeout de 10 segundos para conexão

        // Logar payload enviado (com token mascarado para segurança)
        $maskedMessage = $message;
        if (isset($maskedMessage['message']['token'])) {
            $maskedToken = substr($maskedMessage['message']['token'], 0, 10) . '...' . substr($maskedMessage['message']['token'], -10);
            $maskedMessage['message']['token'] = $maskedToken;
        }

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Toolbox::logInFile('glpipwa', "GLPI PWA FCM Error (cURL): " . $error, LOG_ERR);
            return false;
        }

        // Verificar se a resposta está vazia
        if (empty($result)) {
            Toolbox::logInFile('glpipwa', "GLPI PWA FCM Error: Resposta vazia do servidor (HTTP {$httpCode})", LOG_ERR);
            return false;
        }

        $response = json_decode($result, true);

        // Verificar se o JSON foi decodificado corretamente
        if (json_last_error() !== JSON_ERROR_NONE) {
            Toolbox::logInFile('glpipwa', "GLPI PWA FCM Error: Resposta JSON inválida (HTTP {$httpCode}) - " . json_last_error_msg() . " - Resposta: " . substr($result, 0, 500), LOG_ERR);
            return false;
        }

        // Verificar se a resposta é um array válido
        if (!is_array($response)) {
            Toolbox::logInFile('glpipwa', "GLPI PWA FCM Error: Resposta não é um array válido (HTTP {$httpCode}) - Resposta: " . substr($result, 0, 500), LOG_ERR);
            return false;
        }

        // Tratamento de erros FCM v1
        if ($httpCode !== 200) {
            $errorMessage = $response['error']['message'] ?? 'Unknown error';
            $errorCode = $response['error']['code'] ?? 'UNKNOWN';
            $errorStatus = $response['error']['status'] ?? null;
            
            // Tentar obter o errorCode real dos details (FCM v1 usa details[0].errorCode)
            $fcmErrorCode = null;
            if (isset($response['error']['details']) && is_array($response['error']['details']) && !empty($response['error']['details'])) {
                $fcmErrorCode = $response['error']['details'][0]['errorCode'] ?? null;
            }
            
            // Usar o FCM errorCode se disponível, senão usar o status, senão usar o code
            $actualErrorCode = $fcmErrorCode ?? $errorStatus ?? (is_string($errorCode) ? $errorCode : null);
            
            // Logar resposta completa do erro (mascarando dados sensíveis)
            $maskedResponse = $this->maskSensitiveData($response);
            Toolbox::logInFile('glpipwa', "GLPI PWA FCM HTTP Error: " . $httpCode . " - Code: " . $errorCode . " - Status: " . ($errorStatus ?? 'N/A') . " - FCM ErrorCode: " . ($fcmErrorCode ?? 'N/A') . " - Message: " . $errorMessage . " - Resposta completa: " . json_encode($maskedResponse), LOG_ERR);

            // Tratar erros específicos
            // Verificar tanto o errorCode quanto o status e o FCM errorCode
            $shouldDeleteToken = false;
            $shouldRetryAuth = false;
            
            if ($actualErrorCode === 'UNAUTHENTICATED' || $errorStatus === 'UNAUTHENTICATED') {
                // Token OAuth2 inválido, limpar cache e tentar novamente uma vez
                $shouldRetryAuth = true;
            } elseif ($actualErrorCode === 'UNREGISTERED' || 
                      $actualErrorCode === 'NOT_FOUND' || 
                      $errorStatus === 'NOT_FOUND' ||
                      ($httpCode === 404 && ($errorMessage === 'NotRegistered' || strpos($errorMessage, 'NotRegistered') !== false))) {
                // Token de dispositivo não encontrado ou não registrado
                $shouldDeleteToken = true;
            } elseif ($actualErrorCode === 'INVALID_ARGUMENT' || $errorStatus === 'INVALID_ARGUMENT') {
                // Payload ou token inválido
                Toolbox::logInFile('glpipwa', "GLPI PWA: Argumento inválido no payload FCM", LOG_ERR);
            } elseif ($actualErrorCode === 'PERMISSION_DENIED' || $errorStatus === 'PERMISSION_DENIED') {
                // Service Account sem permissões
                Toolbox::logInFile('glpipwa', "GLPI PWA: Service Account sem permissões para enviar mensagens FCM", LOG_ERR);
            }
            
            // Executar ações baseadas nos erros detectados
            if ($shouldRetryAuth && $retryCount === 0) {
                PluginGlpipwaFirebaseAuth::clearCache();
                $accessToken = PluginGlpipwaFirebaseAuth::getAccessToken();
                if ($accessToken) {
                    // Tentar novamente com novo token
                    return $this->sendToFCM($message, $token, $retryCount + 1);
                }
            }
            
            if ($shouldDeleteToken) {
                // Tentar remover da nova tabela de dispositivos primeiro
                if (class_exists('PluginGlpipwaDevice')) {
                    global $DB;
                    $iterator = $DB->request([
                        'FROM' => PluginGlpipwaDevice::getTable(),
                        'WHERE' => ['fcm_token' => $token],
                        'LIMIT' => 1,
                    ]);
                    foreach ($iterator as $device) {
                        $deviceObj = new PluginGlpipwaDevice();
                        if ($deviceObj->getFromDB($device['id'])) {
                            $deviceObj->delete(['id' => $device['id']]);
                            Toolbox::logInFile('glpipwa', "GLPI PWA: Dispositivo removido devido a erro FCM (UNREGISTERED/NOT_FOUND) - Device ID: " . $device['device_id'], LOG_DEBUG);
                        }
                    }
                }
                // Fallback: tentar remover da tabela antiga também (compatibilidade)
                if (class_exists('PluginGlpipwaToken')) {
                    PluginGlpipwaToken::deleteToken($token);
                    Toolbox::logInFile('glpipwa', "GLPI PWA: Token removido devido a erro FCM (UNREGISTERED/NOT_FOUND) - Token: " . substr($token, 0, 20) . "...", LOG_DEBUG);
                }
            }
            
            return false;
        }

        // Validar estrutura da resposta de sucesso
        // FCM v1 retorna sucesso quando há um campo 'name' com o ID da mensagem
        if (!isset($response['name']) || empty($response['name'])) {
            // Verificar se há erro na resposta mesmo com HTTP 200
            if (isset($response['error'])) {
                $errorMessage = $response['error']['message'] ?? 'Unknown error';
                $errorCode = $response['error']['code'] ?? 'UNKNOWN';
                $maskedResponse = $this->maskSensitiveData($response);
                Toolbox::logInFile('glpipwa', "GLPI PWA FCM Error: Resposta contém erro mesmo com HTTP 200 - " . $errorCode . " - " . $errorMessage . " - Resposta: " . json_encode($maskedResponse), LOG_ERR);
                return false;
            }
            
            // Resposta sem estrutura esperada
            $maskedResponse = $this->maskSensitiveData($response);
            Toolbox::logInFile('glpipwa', "GLPI PWA FCM Error: Resposta de sucesso sem campo 'name' (HTTP {$httpCode}) - Resposta: " . json_encode($maskedResponse), LOG_ERR);
            return false;
        }

        // Sucesso validado - logar resposta (com dados sensíveis mascarados)
        $maskedResponse = $this->maskSensitiveData($response);

        return $response;
    }

    /**
     * Mascara dados sensíveis em arrays para logs
     * 
     * @param array $data Dados a serem mascarados
     * @return array Dados com informações sensíveis mascaradas
     */
    private function maskSensitiveData(array $data)
    {
        $masked = $data;
        
        // Mascarar tokens FCM
        if (isset($masked['message']['token'])) {
            $token = $masked['message']['token'];
            if (strlen($token) > 20) {
                $masked['message']['token'] = substr($token, 0, 10) . '...' . substr($token, -10);
            } else {
                $masked['message']['token'] = '***';
            }
        }
        
        // Mascarar access tokens
        if (isset($masked['access_token'])) {
            $masked['access_token'] = '***';
        }
        
        // Recursivamente mascarar em subarrays
        foreach ($masked as $key => $value) {
            if (is_array($value)) {
                $masked[$key] = $this->maskSensitiveData($value);
            }
        }
        
        return $masked;
    }

    /**
     * Verifica se o Firebase está configurado corretamente
     * 
     * @return bool
     */
    private function isFirebaseConfigured()
    {
        $config = PluginGlpipwaConfig::getAll();
        return PluginGlpipwaConfig::validateFirebaseConfig($config);
    }

    /**
     * Notifica sobre novo ticket
     */
    public function notifyNewTicket(Ticket $ticket)
    {
        // Verificar se Firebase está configurado
        if (!$this->isFirebaseConfigured()) {
            return;
        }

        $recipients = $this->getTicketRecipients($ticket, true);
        
        if (empty($recipients)) {
            return;
        }

        $title = sprintf(__('New Ticket #%d', 'glpipwa'), $ticket->getID());
        $urgency = $ticket->getField('urgency');
        $urgencyName = Ticket::getUrgencyName($urgency);
        $body = sprintf(
            __('Ticket opened by %s - Urgency: %s', 'glpipwa'),
            $ticket->getField('name'),
            $urgencyName
        );

        $data = [
            'url' => $this->getTicketUrl($ticket),
            'ticket_id' => $ticket->getID(),
            'type' => 'new_ticket',
        ];

        $this->sendToUsers($recipients, $title, $body, $data);
    }

    /**
     * Detecta o tipo de mudança ocorrida no ticket
     * 
     * @param Ticket $ticket Ticket atualizado
     * @param array|null $previousState Estado anterior do ticket
     * @return string Tipo de mudança detectada: 'closed', 'solved', 'assigned', 'updated', ou null
     */
    private function detectTicketChange(Ticket $ticket, $previousState = null)
    {
        if ($previousState === null) {
            return 'updated';
        }

        $currentStatus = (int)$ticket->getField('status');
        $previousStatus = isset($previousState['status']) ? (int)$previousState['status'] : null;

        // Verificar se a classe CommonITILObject existe
        if (!class_exists('CommonITILObject')) {
            // Se não existir, usar valores numéricos diretos
            $CLOSED = 6;
            $SOLVED = 5;
        } else {
            $CLOSED = CommonITILObject::CLOSED;
            $SOLVED = CommonITILObject::SOLVED;
        }

        // Detectar fechamento
        if ($previousStatus !== $CLOSED && $currentStatus === $CLOSED) {
            return 'closed';
        }

        // Detectar solução
        if ($previousStatus !== $SOLVED && $currentStatus === $SOLVED) {
            return 'solved';
        }

        // Detectar atribuição (mudança de técnico ou grupo técnico)
        $currentTech = (int)$ticket->getField('users_id_tech');
        $previousTech = isset($previousState['users_id_tech']) ? (int)$previousState['users_id_tech'] : 0;
        
        $currentGroup = (int)$ticket->getField('groups_id_tech');
        $previousGroup = isset($previousState['groups_id_tech']) ? (int)$previousState['groups_id_tech'] : 0;

        if ($currentTech !== $previousTech || $currentGroup !== $previousGroup) {
            return 'assigned';
        }

        // Outras atualizações
        return 'updated';
    }

    /**
     * Obtém URL completa do ticket
     * 
     * @param Ticket $ticket
     * @return string URL completa do ticket
     */
    private function getTicketUrl(Ticket $ticket)
    {
        $url = $ticket->getLinkURL();
        
        // Se a URL não for absoluta, construir URL completa
        if (!empty($url) && !preg_match('/^https?:\/\//', $url)) {
            global $CFG_GLPI;
            if (isset($CFG_GLPI['url_base']) && !empty($CFG_GLPI['url_base'])) {
                $baseUrl = rtrim($CFG_GLPI['url_base'], '/');
                $url = $baseUrl . '/' . ltrim($url, '/');
            }
        }
        
        return $url;
    }

    /**
     * Notifica sobre atualização de ticket
     * 
     * @param Ticket $ticket Ticket atualizado
     * @param array|null $previousState Estado anterior do ticket
     */
    public function notifyTicketUpdate(Ticket $ticket, $previousState = null)
    {
        $ticketId = $ticket->getID();
        
        // Verificar se Firebase está configurado
        if (!$this->isFirebaseConfigured()) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Firebase não está configurado corretamente para ticket ID: {$ticketId}", LOG_WARNING);
            return;
        }

        // Detectar tipo de mudança
        $changeType = $this->detectTicketChange($ticket, $previousState);

        // Chamar método específico baseado no tipo de mudança
        switch ($changeType) {
            case 'closed':
                $this->notifyTicketClosed($ticket, $previousState);
                break;
            
            case 'solved':
                $this->notifyTicketSolved($ticket, $previousState);
                break;
            
            case 'assigned':
                $this->notifyTicketAssigned($ticket, $previousState);
                break;
            
            case 'updated':
            default:
                // Notificação genérica de atualização
                $recipients = $this->getTicketRecipients($ticket, false);
                
                if (empty($recipients)) {
                    Toolbox::logInFile('glpipwa', "GLPI PWA: Nenhum destinatário encontrado para atualização de ticket ID: {$ticketId}", LOG_DEBUG);
                    return;
                }

                $title = sprintf(__('Ticket #%d Updated', 'glpipwa'), $ticketId);
                $body = __('The ticket has been updated', 'glpipwa');

                $data = [
                    'url' => $this->getTicketUrl($ticket),
                    'ticket_id' => $ticketId,
                    'type' => 'ticket_update',
                ];

                $this->sendToUsers($recipients, $title, $body, $data);
                break;
        }

    }

    /**
     * Notifica quando um ticket é fechado
     * 
     * @param Ticket $ticket Ticket fechado
     * @param array|null $previousState Estado anterior do ticket
     */
    public function notifyTicketClosed(Ticket $ticket, $previousState = null)
    {
        // Verificar se Firebase está configurado
        if (!$this->isFirebaseConfigured()) {
            return;
        }

        $ticketId = $ticket->getID();
        $recipients = $this->getTicketRecipients($ticket, false);
        
        if (empty($recipients)) {
            return;
        }

        // Obter nome de quem fechou (usuário atual da sessão)
        $closedByName = __('System', 'glpipwa');
        if (class_exists('Session') && Session::getLoginUserID()) {
            $userId = Session::getLoginUserID();
            if (class_exists('User')) {
                try {
                    $user = new User();
                    if ($user->getFromDB($userId)) {
                        $closedByName = $user->getName();
                    }
                } catch (Exception $e) {
                    // Usa nome padrão se não conseguir obter
                }
            }
        }

        $title = sprintf(__('Ticket #%d Closed', 'glpipwa'), $ticketId);
        $body = sprintf(__('Ticket closed by %s', 'glpipwa'), $closedByName);

        $data = [
            'url' => $this->getTicketUrl($ticket),
            'ticket_id' => $ticketId,
            'type' => 'ticket_closed',
            'status' => (string)$ticket->getField('status'),
        ];

        $this->sendToUsers($recipients, $title, $body, $data);
    }

    /**
     * Notifica quando um ticket é solucionado
     * 
     * @param Ticket $ticket Ticket solucionado
     * @param array|null $previousState Estado anterior do ticket
     */
    public function notifyTicketSolved(Ticket $ticket, $previousState = null)
    {
        // Verificar se Firebase está configurado
        if (!$this->isFirebaseConfigured()) {
            return;
        }

        $ticketId = $ticket->getID();
        $recipients = $this->getTicketRecipients($ticket, false);
        
        if (empty($recipients)) {
            return;
        }

        // Obter nome de quem solucionou
        $solvedByName = __('System', 'glpipwa');
        if (class_exists('Session') && Session::getLoginUserID()) {
            $userId = Session::getLoginUserID();
            if (class_exists('User')) {
                try {
                    $user = new User();
                    if ($user->getFromDB($userId)) {
                        $solvedByName = $user->getName();
                    }
                } catch (Exception $e) {
                    // Usa nome padrão se não conseguir obter
                }
            }
        }

        $title = sprintf(__('Ticket #%d Solved', 'glpipwa'), $ticketId);
        $body = sprintf(__('Ticket solved by %s - Awaiting validation', 'glpipwa'), $solvedByName);

        $data = [
            'url' => $this->getTicketUrl($ticket),
            'ticket_id' => $ticketId,
            'type' => 'ticket_solved',
            'status' => (string)$ticket->getField('status'),
        ];

        $this->sendToUsers($recipients, $title, $body, $data);
    }

    /**
     * Notifica quando um ticket é atribuído
     * 
     * @param Ticket $ticket Ticket atribuído
     * @param array|null $previousState Estado anterior do ticket
     */
    public function notifyTicketAssigned(Ticket $ticket, $previousState = null)
    {
        // Verificar se Firebase está configurado
        if (!$this->isFirebaseConfigured()) {
            return;
        }

        $ticketId = $ticket->getID();
        $recipients = $this->getTicketRecipients($ticket, false);
        
        if (empty($recipients)) {
            return;
        }

        // Obter informações sobre a atribuição
        $assignedTo = '';
        $currentTech = (int)$ticket->getField('users_id_tech');
        $currentGroup = (int)$ticket->getField('groups_id_tech');

        if ($currentTech > 0 && class_exists('User')) {
            try {
                $user = new User();
                if ($user->getFromDB($currentTech)) {
                    $assignedTo = $user->getName();
                }
            } catch (Exception $e) {
                // Ignora erro
            }
        }

        if ($currentGroup > 0 && class_exists('Group')) {
            try {
                $group = new Group();
                if ($group->getFromDB($currentGroup)) {
                    $groupName = $group->getName();
                    if (!empty($assignedTo)) {
                        $assignedTo .= ' / ' . $groupName;
                    } else {
                        $assignedTo = $groupName;
                    }
                }
            } catch (Exception $e) {
                // Ignora erro
            }
        }

        if (empty($assignedTo)) {
            $assignedTo = __('Unassigned', 'glpipwa');
        }

        $title = sprintf(__('Ticket #%d Assigned', 'glpipwa'), $ticketId);
        $body = sprintf(__('Ticket assigned to: %s', 'glpipwa'), $assignedTo);

        $data = [
            'url' => $this->getTicketUrl($ticket),
            'ticket_id' => $ticketId,
            'type' => 'ticket_assigned',
        ];

        $this->sendToUsers($recipients, $title, $body, $data);
    }

    /**
     * Notifica sobre novo follow-up
     */
    public function notifyNewFollowup(ITILFollowup $followup)
    {
        // Verificar se Firebase está configurado
        if (!$this->isFirebaseConfigured()) {
            return;
        }

        // Verificar se a classe Ticket existe antes de usar instanceof
        if (!class_exists('Ticket')) {
            return;
        }
        
        $item = $followup->getItem();
        
        if (!$item instanceof Ticket) {
            return;
        }

        $recipients = $this->getTicketRecipients($item, false);
        
        // Remover o autor do follow-up dos destinatários
        $author_id = $followup->getField('users_id');
        if ($this->isValidUserId($author_id)) {
            $author_id = (int)$author_id;
            $recipients = array_filter($recipients, function($id) use ($author_id) {
                return $id != $author_id;
            });
        }

        if (empty($recipients)) {
            return;
        }

        $authorName = __('User', 'glpipwa');
        if (class_exists('User')) {
            try {
                $user = new User();
                if ($user->getFromDB($author_id)) {
                    $authorName = $user->getName();
                }
            } catch (Exception $e) {
                // Usa nome padrão se não conseguir obter o nome do usuário
            }
        }

        $title = sprintf(__('New interaction on Ticket #%d', 'glpipwa'), $item->getID());
        $body = sprintf(__('%s commented: %s', 'glpipwa'), $authorName, substr($followup->getField('content'), 0, 100));

        $data = [
            'url' => $this->getTicketUrl($item),
            'ticket_id' => $item->getID(),
            'type' => 'new_followup',
        ];

        $this->sendToUsers($recipients, $title, $body, $data);
    }

    /**
     * Valida se um valor é um ID de usuário válido
     * 
     * @param mixed $user_id Valor a ser validado
     * @return bool True se for um ID válido, False caso contrário
     */
    private function isValidUserId($user_id)
    {
        // Verificar se é numérico e maior que zero
        // Rejeitar strings como 'N/A', null, false, arrays, etc.
        if (!is_numeric($user_id)) {
            return false;
        }
        
        $user_id = (int)$user_id;
        return $user_id > 0;
    }

    /**
     * Filtra e valida um array de IDs de usuários
     * 
     * @param array $user_ids Array de IDs a serem validados
     * @return array Array contendo apenas IDs válidos como inteiros
     */
    private function filterValidUserIds(array $user_ids)
    {
        $valid_ids = [];
        foreach ($user_ids as $id) {
            if ($this->isValidUserId($id)) {
                $valid_ids[] = (int)$id;
            }
        }
        return array_unique($valid_ids);
    }

    /**
     * Obtém destinatários de notificação para um ticket
     * 
     * @param Ticket $ticket O ticket para obter destinatários
     * @param bool $isNewTicket Se é um novo ticket (para excluir o criador)
     * @return array Lista de IDs de usuários destinatários
     */
    private function getTicketRecipients(Ticket $ticket, bool $isNewTicket = false)
    {
        $recipients = [];

        // Técnico designado
        $tech_id = $ticket->getField('users_id_tech');
        if ($this->isValidUserId($tech_id)) {
            $recipients[] = (int)$tech_id;
        }

        // Grupo técnico
        $tech_group = $ticket->getField('groups_id_tech');
        if ($tech_group > 0 && class_exists('Group') && class_exists('Group_User')) {
            try {
                $group = new Group();
                if ($group->getFromDB($tech_group)) {
                    $groupUsers = Group_User::getGroupUsers($tech_group);
                    foreach ($groupUsers as $user) {
                        $user_id = $user['id'] ?? null;
                        if ($this->isValidUserId($user_id)) {
                            $recipients[] = (int)$user_id;
                        }
                    }
                }
            } catch (Exception $e) {
                // Silenciosamente ignora erros
            }
        }

        // Observadores via Ticket_User
        if (class_exists('Ticket_User') && class_exists('CommonITILActor')) {
            try {
                $OBSERVER_TYPE = $this->getActorTypeConstant('OBSERVER');
                if ($OBSERVER_TYPE === null) {
                    $OBSERVER_TYPE = 3;
                }
                
                $ticket_user = new Ticket_User();
                $observers = $ticket_user->find([
                    'tickets_id' => $ticket->getID(),
                    'type' => $OBSERVER_TYPE
                ]);
                
                foreach ($observers as $obs) {
                    $user_id = $obs['users_id'] ?? null;
                    if ($this->isValidUserId($user_id)) {
                        $recipients[] = (int)$user_id;
                    }
                }
            } catch (Exception $e) {
                Toolbox::logInFile('glpipwa', "GLPI PWA: Erro ao obter observadores: " . $e->getMessage(), LOG_WARNING);
            } catch (Throwable $e) {
                Toolbox::logInFile('glpipwa', "GLPI PWA: Erro fatal ao obter observadores: " . $e->getMessage(), LOG_ERR);
            }
        }

        // Solicitantes via Ticket_User (pode haver múltiplos)
        if (class_exists('Ticket_User') && class_exists('CommonITILActor')) {
            try {
                $REQUESTER_TYPE = $this->getActorTypeConstant('REQUESTER');
                if ($REQUESTER_TYPE === null) {
                    $REQUESTER_TYPE = 1;
                }
                
                $ticket_user = new Ticket_User();
                $requesters = $ticket_user->find([
                    'tickets_id' => $ticket->getID(),
                    'type' => $REQUESTER_TYPE
                ]);
                
                foreach ($requesters as $req) {
                    $user_id = $req['users_id'] ?? null;
                    if ($this->isValidUserId($user_id)) {
                        $recipients[] = (int)$user_id;
                    }
                }
            } catch (Exception $e) {
                Toolbox::logInFile('glpipwa', "GLPI PWA: Erro ao obter solicitantes: " . $e->getMessage(), LOG_WARNING);
            } catch (Throwable $e) {
                Toolbox::logInFile('glpipwa', "GLPI PWA: Erro fatal ao obter solicitantes: " . $e->getMessage(), LOG_ERR);
            }
        }

        // Grupos de observadores via Group_Ticket
        if (class_exists('Group_Ticket') && class_exists('CommonITILActor') && class_exists('Group_User')) {
            try {
                $OBSERVER_TYPE = $this->getActorTypeConstant('OBSERVER');
                if ($OBSERVER_TYPE === null) {
                    $OBSERVER_TYPE = 3;
                }
                
                $group_ticket = new Group_Ticket();
                $observerGroups = $group_ticket->find([
                    'tickets_id' => $ticket->getID(),
                    'type' => $OBSERVER_TYPE
                ]);
                
                foreach ($observerGroups as $groupTicket) {
                    $group_id = $groupTicket['groups_id'] ?? null;
                    if ($group_id > 0 && class_exists('Group')) {
                        try {
                            $group = new Group();
                            if ($group->getFromDB($group_id)) {
                                $groupUsers = Group_User::getGroupUsers($group_id);
                                foreach ($groupUsers as $user) {
                                    $user_id = $user['id'] ?? null;
                                    if ($this->isValidUserId($user_id)) {
                                        $recipients[] = (int)$user_id;
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro ao obter usuários do grupo de observadores (group_id={$group_id}): " . $e->getMessage(), LOG_WARNING);
                        } catch (Throwable $e) {
                            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro fatal ao obter usuários do grupo de observadores (group_id={$group_id}): " . $e->getMessage(), LOG_ERR);
                        }
                    }
                }
            } catch (Exception $e) {
                Toolbox::logInFile('glpipwa', "GLPI PWA: Erro ao obter grupos de observadores: " . $e->getMessage(), LOG_WARNING);
            } catch (Throwable $e) {
                Toolbox::logInFile('glpipwa', "GLPI PWA: Erro fatal ao obter grupos de observadores: " . $e->getMessage(), LOG_ERR);
            }
        }

        // Grupos atribuídos via Group_Ticket (ASSIGN)
        if (class_exists('Group_Ticket') && class_exists('CommonITILActor') && class_exists('Group_User')) {
            try {
                $ASSIGN_TYPE = $this->getActorTypeConstant('ASSIGN');
                if ($ASSIGN_TYPE === null) {
                    $ASSIGN_TYPE = 2;
                }
                
                $group_ticket = new Group_Ticket();
                $assignedGroups = $group_ticket->find([
                    'tickets_id' => $ticket->getID(),
                    'type' => $ASSIGN_TYPE
                ]);
                
                foreach ($assignedGroups as $groupTicket) {
                    $group_id = $groupTicket['groups_id'] ?? null;
                    if ($group_id > 0 && class_exists('Group')) {
                        try {
                            $group = new Group();
                            if ($group->getFromDB($group_id)) {
                                $groupUsers = Group_User::getGroupUsers($group_id);
                                foreach ($groupUsers as $user) {
                                    $user_id = $user['id'] ?? null;
                                    if ($this->isValidUserId($user_id)) {
                                        $recipients[] = (int)$user_id;
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro ao obter usuários do grupo atribuído (group_id={$group_id}): " . $e->getMessage(), LOG_WARNING);
                        } catch (Throwable $e) {
                            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro fatal ao obter usuários do grupo atribuído (group_id={$group_id}): " . $e->getMessage(), LOG_ERR);
                        }
                    }
                }
            } catch (Exception $e) {
                Toolbox::logInFile('glpipwa', "GLPI PWA: Erro ao obter grupos atribuídos: " . $e->getMessage(), LOG_WARNING);
            } catch (Throwable $e) {
                Toolbox::logInFile('glpipwa', "GLPI PWA: Erro fatal ao obter grupos atribuídos: " . $e->getMessage(), LOG_ERR);
            }
        }

        // Remover duplicatas - garante que se um usuário está atribuído individualmente E pertence a um grupo,
        // ele receberá apenas 1 notificação
        $recipients = array_unique($recipients);
        
        // Se for novo ticket, remover apenas o criador real (quem registrou o chamado)
        // Isso permite que solicitantes diferentes do criador recebam notificação
        if ($isNewTicket) {
            // users_id_recipient é quem criou o registro no sistema
            $creator_id = $ticket->getField('users_id_recipient');
            if ($this->isValidUserId($creator_id)) {
                $creator_id = (int)$creator_id;
                $recipients = array_filter($recipients, function($id) use ($creator_id) {
                    return $id != $creator_id;
                });
            }
        }

        // Filtrar novamente para garantir que todos os valores são válidos
        $finalRecipients = $this->filterValidUserIds($recipients);
        
        
        return $finalRecipients;
    }

    /**
     * Obtém o valor de uma constante de tipo de ator de forma segura
     * 
     * @param string $constantName Nome da constante (REQUESTER, ASSIGN, OBSERVER)
     * @return int|null Valor da constante ou null se não existir
     */
    private function getActorTypeConstant(string $constantName): ?int
    {
        if (!class_exists('CommonITILActor')) {
            return null;
        }

        try {
            // Tentar diferentes variações do nome da constante
            $constantVariations = [];
            
            if ($constantName === 'ASSIGN') {
                // ASSIGN pode estar como ASSIGN ou ASSIGNED
                $constantVariations = ['ASSIGN', 'ASSIGNED'];
            } else {
                $constantVariations = [$constantName];
            }

            foreach ($constantVariations as $variation) {
                $fullConstantName = "CommonITILActor::{$variation}";
                if (defined($fullConstantName)) {
                    $value = constant($fullConstantName);
                    return (int)$value;
                }
                
                // Tentar usando reflexão como fallback
                try {
                    $reflection = new ReflectionClass('CommonITILActor');
                    if ($reflection->hasConstant($variation)) {
                        $value = $reflection->getConstant($variation);
                        return (int)$value;
                    }
                } catch (ReflectionException $e) {
                    // Continuar tentando
                }
            }

            Toolbox::logInFile('glpipwa', "GLPI PWA: Constante CommonITILActor::{$constantName} não encontrada (tentativas: " . implode(', ', $constantVariations) . ")", LOG_WARNING);
            return null;
        } catch (Exception $e) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro ao obter constante CommonITILActor::{$constantName}: " . $e->getMessage(), LOG_WARNING);
            return null;
        } catch (Throwable $e) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro fatal ao obter constante CommonITILActor::{$constantName}: " . $e->getMessage(), LOG_ERR);
            return null;
        }
    }
}

