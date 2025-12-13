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
 * Classe para gerenciamento de dispositivos PWA
 */
class PluginGlpipwaDevice extends CommonDBTM
{
    public static $rightname = 'config';

    /**
     * Retorna o nome da tabela
     * 
     * @param string|null $classname Nome da classe (não utilizado, mantido para compatibilidade)
     * @return string Nome da tabela
     */
    static function getTable($classname = null)
    {
        return 'glpi_plugin_glpipwa_devices';
    }

    /**
     * Define campos para exibição
     * 
     * @param string $interface Interface ('central' ou 'helpdesk')
     * @return array Direitos disponíveis
     */
    function getRights($interface = 'central')
    {
        return [READ => __('Read'), UPDATE => __('Update'), DELETE => __('Delete')];
    }

    /**
     * Adiciona ou atualiza um dispositivo
     */
    public static function addDevice($users_id, $device_id, $fcm_token, $user_agent = null, $platform = 'web')
    {
        global $DB;

        try {
            // Validar parâmetros
            if (empty($users_id) || !is_numeric($users_id) || (int)$users_id <= 0) {
                Toolbox::logInFile('glpipwa', "GLPI PWA: addDevice - users_id inválido: " . var_export($users_id, true), LOG_ERR);
                return false;
            }

            if (empty($device_id) || !is_string($device_id)) {
                Toolbox::logInFile('glpipwa', "GLPI PWA: addDevice - device_id inválido", LOG_ERR);
                return false;
            }

            if (empty($fcm_token) || !is_string($fcm_token)) {
                Toolbox::logInFile('glpipwa', "GLPI PWA: addDevice - fcm_token inválido", LOG_ERR);
                return false;
            }

            $users_id = (int)$users_id;

            // Verificar se o dispositivo já existe (users_id + device_id)
            $existing = new self();
            if ($existing->getFromDBByCrit(['users_id' => $users_id, 'device_id' => $device_id])) {
                // Atualizar fcm_token, user_agent, platform e last_seen_at
                // IMPORTANTE: Resetar last_seen_ticket_id e last_seen_ticket_updated_at para NULL
                // quando o token é atualizado, para evitar estado antigo de visualização
                $current_time = isset($_SESSION['glpi_currenttime']) ? $_SESSION['glpi_currenttime'] : date('Y-m-d H:i:s');
                $input = [
                    'id' => $existing->getID(),
                    'fcm_token' => $fcm_token,
                    'last_seen_at' => $current_time,
                    'last_seen_ticket_id' => null, // Resetar estado de ticket visualizado
                    'last_seen_ticket_updated_at' => null, // Resetar timestamp de ticket visualizado
                    'date_mod' => $current_time,
                ];
                if ($user_agent !== null) {
                    $input['user_agent'] = $user_agent;
                }
                if ($platform !== null) {
                    $input['platform'] = $platform;
                }
                
                $result = $existing->update($input);
                if ($result) {
                    return $existing->getID();
                } else {
                    Toolbox::logInFile('glpipwa', "GLPI PWA: addDevice - Falha ao atualizar dispositivo existente (ID: {$existing->getID()})", LOG_ERR);
                    return false;
                }
            }

            // Verificar se o fcm_token já existe (mesmo token não pode estar em múltiplos dispositivos)
            $existing_token = new self();
            if ($existing_token->getFromDBByCrit(['fcm_token' => $fcm_token])) {
                // Atualizar o dispositivo existente com o novo users_id/device_id se necessário
                // IMPORTANTE: Resetar last_seen_ticket_id e last_seen_ticket_updated_at para NULL
                // quando o token é atualizado, para evitar estado antigo de visualização
                $current_time = isset($_SESSION['glpi_currenttime']) ? $_SESSION['glpi_currenttime'] : date('Y-m-d H:i:s');
                $input = [
                    'id' => $existing_token->getID(),
                    'users_id' => $users_id,
                    'device_id' => $device_id,
                    'last_seen_at' => $current_time,
                    'last_seen_ticket_id' => null, // Resetar estado de ticket visualizado
                    'last_seen_ticket_updated_at' => null, // Resetar timestamp de ticket visualizado
                    'date_mod' => $current_time,
                ];
                if ($user_agent !== null) {
                    $input['user_agent'] = $user_agent;
                }
                if ($platform !== null) {
                    $input['platform'] = $platform;
                }
                
                $result = $existing_token->update($input);
                if ($result) {
                    return $existing_token->getID();
                }
            }

            // Criar novo dispositivo
            $deviceObj = new self();
            $current_time = isset($_SESSION['glpi_currenttime']) ? $_SESSION['glpi_currenttime'] : date('Y-m-d H:i:s');
            $input = [
                'users_id' => $users_id,
                'device_id' => $device_id,
                'fcm_token' => $fcm_token,
                'user_agent' => $user_agent,
                'platform' => $platform,
                'last_seen_at' => $current_time,
                'date_creation' => $current_time,
                'date_mod' => $current_time,
            ];

            $result = $deviceObj->add($input);
            if ($result) {
                return $result;
            } else {
                Toolbox::logInFile('glpipwa', "GLPI PWA: addDevice - Falha ao criar novo dispositivo (users_id: {$users_id}, device_id: {$device_id})", LOG_ERR);
                return false;
            }
        } catch (Exception $e) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: addDevice - Exceção: " . $e->getMessage(), LOG_ERR);
            return false;
        } catch (Throwable $e) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: addDevice - Erro fatal: " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }

    /**
     * Atualiza last_seen_at e opcionalmente last_seen_ticket_id e last_seen_ticket_updated_at
     * 
     * @return bool|string Retorna true em caso de sucesso, false em caso de erro, ou 'NOT_FOUND' se o dispositivo não foi encontrado
     */
    public static function updateLastSeen($users_id, $device_id, $ticket_id = null, $ticket_updated_at = null)
    {
        global $DB;

        try {
            $users_id = (int)$users_id;
            if ($users_id <= 0 || empty($device_id)) {
                return false;
            }

            // Limpar device_id (remover espaços, etc)
            $device_id = trim($device_id);

            $device = new self();
            if (!$device->getFromDBByCrit(['users_id' => $users_id, 'device_id' => $device_id])) {
                // Log mais detalhado para debug
                Toolbox::logInFile('glpipwa', "GLPI PWA: updateLastSeen - Dispositivo não encontrado (users_id: {$users_id}, device_id: [{$device_id}], length: " . strlen($device_id) . ")", LOG_WARNING);
                
                
                return 'NOT_FOUND';
            }

            $current_time = isset($_SESSION['glpi_currenttime']) ? $_SESSION['glpi_currenttime'] : date('Y-m-d H:i:s');
            $input = [
                'id' => $device->getID(),
                'last_seen_at' => $current_time,
                'date_mod' => $current_time,
            ];

            // Se ticket_id fornecido, atualizar também last_seen_ticket_id e last_seen_ticket_updated_at
            // IMPORTANTE: last_seen_ticket_updated_at deve receber o valor REAL de ticket.date_mod,
            // NUNCA usar NOW() ou timestamp atual, pois isso quebraria a lógica de comparação
            if ($ticket_id !== null) {
                $ticket_id = (int)$ticket_id;
                if ($ticket_id > 0) {
                    $input['last_seen_ticket_id'] = $ticket_id;
                    if ($ticket_updated_at !== null) {
                        // Usar valor fornecido (já validado no front-end)
                        $input['last_seen_ticket_updated_at'] = $ticket_updated_at;
                    } else {
                        // Buscar ticket.date_mod do banco se não fornecido
                        // Isso garante que usamos o valor real, não uma estimativa
                        $ticket = new Ticket();
                        if ($ticket->getFromDB($ticket_id)) {
                            $input['last_seen_ticket_updated_at'] = $ticket->getField('date_mod');
                        }
                    }
                }
            }

            $result = $device->update($input);
            if ($result) {
                return true;
            } else {
                Toolbox::logInFile('glpipwa', "GLPI PWA: updateLastSeen - Falha ao atualizar (ID: {$device->getID()})", LOG_ERR);
                return false;
            }
        } catch (Exception $e) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: updateLastSeen - Exceção: " . $e->getMessage(), LOG_ERR);
            return false;
        } catch (Throwable $e) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: updateLastSeen - Erro fatal: " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }

    /**
     * Obtém todos os dispositivos de um usuário
     */
    public static function getUserDevices($users_id)
    {
        global $DB;

        $devices = [];
        $iterator = $DB->request([
            'FROM' => self::getTable(),
            'WHERE' => ['users_id' => $users_id],
        ]);

        foreach ($iterator as $row) {
            $devices[] = [
                'id' => $row['id'],
                'device_id' => $row['device_id'],
                'fcm_token' => $row['fcm_token'],
                'user_agent' => $row['user_agent'],
                'platform' => $row['platform'],
                'last_seen_at' => $row['last_seen_at'],
                'last_seen_ticket_id' => $row['last_seen_ticket_id'],
                'last_seen_ticket_updated_at' => $row['last_seen_ticket_updated_at'],
                'date_creation' => $row['date_creation'],
                'date_mod' => $row['date_mod'],
            ];
        }

        return $devices;
    }

    /**
     * Obtém um dispositivo por device_id e users_id
     */
    public static function getDeviceByDeviceId($users_id, $device_id)
    {
        $device = new self();
        if ($device->getFromDBByCrit(['users_id' => $users_id, 'device_id' => $device_id])) {
            return [
                'id' => $device->getID(),
                'users_id' => $device->getField('users_id'),
                'device_id' => $device->getField('device_id'),
                'fcm_token' => $device->getField('fcm_token'),
                'user_agent' => $device->getField('user_agent'),
                'platform' => $device->getField('platform'),
                'last_seen_at' => $device->getField('last_seen_at'),
                'last_seen_ticket_id' => $device->getField('last_seen_ticket_id'),
                'last_seen_ticket_updated_at' => $device->getField('last_seen_ticket_updated_at'),
                'date_creation' => $device->getField('date_creation'),
                'date_mod' => $device->getField('date_mod'),
            ];
        }
        return null;
    }

    /**
     * Obtém dispositivos de múltiplos usuários que devem receber notificação
     * 
     * ============================================================================
     * REGRA DE DECISÃO - FLUXO FINAL
     * ============================================================================
     * 
     * CONCEITOS:
     * - last_seen_at: Timestamp de quando o usuário acessou QUALQUER página do GLPI
     *   * NÃO é usado para decidir se um ticket foi "visto"
     *   * Usado apenas para telemetria e rate-limit (evitar flood)
     * 
     * - last_seen_ticket_id: ID do último ticket que o usuário visualizou especificamente
     *   * Atualizado apenas quando usuário entra na página do ticket
     *   * NULL se usuário nunca visualizou aquele ticket
     * 
     * - last_seen_ticket_updated_at: Timestamp de quando o ticket estava na última visualização
     *   * Recebe o valor REAL de ticket.date_mod naquele momento
     *   * NUNCA usa NOW() ou timestamp atual
     * 
     * QUANDO O PUSH SEMPRE É ENVIADO:
     * 1. Ticket foi atualizado E usuário nunca visualizou aquele ticket específico
     *    (last_seen_ticket_id != ticket_id OU last_seen_ticket_id é NULL)
     * 
     * 2. Ticket foi atualizado E ticket_date_mod > last_seen_ticket_updated_at
     *    (houve atualização DEPOIS que o usuário visualizou)
     * 
     * QUANDO O PUSH NUNCA É ENVIADO:
     * 1. last_seen_ticket_id == ticket_id E ticket_date_mod <= last_seen_ticket_updated_at
     *    (usuário já viu esta versão específica do ticket)
     * 
     * 2. Rate-limit ativo: last_seen_at foi atualizado há menos de 5 segundos
     *    (evita flood quando múltiplos eventos ocorrem rapidamente)
     * 
     * EXEMPLOS PRÁTICOS:
     * 
     * Exemplo 1: Usuário abriu GLPI, mas não o ticket #123
     *   - last_seen_at = 10:00
     *   - last_seen_ticket_id = NULL
     *   - ticket #123 é atualizado às 10:05
     *   - Resultado: ✅ ENVIAR notificação (usuário nunca viu o ticket)
     * 
     * Exemplo 2: Usuário abriu ticket #123, alguém comentou depois
     *   - last_seen_ticket_id = 123
     *   - last_seen_ticket_updated_at = 10:00 (quando visualizou)
     *   - ticket #123 é atualizado às 10:05 (novo comentário)
     *   - ticket_date_mod = 10:05
     *   - Resultado: ✅ ENVIAR notificação (10:05 > 10:00)
     * 
     * Exemplo 3: Usuário abriu ticket #123 e nada mudou
     *   - last_seen_ticket_id = 123
     *   - last_seen_ticket_updated_at = 10:00
     *   - ticket_date_mod = 10:00 (sem atualizações)
     *   - Resultado: ❌ NÃO ENVIAR (10:00 <= 10:00, usuário já viu)
     * 
     * Exemplo 4: Múltiplas atualizações rápidas
     *   - last_seen_at = 10:00:00 (usuário acessou GLPI)
     *   - Evento 1 às 10:00:01 → ✅ ENVIAR (1s depois, evento legítimo)
     *   - Evento 2 às 10:00:02 → ❌ BLOQUEAR (rate-limit, < 1 segundo após evento 1)
     *   - Evento 3 às 10:00:06 → ✅ ENVIAR (5s depois, rate-limit expirado)
     *   
     *   NOTA: O rate-limit compara com last_seen_at, não com eventos anteriores.
     *   Se múltiplos eventos ocorrem após last_seen_at, todos serão enviados
     *   (o FCM collapse/tag cuida da deduplicação no dispositivo).
     * 
     * TTL E COLLAPSE/TAG:
     * - TTL: 10 minutos padrão, 6 horas para validações
     * - Tag/Collapse: ticket-{ticket_id} para deduplicação no Service Worker
     * - Esses mecanismos não afetam a lógica de "envia/não envia", apenas a entrega
     * 
     * ============================================================================
     * 
     * @param array $users_ids IDs dos usuários
     * @param int|null $ticket_id ID do ticket (opcional)
     * @param string $ticket_date_mod Data de modificação do ticket (Y-m-d H:i:s)
     * @return array Lista de dispositivos que devem receber notificação
     */
    public static function getDevicesForNotification(array $users_ids, $ticket_id, $ticket_date_mod)
    {
        global $DB;

        // Filtrar e validar IDs de usuários
        $valid_users_ids = [];
        foreach ($users_ids as $user_id) {
            if (is_numeric($user_id)) {
                $user_id = (int)$user_id;
                if ($user_id > 0) {
                    $valid_users_ids[] = $user_id;
                }
            }
        }
        
        $valid_users_ids = array_unique($valid_users_ids);
        
        if (empty($valid_users_ids)) {
            return [];
        }

        $ticket_id = $ticket_id ? (int)$ticket_id : null;
        $devices = [];

        // Buscar dispositivos dos usuários
        $iterator = $DB->request([
            'FROM' => self::getTable(),
            'WHERE' => ['users_id' => $valid_users_ids],
        ]);

        foreach ($iterator as $row) {
            $device_id = $row['device_id'];
            $last_seen_at = $row['last_seen_at'];
            $last_seen_ticket_id = $row['last_seen_ticket_id'];
            $last_seen_ticket_updated_at = $row['last_seen_ticket_updated_at'];
            
            // Preparar dados para log
            $logData = [
                'ticket_id' => $ticket_id,
                'ticket_date_mod' => $ticket_date_mod,
                'device_id' => $device_id,
                'last_seen_at' => $last_seen_at,
                'last_seen_ticket_id' => $last_seen_ticket_id,
                'last_seen_ticket_updated_at' => $last_seen_ticket_updated_at,
            ];
            
            $skipReason = null;
            
            // RATE-LIMIT: Evitar flood quando múltiplos eventos ocorrem rapidamente
            // IMPORTANTE: O rate-limit só deve bloquear eventos SUBSEQUENTES, não o primeiro evento
            // Se o evento ocorreu DEPOIS de last_seen_at, é um evento legítimo e deve ser enviado
            // O rate-limit só bloqueia quando o evento ocorreu ANTES ou muito próximo (dentro de 1s) de last_seen_at,
            // indicando que o usuário está ativo e pode ter causado o evento
            // Isso NÃO decide se o ticket foi "visto", apenas evita spam de eventos simultâneos
            if ($last_seen_at !== null) {
                try {
                    $lastSeenTimestamp = strtotime($last_seen_at);
                    $ticketModTimestamp = strtotime($ticket_date_mod);
                    $timeDiff = $ticketModTimestamp - $lastSeenTimestamp;
                    
                    // Rate-limit só bloqueia se:
                    // 1. O evento ocorreu ANTES de last_seen_at (timeDiff < 0), OU
                    // 2. O evento ocorreu muito próximo (dentro de 1 segundo) de last_seen_at
                    //    Isso indica que o usuário está ativo e pode ter causado o evento
                    // Se timeDiff >= 1, o evento ocorreu DEPOIS e é legítimo (permitir)
                    if ($timeDiff < 1) {
                        $skipReason = 'SKIP_RATE_LIMIT';
                        $logData['skip_reason'] = $skipReason;
                        $logData['time_diff_seconds'] = $timeDiff;
                        Toolbox::logInFile('glpipwa', "GLPI PWA: getDevicesForNotification - Rate-limit ativo. " . json_encode($logData), LOG_DEBUG);
                        continue;
                    }
                } catch (Exception $e) {
                    // Se falhar ao converter datas, continuar com o fluxo normal
                    Toolbox::logInFile('glpipwa', "GLPI PWA: getDevicesForNotification - Erro ao calcular rate-limit: " . $e->getMessage(), LOG_WARNING);
                }
            }
            
            // REGRA ÚNICA DE SUPRESSÃO: Usuário já viu aquela versão específica do ticket
            // Só suprime se:
            // 1. Temos um ticket_id
            // 2. O dispositivo tem last_seen_ticket_id igual ao ticket_id atual
            // 3. O ticket não foi atualizado DEPOIS que o usuário visualizou
            if ($ticket_id 
                && $last_seen_ticket_id !== null 
                && (int)$last_seen_ticket_id === $ticket_id 
                && $last_seen_ticket_updated_at !== null) {
                
                try {
                    $ticketModTimestamp = strtotime($ticket_date_mod);
                    $lastSeenTicketTimestamp = strtotime($last_seen_ticket_updated_at);
                    
                    // Se ticket_date_mod <= last_seen_ticket_updated_at, usuário já viu esta versão
                    if ($ticketModTimestamp <= $lastSeenTicketTimestamp) {
                        $skipReason = 'SKIP_ALREADY_VIEWED';
                        $logData['skip_reason'] = $skipReason;
                        $logData['ticket_mod_timestamp'] = $ticketModTimestamp;
                        $logData['last_seen_ticket_timestamp'] = $lastSeenTicketTimestamp;
                        Toolbox::logInFile('glpipwa', "GLPI PWA: getDevicesForNotification - Ticket já visualizado. " . json_encode($logData), LOG_DEBUG);
                        continue;
                    }
                } catch (Exception $e) {
                    // Se falhar ao converter datas, continuar e enviar notificação (comportamento seguro)
                    Toolbox::logInFile('glpipwa', "GLPI PWA: getDevicesForNotification - Erro ao comparar timestamps: " . $e->getMessage() . ". Enviando notificação por segurança.", LOG_WARNING);
                }
            }

            // Dispositivo deve receber notificação
            $logData['skip_reason'] = 'SEND';
            Toolbox::logInFile('glpipwa', "GLPI PWA: getDevicesForNotification - Enviando notificação. " . json_encode($logData), LOG_DEBUG);
            
            $devices[] = [
                'id' => $row['id'],
                'users_id' => $row['users_id'],
                'device_id' => $row['device_id'],
                'fcm_token' => $row['fcm_token'],
                'user_agent' => $row['user_agent'],
                'platform' => $row['platform'],
            ];
        }

        return $devices;
    }

    /**
     * Obtém todos os devices com informações completas
     */
    public static function getAllDevices()
    {
        global $DB;

        $devices = [];
        $iterator = $DB->request([
            'FROM' => self::getTable(),
            'ORDER' => 'date_creation DESC',
        ]);

        foreach ($iterator as $row) {
            $devices[] = [
                'id' => $row['id'],
                'users_id' => $row['users_id'],
                'device_id' => $row['device_id'],
                'fcm_token' => $row['fcm_token'],
                'user_agent' => $row['user_agent'],
                'platform' => $row['platform'],
                'last_seen_at' => $row['last_seen_at'],
                'date_creation' => $row['date_creation'],
                'date_mod' => $row['date_mod'],
            ];
        }

        return $devices;
    }

    /**
     * Remove um device por ID
     */
    public static function deleteDeviceById($id)
    {
        global $DB;
        
        try {
            $id = (int)$id;
            if ($id <= 0) {
                return false;
            }
            
            // Tentar usar o método delete do CommonDBTM primeiro
            $deviceObj = new self();
            if ($deviceObj->getFromDB($id)) {
                $result = $deviceObj->delete(['id' => $id]);
                if ($result) {
                    return true;
                }
            }
            
            // Se falhar, usar SQL direto como fallback
            $table = self::getTable();
            $result = $DB->delete($table, ['id' => $id]);
            
            return $result !== false;
        } catch (Exception $e) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro ao remover device ID $id - " . $e->getMessage(), LOG_ERR);
            return false;
        } catch (Throwable $e) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro fatal ao remover device ID $id - " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }

    /**
     * Remove todos os devices
     */
    public static function deleteAllDevices()
    {
        global $DB;

        try {
            $table = self::getTable();
            
            // Contar devices antes de deletar
            $count_iterator = $DB->request([
                'COUNT' => 'id',
                'FROM' => $table,
            ]);
            $total_count = 0;
            foreach ($count_iterator as $row) {
                $total_count = (int)$row['COUNT'];
                break;
            }
            
            if ($total_count === 0) {
                return 0;
            }
            
            // Tentar usar SQL direto para remover todos de uma vez (mais eficiente)
            $result = $DB->delete($table, []);
            
            if ($result !== false) {
                return $total_count;
            }
            
            // Se falhar, tentar remover um por um usando CommonDBTM
            $deleted = 0;
            $iterator = $DB->request([
                'FROM' => $table,
            ]);

            foreach ($iterator as $row) {
                $device = new self();
                if ($device->getFromDB($row['id'])) {
                    if ($device->delete(['id' => $row['id']])) {
                        $deleted++;
                    } else {
                        // Fallback: SQL direto
                        $DB->delete($table, ['id' => $row['id']]);
                        $deleted++;
                    }
                }
            }

            return $deleted;
        } catch (Exception $e) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro ao remover todos os devices - " . $e->getMessage(), LOG_ERR);
            return 0;
        } catch (Throwable $e) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro fatal ao remover todos os devices - " . $e->getMessage(), LOG_ERR);
            return 0;
        }
    }
}
