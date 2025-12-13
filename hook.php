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

// Definir versão do plugin se ainda não estiver definida
if (!defined('PLUGIN_GLPIPWA_VERSION')) {
    define('PLUGIN_GLPIPWA_VERSION', '1.0.6');
}

/**
 * Carrega as classes necessárias do plugin
 */
function plugin_glpipwa_load_hook_classes() {
    static $loaded = false;
    if (!$loaded) {
        require_once(__DIR__ . '/inc/NotificationPush.php');
        require_once(__DIR__ . '/inc/NotificationService.php');
        $loaded = true;
    }
}

/**
 * Armazena o estado anterior dos tickets para comparação
 * Chave: ticket ID, Valor: array com campos do ticket
 */
if (!isset($GLOBALS['plugin_glpipwa_ticket_previous_state'])) {
    $GLOBALS['plugin_glpipwa_ticket_previous_state'] = [];
}

/**
 * Armazena tickets recém-criados para evitar notificação de "updated" logo após criação
 * Chave: ticket ID, Valor: timestamp de criação
 */
if (!isset($GLOBALS['plugin_glpipwa_new_tickets'])) {
    $GLOBALS['plugin_glpipwa_new_tickets'] = [];
}

/**
 * Hook chamado antes de um item ser atualizado
 * Captura o estado anterior do ticket para comparação posterior
 */
function plugin_glpipwa_pre_item_update($item) {
    try {
        // Verificar se a classe Ticket existe antes de usar instanceof
        if (!class_exists('Ticket')) {
            return;
        }
        
        if ($item instanceof Ticket) {
            $ticketId = $item->getID();
            
            if ($ticketId > 0) {
                // Carregar o ticket do banco para obter o estado atual (antes da atualização)
                $ticket = new Ticket();
                if ($ticket->getFromDB($ticketId)) {
                    // Armazenar campos relevantes para comparação
                    $previousStatus = $ticket->getField('status');
                    $previousTech = $ticket->getField('users_id_tech');
                    $previousGroup = $ticket->getField('groups_id_tech');
                    
                    // Normalizar valores null/0 para comparação adequada
                    $previousTechValue = ($previousTech && $previousTech > 0) ? (int)$previousTech : 0;
                    $previousGroupValue = ($previousGroup && $previousGroup > 0) ? (int)$previousGroup : 0;
                    
                    $GLOBALS['plugin_glpipwa_ticket_previous_state'][$ticketId] = [
                        'status' => $previousStatus,
                        'users_id_tech' => $previousTechValue,
                        'groups_id_tech' => $previousGroupValue,
                        'urgency' => $ticket->getField('urgency'),
                        'priority' => $ticket->getField('priority'),
                        'impact' => $ticket->getField('impact'),
                    ];
                    
                } else {
                    if (class_exists('Toolbox')) {
                        Toolbox::logInFile('glpipwa', "GLPI PWA: Não foi possível carregar ticket ID: {$ticketId} para capturar estado anterior", LOG_WARNING);
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Silenciosamente ignora erros para não quebrar o fluxo do GLPI
        if (class_exists('Toolbox')) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro em plugin_glpipwa_pre_item_update - " . $e->getMessage(), LOG_ERR);
        }
    } catch (Throwable $e) {
        // Capturar também erros fatais
        if (class_exists('Toolbox')) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro fatal em plugin_glpipwa_pre_item_update - " . $e->getMessage(), LOG_ERR);
        }
    }
}

/**
 * Hook chamado quando um item é adicionado
 */
function plugin_glpipwa_item_add($item) {
    try {
        if (!class_exists('Ticket')) {
            return;
        }
        
        if ($item instanceof Ticket) {
            $ticketId = $item->getID();
            if ($ticketId <= 0) {
                return;
            }
            
            // Marcar ticket como recém-criado para evitar notificação de "updated" logo após
            $GLOBALS['plugin_glpipwa_new_tickets'][$ticketId] = time();
            
            plugin_glpipwa_load_hook_classes();
            
            if (!class_exists('PluginGlpipwaNotificationService')) {
                if (class_exists('Toolbox')) {
                    Toolbox::logInFile('glpipwa', "GLPI PWA: plugin_glpipwa_item_add - PluginGlpipwaNotificationService não encontrado", LOG_WARNING);
                }
                return;
            }
            
            // Montar payload com dados do ticket
            $urgency = $item->getField('urgency');
            $urgencyName = '';
            if (method_exists('Ticket', 'getUrgencyName')) {
                $urgencyName = Ticket::getUrgencyName($urgency);
            }
            
            $payload = [
                'ticket_name' => $item->getField('name'),
                'urgency_name' => $urgencyName,
            ];
            
            // Obter criador do ticket para excluir das notificações
            $creatorId = $item->getField('users_id_recipient');
            $excludeUserId = (!empty($creatorId) && is_numeric($creatorId) && (int)$creatorId > 0) ? (int)$creatorId : null;
            
            PluginGlpipwaNotificationService::notify($ticketId, 'ticket_created', $payload, $excludeUserId);
        }
    } catch (Exception $e) {
        if (class_exists('Toolbox')) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro em plugin_glpipwa_item_add - " . $e->getMessage(), LOG_ERR);
        }
    } catch (Throwable $e) {
        if (class_exists('Toolbox')) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro fatal em plugin_glpipwa_item_add - " . $e->getMessage(), LOG_ERR);
        }
    }
}

/**
 * Hook chamado quando um item é atualizado
 */
function plugin_glpipwa_item_update($item) {
    try {
        if (!class_exists('Ticket')) {
            return;
        }
        
        if ($item instanceof Ticket) {
            $ticketId = $item->getID();
            if ($ticketId <= 0) {
                return;
            }
            
            // Verificar se o ticket é recém-criado (dentro de 5 segundos)
            // Isso evita notificação de "atualizado" quando o ticket está sendo criado
            // (o GLPI pode disparar item_update antes de item_add durante a criação)
            $dateCreation = $item->getField('date_creation');
            if (!empty($dateCreation)) {
                try {
                    $creationTimestamp = strtotime($dateCreation);
                    $currentTimestamp = time();
                    $timeSinceCreation = $currentTimestamp - $creationTimestamp;
                    
                    if ($timeSinceCreation < 5) {
                        return;
                    }
                } catch (Exception $e) {
                    // Se falhar ao converter data, continua com o fluxo normal
                    if (class_exists('Toolbox')) {
                        Toolbox::logInFile('glpipwa', "GLPI PWA: plugin_glpipwa_item_update - Erro ao verificar data de criação: " . $e->getMessage(), LOG_WARNING);
                    }
                }
            }
            
            // Verificação adicional via variável global (para tickets marcados pelo item_add)
            if (isset($GLOBALS['plugin_glpipwa_new_tickets'][$ticketId])) {
                $creationTime = $GLOBALS['plugin_glpipwa_new_tickets'][$ticketId];
                $timeSinceCreation = time() - $creationTime;
                
                if ($timeSinceCreation < 30) {
                    return;
                }
                unset($GLOBALS['plugin_glpipwa_new_tickets'][$ticketId]);
            }
            
            plugin_glpipwa_load_hook_classes();
            
            if (!class_exists('PluginGlpipwaNotificationService')) {
                if (class_exists('Toolbox')) {
                    Toolbox::logInFile('glpipwa', "GLPI PWA: plugin_glpipwa_item_update - PluginGlpipwaNotificationService não encontrado", LOG_WARNING);
                }
                return;
            }
            
            // Obter estado anterior se disponível
            $previousState = null;
            if (isset($GLOBALS['plugin_glpipwa_ticket_previous_state'][$ticketId])) {
                $previousState = $GLOBALS['plugin_glpipwa_ticket_previous_state'][$ticketId];
                unset($GLOBALS['plugin_glpipwa_ticket_previous_state'][$ticketId]);
            }
            
            // Comparar mudanças - só notificar se houver mudança relevante
            if (!plugin_glpipwa_has_relevant_change($item, $previousState, $item->input ?? [])) {
                return;
            }
            
            // Obter usuário atual para excluir das notificações
            $excludeUserId = plugin_glpipwa_get_current_user_id();
            
            PluginGlpipwaNotificationService::notify($ticketId, 'ticket_updated', [], $excludeUserId);
        }
    } catch (Exception $e) {
        if (class_exists('Toolbox')) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro em plugin_glpipwa_item_update - " . $e->getMessage(), LOG_ERR);
        }
    } catch (Throwable $e) {
        if (class_exists('Toolbox')) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro fatal em plugin_glpipwa_item_update - " . $e->getMessage(), LOG_ERR);
        }
    }
}

/**
 * Verifica se houve mudança relevante no ticket
 * 
 * @param Ticket $ticket Ticket atualizado
 * @param array|null $previousState Estado anterior
 * @param array $input Dados da atualização
 * @return bool True se houver mudança relevante
 */
function plugin_glpipwa_has_relevant_change($ticket, $previousState, array $input): bool
{
    // Se não há estado anterior, considerar como mudança relevante
    if ($previousState === null || empty($previousState)) {
        return true;
    }
    
    // Campos considerados relevantes para notificação
    $relevantFields = ['status', 'users_id_tech', 'groups_id_tech', 'urgency', 'priority', 'impact', 'name'];
    
    foreach ($relevantFields as $field) {
        // Verificar em input (novos valores)
        if (isset($input[$field])) {
            $newValue = $input[$field];
            $oldValue = $previousState[$field] ?? null;
            
            if ($newValue != $oldValue) {
                return true;
            }
        }
        
        // Verificar se campo foi alterado comparando com fields atual
        $currentValue = $ticket->getField($field);
        $oldValue = $previousState[$field] ?? null;
        
        if ($currentValue != $oldValue) {
            return true;
        }
    }
    
    return false;
}

/**
 * Hook chamado quando um follow-up é adicionado
 */
function plugin_glpipwa_followup_add($item) {
    try {
        if (!class_exists('ITILFollowup')) {
            return;
        }
        
        if ($item instanceof ITILFollowup) {
            // Verificar se é um follow-up de ticket
            $itemtype = $item->getField('itemtype');
            if ($itemtype !== 'Ticket') {
                return;
            }
            
            $ticketId = $item->getField('items_id');
            if ($ticketId <= 0) {
                return;
            }
            
            plugin_glpipwa_load_hook_classes();
            
            if (!class_exists('PluginGlpipwaNotificationService')) {
                if (class_exists('Toolbox')) {
                    Toolbox::logInFile('glpipwa', "GLPI PWA: plugin_glpipwa_followup_add - PluginGlpipwaNotificationService não encontrado", LOG_WARNING);
                }
                return;
            }
            
            // Obter nome do autor
            $authorId = $item->getField('users_id');
            $authorName = __('User', 'glpipwa');
            if (class_exists('User') && plugin_glpipwa_is_valid_user_id($authorId)) {
                try {
                    $user = new User();
                    if ($user->getFromDB($authorId)) {
                        $authorName = $user->getName();
                    }
                } catch (Exception $e) {
                    // Usar nome padrão
                }
            }
            
            // Sanitizar conteúdo HTML antes de passar para o payload
            $content = plugin_glpipwa_sanitize_content($item->getField('content'));
            
            $payload = [
                'author_name' => $authorName,
                'content' => $content,
            ];
            
            $excludeUserId = (plugin_glpipwa_is_valid_user_id($authorId)) ? (int)$authorId : null;
            
            PluginGlpipwaNotificationService::notify($ticketId, 'followup_added', $payload, $excludeUserId);
        }
    } catch (Exception $e) {
        if (class_exists('Toolbox')) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro em plugin_glpipwa_followup_add - " . $e->getMessage(), LOG_ERR);
        }
    } catch (Throwable $e) {
        if (class_exists('Toolbox')) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro fatal em plugin_glpipwa_followup_add - " . $e->getMessage(), LOG_ERR);
        }
    }
}

/**
 * Valida se um ID de usuário é válido
 */
function plugin_glpipwa_is_valid_user_id($user_id): bool
{
    if (!is_numeric($user_id)) {
        return false;
    }
    return (int)$user_id > 0;
}

/**
 * Obtém o ID do usuário logado atual de forma segura
 * 
 * @return int|null ID do usuário ou null se não disponível
 */
function plugin_glpipwa_get_current_user_id(): ?int
{
    try {
        if (class_exists('Session') && method_exists('Session', 'getLoginUserID')) {
            $userId = Session::getLoginUserID();
            if (plugin_glpipwa_is_valid_user_id($userId)) {
                return (int)$userId;
            }
        }
    } catch (Exception $e) {
        // Silenciosamente retorna null em caso de erro
    } catch (Throwable $e) {
        // Silenciosamente retorna null em caso de erro fatal
    }
    return null;
}

/**
 * Sanitiza conteúdo HTML removendo tags e decodificando entidades
 * 
 * @param string $content Conteúdo a ser sanitizado
 * @return string Conteúdo sanitizado
 */
function plugin_glpipwa_sanitize_content(string $content): string
{
    try {
        // Remover todas as tags HTML
        $content = strip_tags($content);
        // Decodificar entidades HTML (como &nbsp;, &lt;, etc.)
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Normalizar espaços em branco (múltiplos espaços viram um só)
        $content = preg_replace('/\s+/', ' ', $content);
        // Remover espaços no início e fim
        return trim($content);
    } catch (Exception $e) {
        // Em caso de erro, retornar conteúdo original sem tags
        return strip_tags($content);
    } catch (Throwable $e) {
        // Em caso de erro fatal, retornar conteúdo original sem tags
        return strip_tags($content);
    }
}

/**
 * Hook chamado quando um Ticket_User é adicionado (novo envolvido)
 */
function plugin_glpipwa_ticket_user_add($item) {
    try {
        if (!class_exists('Ticket_User')) {
            return;
        }
        
        if ($item instanceof Ticket_User) {
            $ticketId = $item->getField('tickets_id');
            if ($ticketId <= 0) {
                return;
            }
            
            // Verificar se ticket foi criado recentemente (dentro de 2s)
            // Isso evita notificações duplicadas quando um ticket é criado com envolvidos
            if (isset($GLOBALS['plugin_glpipwa_new_tickets'][$ticketId])) {
                $creationTime = $GLOBALS['plugin_glpipwa_new_tickets'][$ticketId];
                $timeSinceCreation = time() - $creationTime;
                
                if ($timeSinceCreation < 2) {
                    return;
                }
                // Remover da lista após 2 segundos para não acumular memória
                unset($GLOBALS['plugin_glpipwa_new_tickets'][$ticketId]);
            }
            
            plugin_glpipwa_load_hook_classes();
            
            if (!class_exists('PluginGlpipwaNotificationService')) {
                if (class_exists('Toolbox')) {
                    Toolbox::logInFile('glpipwa', "GLPI PWA: plugin_glpipwa_ticket_user_add - PluginGlpipwaNotificationService não encontrado", LOG_WARNING);
                }
                return;
            }
            
            // Obter informações do envolvido
            $userId = $item->getField('users_id');
            $type = $item->getField('type');
            
            $userName = __('User', 'glpipwa');
            if (class_exists('User') && plugin_glpipwa_is_valid_user_id($userId)) {
                try {
                    $user = new User();
                    if ($user->getFromDB($userId)) {
                        $userName = $user->getName();
                    }
                } catch (Exception $e) {
                    // Usar nome padrão em caso de erro
                }
            }
            
            // Mapear tipo para nome legível
            $typeName = __('participant', 'glpipwa');
            if (class_exists('CommonITILActor')) {
                // Usar valores numéricos diretos para evitar problemas com constantes
                // REQUESTER = 1, ASSIGN = 2, OBSERVER = 3
                $REQUESTER = defined('CommonITILActor::REQUESTER') ? CommonITILActor::REQUESTER : 1;
                $ASSIGN = defined('CommonITILActor::ASSIGN') ? CommonITILActor::ASSIGN : 
                         (defined('CommonITILActor::ASSIGNED') ? CommonITILActor::ASSIGNED : 2);
                $OBSERVER = defined('CommonITILActor::OBSERVER') ? CommonITILActor::OBSERVER : 3;
                
                switch ($type) {
                    case $REQUESTER:
                        $typeName = __('requester', 'glpipwa');
                        break;
                    case $ASSIGN:
                        $typeName = __('assigned technician', 'glpipwa');
                        break;
                    case $OBSERVER:
                        $typeName = __('observer', 'glpipwa');
                        break;
                }
            }
            
            $payload = [
                'actor_name' => $userName,
                'actor_type' => $typeName,
            ];
            
            // Obter usuário atual para excluir das notificações
            $excludeUserId = plugin_glpipwa_get_current_user_id();
            
            PluginGlpipwaNotificationService::notify($ticketId, 'actor_added', $payload, $excludeUserId);
        }
    } catch (Exception $e) {
        if (class_exists('Toolbox')) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro em plugin_glpipwa_ticket_user_add - " . $e->getMessage(), LOG_ERR);
        }
    } catch (Throwable $e) {
        if (class_exists('Toolbox')) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro fatal em plugin_glpipwa_ticket_user_add - " . $e->getMessage(), LOG_ERR);
        }
    }
}

/**
 * Hook chamado quando um Ticket_User é atualizado (alteração de participação)
 */
function plugin_glpipwa_ticket_user_update($item) {
    try {
        if (!class_exists('Ticket_User')) {
            return;
        }
        
        if ($item instanceof Ticket_User) {
            $ticketId = $item->getField('tickets_id');
            if ($ticketId <= 0) {
                return;
            }
            
            plugin_glpipwa_load_hook_classes();
            
            if (!class_exists('PluginGlpipwaNotificationService')) {
                return;
            }
            
            // Obter informações do envolvido
            $userId = $item->getField('users_id');
            
            $userName = __('User', 'glpipwa');
            if (class_exists('User') && plugin_glpipwa_is_valid_user_id($userId)) {
                try {
                    $user = new User();
                    if ($user->getFromDB($userId)) {
                        $userName = $user->getName();
                    }
                } catch (Exception $e) {
                    // Usar nome padrão
                }
            }
            
            $payload = [
                'actor_name' => $userName,
            ];
            
            // Obter usuário atual para excluir das notificações
            $excludeUserId = plugin_glpipwa_get_current_user_id();
            
            PluginGlpipwaNotificationService::notify($ticketId, 'actor_updated', $payload, $excludeUserId);
        }
    } catch (Exception $e) {
        if (class_exists('Toolbox')) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro em plugin_glpipwa_ticket_user_update - " . $e->getMessage(), LOG_ERR);
        }
    } catch (Throwable $e) {
        if (class_exists('Toolbox')) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro fatal em plugin_glpipwa_ticket_user_update - " . $e->getMessage(), LOG_ERR);
        }
    }
}

/**
 * Hook chamado quando uma TicketValidation é adicionada
 */
function plugin_glpipwa_validation_add($item) {
    try {
        if (!class_exists('TicketValidation')) {
            return;
        }
        
        if ($item instanceof TicketValidation) {
            $ticketId = $item->getField('tickets_id');
            if ($ticketId <= 0) {
                return;
            }
            
            plugin_glpipwa_load_hook_classes();
            
            if (!class_exists('PluginGlpipwaNotificationService')) {
                return;
            }
            
            // Obter usuário atual para excluir das notificações
            $excludeUserId = plugin_glpipwa_get_current_user_id();
            
            PluginGlpipwaNotificationService::notify($ticketId, 'validation_added', [], $excludeUserId);
        }
    } catch (Exception $e) {
        if (class_exists('Toolbox')) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro em plugin_glpipwa_validation_add - " . $e->getMessage(), LOG_ERR);
        }
    } catch (Throwable $e) {
        if (class_exists('Toolbox')) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro fatal em plugin_glpipwa_validation_add - " . $e->getMessage(), LOG_ERR);
        }
    }
}

/**
 * Hook chamado quando uma TicketValidation é atualizada
 */
function plugin_glpipwa_validation_update($item) {
    try {
        if (!class_exists('TicketValidation')) {
            return;
        }
        
        if ($item instanceof TicketValidation) {
            $ticketId = $item->getField('tickets_id');
            if ($ticketId <= 0) {
                return;
            }
            
            plugin_glpipwa_load_hook_classes();
            
            if (!class_exists('PluginGlpipwaNotificationService')) {
                return;
            }
            
            // Obter informações da validação
            $validatorId = $item->getField('users_id');
            $status = $item->getField('status');
            
            $validatorName = __('User', 'glpipwa');
            if (class_exists('User') && plugin_glpipwa_is_valid_user_id($validatorId)) {
                try {
                    $user = new User();
                    if ($user->getFromDB($validatorId)) {
                        $validatorName = $user->getName();
                    }
                } catch (Exception $e) {
                    // Usar nome padrão
                }
            }
            
            // Mapear status
            $statusName = 'updated';
            if (class_exists('TicketValidation')) {
                if (defined('TicketValidation::ACCEPTED') && $status == TicketValidation::ACCEPTED) {
                    $statusName = 'accepted';
                } elseif (defined('TicketValidation::REFUSED') && $status == TicketValidation::REFUSED) {
                    $statusName = 'refused';
                }
            }
            
            $payload = [
                'validator_name' => $validatorName,
                'status' => $statusName,
            ];
            
            // Obter usuário atual para excluir das notificações
            $excludeUserId = plugin_glpipwa_get_current_user_id();
            
            PluginGlpipwaNotificationService::notify($ticketId, 'validation_updated', $payload, $excludeUserId);
        }
    } catch (Exception $e) {
        if (class_exists('Toolbox')) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro em plugin_glpipwa_validation_update - " . $e->getMessage(), LOG_ERR);
        }
    } catch (Throwable $e) {
        if (class_exists('Toolbox')) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro fatal em plugin_glpipwa_validation_update - " . $e->getMessage(), LOG_ERR);
        }
    }
}

/**
 * Hook chamado quando uma TicketTask é adicionada
 */
function plugin_glpipwa_task_add($item) {
    try {
        if (!class_exists('TicketTask')) {
            return;
        }
        
        if ($item instanceof TicketTask) {
            $ticketId = $item->getField('tickets_id');
            if ($ticketId <= 0) {
                // Tentar obter via items_id se tickets_id não estiver disponível
                $itemtype = $item->getField('itemtype');
                if ($itemtype === 'Ticket') {
                    $ticketId = $item->getField('items_id');
                }
            }
            
            if ($ticketId <= 0) {
                return;
            }
            
            plugin_glpipwa_load_hook_classes();
            
            if (!class_exists('PluginGlpipwaNotificationService')) {
                return;
            }
            
            // Obter informações da tarefa
            $taskName = $item->getField('content');
            if (empty($taskName)) {
                $taskName = __('Task', 'glpipwa');
            } else {
                // Sanitizar conteúdo HTML antes de limitar tamanho
                $taskName = plugin_glpipwa_sanitize_content($taskName);
                // Limitar tamanho do nome
                $taskName = strlen($taskName) > 100 ? substr($taskName, 0, 100) . '...' : $taskName;
            }
            
            $creatorId = $item->getField('users_id');
            $creatorName = __('User', 'glpipwa');
            if (class_exists('User') && plugin_glpipwa_is_valid_user_id($creatorId)) {
                try {
                    $user = new User();
                    if ($user->getFromDB($creatorId)) {
                        $creatorName = $user->getName();
                    }
                } catch (Exception $e) {
                    // Usar nome padrão
                }
            }
            
            $payload = [
                'task_name' => $taskName,
                'creator_name' => $creatorName,
            ];
            
            // Obter usuário atual para excluir das notificações
            $excludeUserId = plugin_glpipwa_get_current_user_id();
            
            PluginGlpipwaNotificationService::notify($ticketId, 'task_added', $payload, $excludeUserId);
        }
    } catch (Exception $e) {
        if (class_exists('Toolbox')) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro em plugin_glpipwa_task_add - " . $e->getMessage(), LOG_ERR);
        }
    } catch (Throwable $e) {
        if (class_exists('Toolbox')) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro fatal em plugin_glpipwa_task_add - " . $e->getMessage(), LOG_ERR);
        }
    }
}

/**
 * Instalação do plugin
 *
 * @return boolean
 */
function plugin_glpipwa_install() {
    global $DB;

    try {
        // Verificar se as classes necessárias existem
        if (!class_exists('Migration')) {
            return false;
        }
        
        $migration = new Migration(PLUGIN_GLPIPWA_VERSION);
        
        // Incluir classes necessárias
        require_once(__DIR__ . '/inc/Config.php');
        require_once(__DIR__ . '/inc/Cron.php');
        
        if (!class_exists('PluginGlpipwaConfig')) {
            return false;
        }

        // Criar tabela de tokens FCM usando Migration
        $table = 'glpi_plugin_glpipwa_tokens';
        
        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Criando tabela $table...");
            
            // Criar tabela usando SQL direto mas com tipos corretos para GLPI 11
            $query = "CREATE TABLE `$table` (
            `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
            `users_id` int UNSIGNED NOT NULL,
                `token` varchar(255) NOT NULL,
                `user_agent` varchar(255) DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `token` (`token`),
                KEY `users_id` (`users_id`),
                KEY `date_mod` (`date_mod`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
            
            $DB->doQuery($query);
            $migration->migrationOneTable($table);
        }

        // Criar tabela de dispositivos PWA
        $devices_table = 'glpi_plugin_glpipwa_devices';
        
        if (!$DB->tableExists($devices_table)) {
            $migration->displayMessage("Criando tabela $devices_table...");
            
            $query = "CREATE TABLE `$devices_table` (
                `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
                `users_id` int UNSIGNED NOT NULL,
                `device_id` varchar(36) NOT NULL,
                `fcm_token` TEXT NOT NULL,
                `user_agent` varchar(255) DEFAULT NULL,
                `platform` varchar(50) DEFAULT NULL,
                `last_seen_at` timestamp NULL DEFAULT NULL,
                `last_seen_ticket_id` int UNSIGNED DEFAULT NULL,
                `last_seen_ticket_updated_at` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `users_device` (`users_id`, `device_id`),
                UNIQUE KEY `fcm_token` (`fcm_token`(255)),
                KEY `users_id` (`users_id`),
                KEY `last_seen_at` (`last_seen_at`),
                KEY `last_seen_ticket_id` (`last_seen_ticket_id`),
                KEY `last_seen_ticket_updated_at` (`last_seen_ticket_updated_at`),
                KEY `date_mod` (`date_mod`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
            
            $DB->doQuery($query);
            $migration->migrationOneTable($devices_table);

            // Migrar dados existentes de glpi_plugin_glpipwa_tokens para glpi_plugin_glpipwa_devices
            if ($DB->tableExists($table)) {
                $migration->displayMessage("Migrando dados de $table para $devices_table...");
                
                require_once(__DIR__ . '/inc/Device.php');
                
                $iterator = $DB->request([
                    'FROM' => $table,
                ]);

                $migrated = 0;
                foreach ($iterator as $row) {
                    // Gerar device_id UUID v4 para cada token existente
                    $data_bytes = random_bytes(16);
                    $data_bytes[6] = chr(ord($data_bytes[6]) & 0x0f | 0x40); // versão 4
                    $data_bytes[8] = chr(ord($data_bytes[8]) & 0x3f | 0x80); // variante 10
                    $hex = bin2hex($data_bytes);
                    $device_id = sprintf(
                        '%08s-%04s-%04s-%04s-%012s',
                        substr($hex, 0, 8),
                        substr($hex, 8, 4),
                        substr($hex, 12, 4),
                        substr($hex, 16, 4),
                        substr($hex, 20, 12)
                    );

                    // Migrar dados
                    $result = PluginGlpipwaDevice::addDevice(
                        $row['users_id'],
                        $device_id,
                        $row['token'],
                        $row['user_agent'],
                        'web' // platform padrão
                    );

                    if ($result) {
                        // Atualizar last_seen_at com date_mod do token (melhor estimativa disponível)
                        PluginGlpipwaDevice::updateLastSeen(
                            $row['users_id'],
                            $device_id,
                            null, // ticket_id
                            null  // ticket_updated_at
                        );
                        $migrated++;
                    }
                }

                $migration->displayMessage("Migração concluída: {$migrated} dispositivo(s) migrado(s)");
            }
        }

        // Configurações padrão (apenas para chaves que não existem)
        $existing = PluginGlpipwaConfig::getAll();
        $defaults = [
            'firebase_api_key' => '',
            'firebase_project_id' => '',
            'firebase_messaging_sender_id' => '',
            'firebase_app_id' => '',
            'firebase_vapid_key' => '',
            'firebase_service_account_json' => '',
            'pwa_name' => 'GLPI Service Desk',
            'pwa_short_name' => 'GLPI',
            'pwa_theme_color' => '#0d6efd',
            'pwa_background_color' => '#ffffff',
            'pwa_start_url' => '/',
            'pwa_display' => 'standalone',
            'pwa_orientation' => 'any',
        ];

        // Filtrar apenas as configurações que não existem
        $newDefaults = [];
        foreach ($defaults as $key => $value) {
            if (!isset($existing[$key])) {
                $newDefaults[$key] = $value;
            }
        }

        // Definir apenas as novas configurações
        if (!empty($newDefaults)) {
            PluginGlpipwaConfig::setMultiple($newDefaults);
        }

        // Registrar tarefa cron para limpeza de tokens
        if (class_exists('PluginGlpipwaCron')) {
            PluginGlpipwaCron::install();
        }

        return true;
    } catch (Exception $e) {
        if (class_exists('Toolbox')) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro na instalação - " . $e->getMessage(), LOG_ERR);
        }
        return false;
    }
}

/**
 * Desinstalação do plugin
 *
 * @return boolean
 */
function plugin_glpipwa_uninstall() {
    global $DB;

    try {
        // Verificar se as classes necessárias existem
        if (!class_exists('Migration')) {
            return false;
        }
        
        $migration = new Migration(PLUGIN_GLPIPWA_VERSION);
        
        // Incluir classes necessárias
        require_once(__DIR__ . '/inc/Config.php');
        require_once(__DIR__ . '/inc/Cron.php');
        
        if (!class_exists('Config')) {
            return false;
        }
        
        // Remover tarefa cron
        if (class_exists('PluginGlpipwaCron')) {
            PluginGlpipwaCron::uninstall();
        }

        // Remover tabela de tokens usando Migration
        $table = 'glpi_plugin_glpipwa_tokens';
        if ($DB->tableExists($table)) {
            $migration->displayMessage("Removendo tabela $table...");
            $migration->dropTable($table);
        }

        // Remover configurações
        $config = new Config();
        $config->deleteByCriteria(['context' => 'plugin:glpipwa'], true);

        return true;
    } catch (Exception $e) {
        if (class_exists('Toolbox')) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro na desinstalação - " . $e->getMessage(), LOG_ERR);
        }
        return false;
    }
}

