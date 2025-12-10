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
            if (in_array($error, ['InvalidRegistration', 'NotRegistered'])) {
                // Token inválido, remover do banco
                if (isset($message['to'])) {
                    PluginGlpipwaToken::deleteToken($message['to']);
                }
            }
            return false;
        }

        return $response;
    }

    /**
     * Notifica sobre novo ticket
     */
    public function notifyNewTicket(Ticket $ticket)
    {
        $recipients = $this->getTicketRecipients($ticket);
        
        if (empty($recipients)) {
            return;
        }

        $title = sprintf(__('Novo Chamado #%d', 'glpipwa'), $ticket->getID());
        $urgency = $ticket->getField('urgency');
        $urgencyName = Ticket::getUrgencyName($urgency);
        $body = sprintf(
            __('Chamado aberto por %s - Urgência: %s', 'glpipwa'),
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
        $recipients = $this->getTicketRecipients($ticket);
        
        if (empty($recipients)) {
            return;
        }

        $title = sprintf(__('Atualização no Chamado #%d', 'glpipwa'), $ticket->getID());
        $body = __('O chamado foi atualizado', 'glpipwa');

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
        $item = $followup->getItem();
        
        if (!$item instanceof Ticket) {
            return;
        }

        $recipients = $this->getTicketRecipients($item);
        
        // Remover o autor do follow-up dos destinatários
        $author_id = $followup->getField('users_id');
        $recipients = array_filter($recipients, function($id) use ($author_id) {
            return $id != $author_id;
        });

        if (empty($recipients)) {
            return;
        }

        $user = new User();
        $user->getFromDB($author_id);
        $authorName = $user->getName();

        $title = sprintf(__('Nova interação no Chamado #%d', 'glpipwa'), $item->getID());
        $body = sprintf(__('%s comentou: %s', 'glpipwa'), $authorName, substr($followup->getField('content'), 0, 100));

        $data = [
            'url' => $item->getLinkURL(),
            'ticket_id' => $item->getID(),
            'type' => 'new_followup',
        ];

        $this->sendToUsers($recipients, $title, $body, $data);
    }

    /**
     * Obtém destinatários de notificação para um ticket
     */
    private function getTicketRecipients(Ticket $ticket)
    {
        $recipients = [];

        // Técnico designado
        $tech_id = $ticket->getField('users_id_tech');
        if ($tech_id > 0) {
            $recipients[] = $tech_id;
        }

        // Grupo técnico
        $tech_group = $ticket->getField('groups_id_tech');
        if ($tech_group > 0) {
            $group = new Group();
            if ($group->getFromDB($tech_group)) {
                $groupUsers = Group_User::getGroupUsers($tech_group);
                foreach ($groupUsers as $user) {
                    $recipients[] = $user['id'];
                }
            }
        }

        // Observadores
        $observer = new Ticket_User();
        $observers = $observer->find(['tickets_id' => $ticket->getID(), 'type' => CommonITILActor::OBSERVER]);
        foreach ($observers as $obs) {
            $recipients[] = $obs['users_id'];
        }

        // Requerente (para atualizações)
        $requester_id = $ticket->getField('users_id_recipient');
        if ($requester_id > 0) {
            $recipients[] = $requester_id;
        }

        // Remover duplicatas
        $recipients = array_unique($recipients);
        
        // Remover o autor do ticket se for criação
        $creator_id = $ticket->getField('users_id_recipient');
        if ($creator_id > 0) {
            $recipients = array_filter($recipients, function($id) use ($creator_id) {
                return $id != $creator_id;
            });
        }

        return array_values($recipients);
    }
}

