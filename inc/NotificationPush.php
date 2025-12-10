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

        $message = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $dataStrings,
            ],
        ];

        return $this->sendToFCM($message, $token);
    }

    /**
     * Envia notificação para todos os tokens de um usuário
     */
    public function sendToUser($users_id, $title, $body, $data = [])
    {
        $tokens = PluginGlpipwaToken::getUserTokens($users_id);
        
        if (empty($tokens)) {
            Toolbox::logDebug("GLPI PWA: Nenhum token encontrado para o usuário ID: {$users_id}");
            return false;
        }

        $totalTokens = count($tokens);
        Toolbox::logDebug("GLPI PWA: Enviando notificação para usuário ID: {$users_id} - Total de tokens: {$totalTokens}");

        $results = [];
        $successCount = 0;
        $failureCount = 0;
        $firstToken = true;

        foreach ($tokens as $token) {
            // Adicionar delay de 100ms entre requisições para evitar rate limiting (exceto na primeira)
            if (!$firstToken) {
                usleep(100000); // 100ms em microsegundos
            }
            $firstToken = false;

            try {
                $result = $this->send($token, $title, $body, $data);
                $results[] = $result;
                
                if ($result !== false) {
                    $successCount++;
                    Toolbox::logDebug("GLPI PWA: Notificação enviada com sucesso para token (usuário ID: {$users_id})");
                } else {
                    $failureCount++;
                    Toolbox::logWarning("GLPI PWA: Falha ao enviar notificação para token (usuário ID: {$users_id})");
                }
            } catch (Exception $e) {
                $failureCount++;
                $results[] = false;
                Toolbox::logError("GLPI PWA: Exceção ao enviar notificação para token (usuário ID: {$users_id}): " . $e->getMessage());
            }
        }

        Toolbox::logDebug("GLPI PWA: Resumo de envio para usuário ID: {$users_id} - Sucessos: {$successCount}, Falhas: {$failureCount}, Total: {$totalTokens}");

        return $results;
    }

    /**
     * Envia notificação para múltiplos usuários
     */
    public function sendToUsers(array $users_ids, $title, $body, $data = [])
    {
        $usersTokens = PluginGlpipwaToken::getUsersTokens($users_ids);
        
        if (empty($usersTokens)) {
            Toolbox::logDebug("GLPI PWA: Nenhum token encontrado para os usuários: " . implode(', ', $users_ids));
            return false;
        }

        $totalUsers = count($usersTokens);
        $totalTokens = 0;
        foreach ($usersTokens as $tokens) {
            $totalTokens += count($tokens);
        }
        Toolbox::logDebug("GLPI PWA: Enviando notificação para {$totalUsers} usuário(s) - Total de tokens: {$totalTokens}");

        $results = [];
        $successCount = 0;
        $failureCount = 0;
        $firstToken = true;

        foreach ($usersTokens as $users_id => $tokens) {
            foreach ($tokens as $token) {
                // Adicionar delay de 100ms entre requisições para evitar rate limiting (exceto na primeira)
                if (!$firstToken) {
                    usleep(100000); // 100ms em microsegundos
                }
                $firstToken = false;

                try {
                    $result = $this->send($token, $title, $body, $data);
                    $results[] = $result;
                    
                    if ($result !== false) {
                        $successCount++;
                        Toolbox::logDebug("GLPI PWA: Notificação enviada com sucesso para token (usuário ID: {$users_id})");
                    } else {
                        $failureCount++;
                        Toolbox::logWarning("GLPI PWA: Falha ao enviar notificação para token (usuário ID: {$users_id})");
                    }
                } catch (Exception $e) {
                    $failureCount++;
                    $results[] = false;
                    Toolbox::logError("GLPI PWA: Exceção ao enviar notificação para token (usuário ID: {$users_id}): " . $e->getMessage());
                }
            }
        }

        Toolbox::logDebug("GLPI PWA: Resumo de envio para múltiplos usuários - Sucessos: {$successCount}, Falhas: {$failureCount}, Total: {$totalTokens}");

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
            Toolbox::logError("GLPI PWA: Número máximo de tentativas excedido para envio FCM");
            return false;
        }

        // Obter access token OAuth2
        $accessToken = PluginGlpipwaFirebaseAuth::getAccessToken();
        
        if (!$accessToken) {
            Toolbox::logError("GLPI PWA: Não foi possível obter access token OAuth2");
            return false;
        }

        // Obter project ID
        $projectId = PluginGlpipwaConfig::get('firebase_project_id');
        
        if (empty($projectId)) {
            Toolbox::logError("GLPI PWA: Project ID não configurado");
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

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Toolbox::logError("GLPI PWA FCM Error: " . $error);
            return false;
        }

        $response = json_decode($result, true);

        // Tratamento de erros FCM v1
        if ($httpCode !== 200) {
            $errorMessage = $response['error']['message'] ?? 'Unknown error';
            $errorCode = $response['error']['code'] ?? 'UNKNOWN';
            
            Toolbox::logError("GLPI PWA FCM HTTP Error: " . $httpCode . " - " . $errorCode . " - " . $errorMessage);

            // Tratar erros específicos
            switch ($errorCode) {
                case 'UNAUTHENTICATED':
                    // Token OAuth2 inválido, limpar cache e tentar novamente uma vez
                    if ($retryCount === 0) {
                        PluginGlpipwaFirebaseAuth::clearCache();
                        $accessToken = PluginGlpipwaFirebaseAuth::getAccessToken();
                        if ($accessToken) {
                            // Tentar novamente com novo token
                            return $this->sendToFCM($message, $token, $retryCount + 1);
                        }
                    }
                    break;
                
                case 'NOT_FOUND':
                case 'UNREGISTERED':
                    // Token de dispositivo não encontrado ou não registrado
                    PluginGlpipwaToken::deleteToken($token);
                    Toolbox::logDebug("GLPI PWA: Token removido devido a erro FCM: " . $errorCode);
                    break;
                
                case 'INVALID_ARGUMENT':
                    // Payload ou token inválido
                    Toolbox::logError("GLPI PWA: Argumento inválido no payload FCM");
                    break;
                
                case 'PERMISSION_DENIED':
                    // Service Account sem permissões
                    Toolbox::logError("GLPI PWA: Service Account sem permissões para enviar mensagens FCM");
                    break;
            }
            
            return false;
        }

        // Sucesso
        return $response;
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
            'url' => $ticket->getLinkURL(),
            'ticket_id' => $ticket->getID(),
            'type' => 'new_ticket',
        ];

        $this->sendToUsers($recipients, $title, $body, $data);
    }

    /**
     * Notifica sobre atualização de ticket
     */
    public function notifyTicketUpdate(Ticket $ticket)
    {
        $ticketId = $ticket->getID();
        Toolbox::logDebug("GLPI PWA: notifyTicketUpdate chamado para ticket ID: {$ticketId}");
        
        // Verificar se Firebase está configurado
        if (!$this->isFirebaseConfigured()) {
            Toolbox::logWarning("GLPI PWA: Firebase não está configurado corretamente para ticket ID: {$ticketId}");
            return;
        }

        $recipients = $this->getTicketRecipients($ticket, false);
        Toolbox::logDebug("GLPI PWA: Destinatários encontrados para atualização de ticket ID: {$ticketId} - Total: " . count($recipients));
        
        if (empty($recipients)) {
            Toolbox::logDebug("GLPI PWA: Nenhum destinatário encontrado para atualização de ticket ID: {$ticketId}");
            return;
        }

        $title = sprintf(__('Ticket #%d Updated', 'glpipwa'), $ticketId);
        $body = __('The ticket has been updated', 'glpipwa');

        $data = [
            'url' => $ticket->getLinkURL(),
            'ticket_id' => $ticketId,
            'type' => 'ticket_update',
        ];

        Toolbox::logDebug("GLPI PWA: Enviando notificação de atualização para ticket ID: {$ticketId} - Título: {$title}");
        $this->sendToUsers($recipients, $title, $body, $data);
        Toolbox::logDebug("GLPI PWA: Notificação de atualização processada para ticket ID: {$ticketId}");
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
        $recipients = array_filter($recipients, function($id) use ($author_id) {
            return $id != $author_id;
        });

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
            'url' => $item->getLinkURL(),
            'ticket_id' => $item->getID(),
            'type' => 'new_followup',
        ];

        $this->sendToUsers($recipients, $title, $body, $data);
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
        if ($tech_id > 0) {
            $recipients[] = $tech_id;
        }

        // Grupo técnico
        $tech_group = $ticket->getField('groups_id_tech');
        if ($tech_group > 0 && class_exists('Group') && class_exists('Group_User')) {
            try {
                $group = new Group();
                if ($group->getFromDB($tech_group)) {
                    $groupUsers = Group_User::getGroupUsers($tech_group);
                    foreach ($groupUsers as $user) {
                        $recipients[] = $user['id'];
                    }
                }
            } catch (Exception $e) {
                // Silenciosamente ignora erros
            }
        }

        // Observadores via Ticket_User
        if (class_exists('Ticket_User') && class_exists('CommonITILActor')) {
            try {
                $ticket_user = new Ticket_User();
                $observers = $ticket_user->find([
                    'tickets_id' => $ticket->getID(),
                    'type' => CommonITILActor::OBSERVER
                ]);
                foreach ($observers as $obs) {
                    $recipients[] = $obs['users_id'];
                }
            } catch (Exception $e) {
                // Silenciosamente ignora erros
            }
        }

        // Solicitantes via Ticket_User (pode haver múltiplos)
        if (class_exists('Ticket_User') && class_exists('CommonITILActor')) {
            try {
                $ticket_user = new Ticket_User();
                $requesters = $ticket_user->find([
                    'tickets_id' => $ticket->getID(),
                    'type' => CommonITILActor::REQUESTER
                ]);
                foreach ($requesters as $req) {
                    $recipients[] = $req['users_id'];
                }
            } catch (Exception $e) {
                // Silenciosamente ignora erros
            }
        }

        // Remover duplicatas
        $recipients = array_unique($recipients);
        
        // Se for novo ticket, remover apenas o criador real (quem registrou o chamado)
        // Isso permite que solicitantes diferentes do criador recebam notificação
        if ($isNewTicket) {
            // users_id_recipient é quem criou o registro no sistema
            $creator_id = $ticket->getField('users_id_recipient');
            if ($creator_id > 0) {
                $recipients = array_filter($recipients, function($id) use ($creator_id) {
                    return $id != $creator_id;
                });
            }
        }

        return array_values($recipients);
    }
}

