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
 * Serviço centralizado de notificações
 * Responsável por coletar envolvidos, montar payloads e enviar notificações
 */
class PluginGlpipwaNotificationService
{
    /**
     * Arquivo de log para notificações
     */
    private const LOG_FILE = 'plugin-notify.log';

    /**
     * Método principal para enviar notificações
     * 
     * @param int $ticketId ID do ticket
     * @param string $eventType Tipo do evento (ticket_created, ticket_updated, etc.)
     * @param array $payload Dados adicionais para o payload
     * @param int|null $excludeUserId ID do usuário a ser excluído (autor da ação)
     * @return void
     */
    public static function notify($ticketId, string $eventType, array $payload, ?int $excludeUserId = null): void
    {
        try {
            self::log('debug', "Iniciando notificação para ticket ID: {$ticketId}, evento: {$eventType}");

            // Verificar se Firebase está configurado
            if (!class_exists('PluginGlpipwaConfig')) {
                self::log('warning', "PluginGlpipwaConfig não encontrado");
                return;
            }

            $config = PluginGlpipwaConfig::getAll();
            if (!PluginGlpipwaConfig::validateFirebaseConfig($config)) {
                self::log('warning', "Firebase não está configurado corretamente");
                return;
            }

            // Coletar destinatários
            $recipients = self::collectTicketRecipients($ticketId, $excludeUserId);

            if (empty($recipients)) {
                self::log('debug', "Nenhum destinatário encontrado para ticket ID: {$ticketId}");
                return;
            }

            self::log('debug', "Encontrados " . count($recipients) . " destinatários para ticket ID: {$ticketId}");

            // Montar título e corpo baseado no tipo de evento
            $title = self::buildTitle($eventType, $ticketId, $payload);
            $body = self::buildBody($eventType, $ticketId, $payload);

            // Montar payload completo
            $fullPayload = self::buildPayload($eventType, $ticketId, $payload);

            // Enviar notificação
            if (!class_exists('PluginGlpipwaNotificationPush')) {
                self::log('error', "PluginGlpipwaNotificationPush não encontrado");
                return;
            }

            $notificationPush = new PluginGlpipwaNotificationPush();
            $notificationPush->sendToUsers($recipients, $title, $body, $fullPayload);

            self::log('info', "Notificação enviada para ticket ID: {$ticketId}, evento: {$eventType}, destinatários: " . count($recipients));

        } catch (Exception $e) {
            self::log('error', "Erro ao enviar notificação: " . $e->getMessage(), [
                'ticket_id' => $ticketId,
                'event_type' => $eventType,
                'trace' => $e->getTraceAsString()
            ]);
        } catch (Throwable $e) {
            self::log('error', "Erro fatal ao enviar notificação: " . $e->getMessage(), [
                'ticket_id' => $ticketId,
                'event_type' => $eventType,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Coleta todos os envolvidos de um ticket
     * 
     * @param int $ticketId ID do ticket
     * @param int|null $excludeUserId ID do usuário a ser excluído
     * @return array Lista de IDs de usuários
     */
    private static function collectTicketRecipients($ticketId, ?int $excludeUserId = null): array
    {
        $recipients = [];

        if (!class_exists('Ticket')) {
            return $recipients;
        }

        try {
            $ticket = new Ticket();
            if (!$ticket->getFromDB($ticketId)) {
                self::log('warning', "Ticket ID {$ticketId} não encontrado");
                return $recipients;
            }

            // Técnico designado
            $tech_id = $ticket->getField('users_id_tech');
            if (self::isValidUserId($tech_id)) {
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
                            if (self::isValidUserId($user_id)) {
                                $recipients[] = (int)$user_id;
                            }
                        }
                    }
                } catch (Exception $e) {
                    self::log('warning', "Erro ao obter usuários do grupo técnico: " . $e->getMessage());
                }
            }

            // Observadores via Ticket_User
            if (class_exists('Ticket_User') && class_exists('CommonITILActor')) {
                try {
                    $ticket_user = new Ticket_User();
                    $observers = $ticket_user->find([
                        'tickets_id' => $ticketId,
                        'type' => CommonITILActor::OBSERVER
                    ]);
                    foreach ($observers as $obs) {
                        $user_id = $obs['users_id'] ?? null;
                        if (self::isValidUserId($user_id)) {
                            $recipients[] = (int)$user_id;
                        }
                    }
                } catch (Exception $e) {
                    self::log('warning', "Erro ao obter observadores: " . $e->getMessage());
                }
            }

            // Solicitantes via Ticket_User
            if (class_exists('Ticket_User') && class_exists('CommonITILActor')) {
                try {
                    $ticket_user = new Ticket_User();
                    $requesters = $ticket_user->find([
                        'tickets_id' => $ticketId,
                        'type' => CommonITILActor::REQUESTER
                    ]);
                    foreach ($requesters as $req) {
                        $user_id = $req['users_id'] ?? null;
                        if (self::isValidUserId($user_id)) {
                            $recipients[] = (int)$user_id;
                        }
                    }
                } catch (Exception $e) {
                    self::log('warning', "Erro ao obter solicitantes: " . $e->getMessage());
                }
            }

            // Técnicos via Ticket_User (ASSIGNED)
            if (class_exists('Ticket_User') && class_exists('CommonITILActor')) {
                try {
                    $ticket_user = new Ticket_User();
                    $assigned = $ticket_user->find([
                        'tickets_id' => $ticketId,
                        'type' => CommonITILActor::ASSIGNED
                    ]);
                    foreach ($assigned as $ass) {
                        $user_id = $ass['users_id'] ?? null;
                        if (self::isValidUserId($user_id)) {
                            $recipients[] = (int)$user_id;
                        }
                    }
                } catch (Exception $e) {
                    self::log('warning', "Erro ao obter técnicos atribuídos: " . $e->getMessage());
                }
            }

            // Remover duplicatas
            $recipients = array_unique($recipients);

            // Remover usuário excluído se especificado
            if ($excludeUserId !== null && $excludeUserId > 0) {
                $recipients = array_filter($recipients, function($id) use ($excludeUserId) {
                    return $id != $excludeUserId;
                });
            }

            // Filtrar para garantir que todos são válidos
            return self::filterValidUserIds($recipients);

        } catch (Exception $e) {
            self::log('error', "Erro ao coletar destinatários: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Monta o payload completo para a notificação
     * 
     * @param string $eventType Tipo do evento
     * @param int $ticketId ID do ticket
     * @param array $additionalData Dados adicionais
     * @return array Payload completo
     */
    private static function buildPayload(string $eventType, int $ticketId, array $additionalData = []): array
    {
        $payload = [
            'ticket_id' => (string)$ticketId,
            'type' => $eventType,
        ];

        // Adicionar URL do ticket se não estiver presente
        if (!isset($additionalData['url'])) {
            $payload['url'] = self::getTicketUrl($ticketId);
        } else {
            $payload['url'] = $additionalData['url'];
        }

        // Mesclar dados adicionais (convertendo todos para string para FCM)
        foreach ($additionalData as $key => $value) {
            if ($key !== 'url') { // URL já foi tratada
                $payload[$key] = (string)$value;
            }
        }

        return $payload;
    }

    /**
     * Constrói o título da notificação baseado no tipo de evento
     * 
     * @param string $eventType Tipo do evento
     * @param int $ticketId ID do ticket
     * @param array $payload Dados adicionais
     * @return string Título da notificação
     */
    private static function buildTitle(string $eventType, int $ticketId, array $payload = []): string
    {
        switch ($eventType) {
            case 'ticket_created':
                return sprintf(__('New Ticket #%d', 'glpipwa'), $ticketId);
            
            case 'ticket_updated':
                return sprintf(__('Ticket #%d Updated', 'glpipwa'), $ticketId);
            
            case 'followup_added':
                return sprintf(__('New interaction on Ticket #%d', 'glpipwa'), $ticketId);
            
            case 'actor_added':
                return sprintf(__('Ticket #%d - New participant', 'glpipwa'), $ticketId);
            
            case 'actor_updated':
                return sprintf(__('Ticket #%d - Participant updated', 'glpipwa'), $ticketId);
            
            case 'validation_added':
                return sprintf(__('Ticket #%d - Validation requested', 'glpipwa'), $ticketId);
            
            case 'validation_updated':
                return sprintf(__('Ticket #%d - Validation updated', 'glpipwa'), $ticketId);
            
            case 'task_added':
                return sprintf(__('Ticket #%d - New task', 'glpipwa'), $ticketId);
            
            default:
                return sprintf(__('Ticket #%d', 'glpipwa'), $ticketId);
        }
    }

    /**
     * Constrói o corpo da notificação baseado no tipo de evento
     * 
     * @param string $eventType Tipo do evento
     * @param int $ticketId ID do ticket
     * @param array $payload Dados adicionais
     * @return string Corpo da notificação
     */
    private static function buildBody(string $eventType, int $ticketId, array $payload = []): string
    {
        switch ($eventType) {
            case 'ticket_created':
                $name = $payload['ticket_name'] ?? __('Ticket', 'glpipwa');
                $urgency = $payload['urgency_name'] ?? '';
                if ($urgency) {
                    return sprintf(__('Ticket opened by %s - Urgency: %s', 'glpipwa'), $name, $urgency);
                }
                return sprintf(__('Ticket opened by %s', 'glpipwa'), $name);
            
            case 'ticket_updated':
                return __('The ticket has been updated', 'glpipwa');
            
            case 'followup_added':
                $author = $payload['author_name'] ?? __('User', 'glpipwa');
                $content = $payload['content'] ?? '';
                $preview = !empty($content) ? substr($content, 0, 100) : '';
                if ($preview) {
                    return sprintf(__('%s commented: %s', 'glpipwa'), $author, $preview);
                }
                return sprintf(__('%s added a comment', 'glpipwa'), $author);
            
            case 'actor_added':
                $actorName = $payload['actor_name'] ?? __('User', 'glpipwa');
                $actorType = $payload['actor_type'] ?? __('participant', 'glpipwa');
                return sprintf(__('%s was added as %s', 'glpipwa'), $actorName, $actorType);
            
            case 'actor_updated':
                $actorName = $payload['actor_name'] ?? __('User', 'glpipwa');
                return sprintf(__('%s participation was updated', 'glpipwa'), $actorName);
            
            case 'validation_added':
                return __('A validation was requested for this ticket', 'glpipwa');
            
            case 'validation_updated':
                $validator = $payload['validator_name'] ?? __('User', 'glpipwa');
                $status = $payload['status'] ?? 'updated';
                if ($status === 'accepted') {
                    return sprintf(__('Validation accepted by %s', 'glpipwa'), $validator);
                } elseif ($status === 'refused') {
                    return sprintf(__('Validation refused by %s', 'glpipwa'), $validator);
                }
                return sprintf(__('Validation updated by %s', 'glpipwa'), $validator);
            
            case 'task_added':
                $taskName = $payload['task_name'] ?? __('Task', 'glpipwa');
                $creator = $payload['creator_name'] ?? __('User', 'glpipwa');
                return sprintf(__('%s added task: %s', 'glpipwa'), $creator, $taskName);
            
            default:
                return __('An event occurred on this ticket', 'glpipwa');
        }
    }

    /**
     * Obtém URL completa do ticket
     * 
     * @param int $ticketId ID do ticket
     * @return string URL completa
     */
    private static function getTicketUrl(int $ticketId): string
    {
        if (!class_exists('Ticket')) {
            return '';
        }

        try {
            $ticket = new Ticket();
            if (!$ticket->getFromDB($ticketId)) {
                return '';
            }

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
        } catch (Exception $e) {
            self::log('warning', "Erro ao obter URL do ticket: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Valida se um valor é um ID de usuário válido
     * 
     * @param mixed $user_id Valor a ser validado
     * @return bool True se for válido
     */
    private static function isValidUserId($user_id): bool
    {
        if (!is_numeric($user_id)) {
            return false;
        }
        
        $user_id = (int)$user_id;
        return $user_id > 0;
    }

    /**
     * Filtra e valida um array de IDs de usuários
     * 
     * @param array $user_ids Array de IDs
     * @return array Array com IDs válidos
     */
    private static function filterValidUserIds(array $user_ids): array
    {
        $valid_ids = [];
        foreach ($user_ids as $id) {
            if (self::isValidUserId($id)) {
                $valid_ids[] = (int)$id;
            }
        }
        return array_unique($valid_ids);
    }

    /**
     * Registra log em arquivo próprio
     * 
     * @param string $level Nível do log (debug, info, warning, error)
     * @param string $message Mensagem do log
     * @param array $context Contexto adicional
     * @return void
     */
    private static function log(string $level, string $message, array $context = []): void
    {
        try {
            // Diretório de logs do GLPI
            $logDir = GLPI_ROOT . '/files/_log';
            
            // Garantir que o diretório existe
            if (!is_dir($logDir) && !mkdir($logDir, 0755, true)) {
                // Se não conseguir criar, usar Toolbox como fallback
                if (class_exists('Toolbox')) {
                    Toolbox::logInFile('glpipwa', "GLPI PWA NotificationService: " . $message, LOG_DEBUG);
                }
                return;
            }

            $logFile = $logDir . '/' . self::LOG_FILE;
            
            // Formatar mensagem
            $timestamp = date('Y-m-d H:i:s');
            $levelUpper = strtoupper($level);
            $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
            $logMessage = "[{$timestamp}] [{$levelUpper}] {$message}{$contextStr}" . PHP_EOL;
            
            // Escrever no arquivo (append)
            file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
            
            // Também usar Toolbox para logs de erro/warning
            if ($level === 'error' || $level === 'warning') {
                if (class_exists('Toolbox')) {
                    if ($level === 'error') {
                        Toolbox::logInFile('glpipwa', "GLPI PWA NotificationService: " . $message, LOG_ERR);
                    } else {
                        Toolbox::logInFile('glpipwa', "GLPI PWA NotificationService: " . $message, LOG_WARNING);
                    }
                }
            }
        } catch (Exception $e) {
            // Silenciosamente falha no log se não conseguir escrever
            // Não queremos que erro de log quebre a notificação
        } catch (Throwable $e) {
            // Ignorar erros fatais no log também
        }
    }
}

