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
 * Classe para envio de notificações push via FCM
 */
class PluginGlpipwaNotificationPush
{
    const FCM_URL = 'https://fcm.googleapis.com/fcm/send';

    /**
     * Envia notificação para um token específico
     */
    public function send($token, $title, $body, $data = [])
    {
        $serverKey = PluginGlpipwaConfig::get('firebase_server_key');
        
        if (empty($serverKey)) {
            return false;
        }

        $message = [
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'data' => $data,
            'to' => $token,
        ];

        return $this->sendToFCM($message);
    }

    /**
     * Envia notificação para todos os tokens de um usuário
     */
    public function sendToUser($users_id, $title, $body, $data = [])
    {
        $tokens = PluginGlpipwaToken::getUserTokens($users_id);
        
        if (empty($tokens)) {
            return false;
        }

        $results = [];
        foreach ($tokens as $token) {
            $results[] = $this->send($token, $title, $body, $data);
        }

        return $results;
    }

    /**
     * Envia notificação para múltiplos usuários
     */
    public function sendToUsers(array $users_ids, $title, $body, $data = [])
    {
        $usersTokens = PluginGlpipwaToken::getUsersTokens($users_ids);
        
        if (empty($usersTokens)) {
            return false;
        }

        $results = [];
        foreach ($usersTokens as $users_id => $tokens) {
            foreach ($tokens as $token) {
                $results[] = $this->send($token, $title, $body, $data);
            }
        }

        return $results;
    }

    /**
     * Envia requisição para FCM
     */
    private function sendToFCM(array $message)
    {
        $serverKey = PluginGlpipwaConfig::get('firebase_server_key');
        
        if (empty($serverKey)) {
            return false;
        }

        $headers = [
            'Authorization: key=' . $serverKey,
            'Content-Type: application/json',
        ];

        $ch = curl_init(self::FCM_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Toolbox::logError("FCM Error: " . $error);
            return false;
        }

        if ($httpCode !== 200) {
            Toolbox::logError("FCM HTTP Error: " . $httpCode . " - " . $result);
            return false;
        }

        $response = json_decode($result, true);
        
        // Verificar se o token é inválido
        if (isset($response['results'][0]['error'])) {
            $error = $response['results'][0]['error'];
            // Lista de erros que indicam token inválido ou expirado
            $invalidTokenErrors = [
                'InvalidRegistration',      // Token mal formatado
                'NotRegistered',            // Token não está mais registrado
                'MismatchSenderId',         // Token registrado com outro sender
                'InvalidPackageName',       // Nome do pacote inválido
                'InvalidApnsCredential',    // Credenciais APNS inválidas (iOS)
            ];
            
            if (in_array($error, $invalidTokenErrors)) {
                // Token inválido, remover do banco
                if (isset($message['to'])) {
                    PluginGlpipwaToken::deleteToken($message['to']);
                    Toolbox::logDebug("GLPIPWA: Token removido devido a erro FCM: " . $error);
                }
            }
            return false;
        }

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
        // Verificar se Firebase está configurado
        if (!$this->isFirebaseConfigured()) {
            return;
        }

        $recipients = $this->getTicketRecipients($ticket, false);
        
        if (empty($recipients)) {
            return;
        }

        $title = sprintf(__('Ticket #%d Updated', 'glpipwa'), $ticket->getID());
        $body = __('The ticket has been updated', 'glpipwa');

        $data = [
            'url' => $ticket->getLinkURL(),
            'ticket_id' => $ticket->getID(),
            'type' => 'ticket_update',
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

