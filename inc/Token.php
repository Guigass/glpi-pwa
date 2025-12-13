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
 * Classe para gerenciamento de tokens FCM
 */
class PluginGlpipwaToken extends CommonDBTM
{
    public static $rightname = 'config';

    /**
     * Retorna o nome da tabela
     */
    static function getTable($classname = null)
    {
        return 'glpi_plugin_glpipwa_tokens';
    }

    /**
     * Define campos para exibição
     */
    function getRights($interface = 'central')
    {
        return [READ => __('Read'), UPDATE => __('Update'), DELETE => __('Delete')];
    }

    /**
     * Adiciona um novo token para um usuário
     */
    public static function addToken($users_id, $token, $user_agent = null)
    {
        global $DB;

        try {
            // Validar parâmetros
            if (empty($users_id) || !is_numeric($users_id) || (int)$users_id <= 0) {
                Toolbox::logInFile('glpipwa', "GLPI PWA: addToken - users_id inválido: " . var_export($users_id, true), LOG_ERR);
                return false;
            }

            if (empty($token) || !is_string($token)) {
                Toolbox::logInFile('glpipwa', "GLPI PWA: addToken - token inválido", LOG_ERR);
                return false;
            }

            $users_id = (int)$users_id;

            // Verificar se o token já existe
            $existing = new self();
            if ($existing->getFromDBByCrit(['token' => $token])) {
                // Atualizar data de modificação e usuário se necessário
                $current_time = isset($_SESSION['glpi_currenttime']) ? $_SESSION['glpi_currenttime'] : date('Y-m-d H:i:s');
                $input = [
                    'id' => $existing->getID(),
                    'date_mod' => $current_time,
                ];
                if ($user_agent !== null) {
                    $input['user_agent'] = $user_agent;
                }
                if ($existing->getField('users_id') != $users_id) {
                    $input['users_id'] = $users_id;
                    Toolbox::logInFile('glpipwa', "GLPI PWA: addToken - Atualizando token existente (ID: {$existing->getID()}) para usuário {$users_id}", LOG_DEBUG);
                } else {
                    Toolbox::logInFile('glpipwa', "GLPI PWA: addToken - Token já existe e pertence ao mesmo usuário (ID: {$existing->getID()}, users_id: {$users_id})", LOG_DEBUG);
                }
                
                $result = $existing->update($input);
                if ($result) {
                    Toolbox::logInFile('glpipwa', "GLPI PWA: addToken - Token atualizado com sucesso (ID: {$existing->getID()})", LOG_DEBUG);
                    return $existing->getID();
                } else {
                    Toolbox::logInFile('glpipwa', "GLPI PWA: addToken - Falha ao atualizar token existente (ID: {$existing->getID()})", LOG_ERR);
                    return false;
                }
            }

            // Criar novo token
            $tokenObj = new self();
            $current_time = isset($_SESSION['glpi_currenttime']) ? $_SESSION['glpi_currenttime'] : date('Y-m-d H:i:s');
            $input = [
                'users_id' => $users_id,
                'token' => $token,
                'user_agent' => $user_agent,
                'date_creation' => $current_time,
                'date_mod' => $current_time,
            ];

            $result = $tokenObj->add($input);
            if ($result) {
                Toolbox::logInFile('glpipwa', "GLPI PWA: addToken - Novo token criado com sucesso (ID: {$result}, users_id: {$users_id})", LOG_DEBUG);
                return $result;
            } else {
                Toolbox::logInFile('glpipwa', "GLPI PWA: addToken - Falha ao criar novo token (users_id: {$users_id})", LOG_ERR);
                return false;
            }
        } catch (Exception $e) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: addToken - Exceção: " . $e->getMessage(), LOG_ERR);
            return false;
        } catch (Throwable $e) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: addToken - Erro fatal: " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }

    /**
     * Remove um token
     */
    public static function deleteToken($token)
    {
        $tokenObj = new self();
        if ($tokenObj->getFromDBByCrit(['token' => $token])) {
            return $tokenObj->delete(['id' => $tokenObj->getID()]);
        }
        return false;
    }

    /**
     * Obtém todos os tokens de um usuário
     */
    public static function getUserTokens($users_id)
    {
        global $DB;

        $tokens = [];
        $iterator = $DB->request([
            'FROM' => self::getTable(),
            'WHERE' => ['users_id' => $users_id],
        ]);

        foreach ($iterator as $row) {
            $tokens[] = $row['token'];
        }

        return $tokens;
    }

    /**
     * Obtém todos os tokens de múltiplos usuários
     */
    public static function getUsersTokens(array $users_ids)
    {
        global $DB;

        // Filtrar e validar IDs de usuários antes de executar a query
        $valid_users_ids = [];
        foreach ($users_ids as $user_id) {
            // Verificar se é numérico e maior que zero
            // Rejeitar strings como 'N/A', null, false, arrays, etc.
            if (is_numeric($user_id)) {
                $user_id = (int)$user_id;
                if ($user_id > 0) {
                    $valid_users_ids[] = $user_id;
                }
            }
        }
        
        // Remover duplicatas
        $valid_users_ids = array_unique($valid_users_ids);
        
        // Se não houver IDs válidos, retornar array vazio
        if (empty($valid_users_ids)) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Nenhum ID de usuário válido fornecido para getUsersTokens. IDs originais: " . implode(', ', $users_ids), LOG_WARNING);
            return [];
        }

        $tokens = [];
        $iterator = $DB->request([
            'FROM' => self::getTable(),
            'WHERE' => ['users_id' => $valid_users_ids],
        ]);

        foreach ($iterator as $row) {
            if (!isset($tokens[$row['users_id']])) {
                $tokens[$row['users_id']] = [];
            }
            $tokens[$row['users_id']][] = $row['token'];
        }

        return $tokens;
    }

    /**
     * Remove tokens expirados ou inválidos
     */
    public static function cleanExpired($days = 90)
    {
        global $DB;

        $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $iterator = $DB->request([
            'FROM' => self::getTable(),
            'WHERE' => [
                'date_mod' => ['<', $date],
            ],
        ]);

        $deleted = 0;
        foreach ($iterator as $row) {
            $token = new self();
            if ($token->delete(['id' => $row['id']])) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Remove tokens de um usuário
     */
    public static function deleteUserTokens($users_id)
    {
        global $DB;

        $iterator = $DB->request([
            'FROM' => self::getTable(),
            'WHERE' => ['users_id' => $users_id],
        ]);

        $deleted = 0;
        foreach ($iterator as $row) {
            $token = new self();
            if ($token->delete(['id' => $row['id']])) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Obtém todos os tokens com informações completas
     */
    public static function getAllTokens()
    {
        global $DB;

        $tokens = [];
        $iterator = $DB->request([
            'FROM' => self::getTable(),
            'ORDER' => 'date_creation DESC',
        ]);

        foreach ($iterator as $row) {
            $tokens[] = [
                'id' => $row['id'],
                'users_id' => $row['users_id'],
                'token' => $row['token'],
                'user_agent' => $row['user_agent'],
                'date_creation' => $row['date_creation'],
                'date_mod' => $row['date_mod'],
            ];
        }

        return $tokens;
    }

    /**
     * Remove um token por ID
     */
    public static function deleteTokenById($id)
    {
        global $DB;
        
        try {
            $id = (int)$id;
            if ($id <= 0) {
                return false;
            }
            
            // Tentar usar o método delete do CommonDBTM primeiro
            $tokenObj = new self();
            if ($tokenObj->getFromDB($id)) {
                $result = $tokenObj->delete(['id' => $id]);
                if ($result) {
                    return true;
                }
            }
            
            // Se falhar, usar SQL direto como fallback
            $table = self::getTable();
            $result = $DB->delete($table, ['id' => $id]);
            
            return $result !== false;
        } catch (Exception $e) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro ao remover token ID $id - " . $e->getMessage(), LOG_ERR);
            return false;
        } catch (Throwable $e) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro fatal ao remover token ID $id - " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }

    /**
     * Remove todos os tokens
     */
    public static function deleteAllTokens()
    {
        global $DB;

        try {
            $table = self::getTable();
            
            // Contar tokens antes de deletar
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
                $token = new self();
                if ($token->getFromDB($row['id'])) {
                    if ($token->delete(['id' => $row['id']])) {
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
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro ao remover todos os tokens - " . $e->getMessage(), LOG_ERR);
            return 0;
        } catch (Throwable $e) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro fatal ao remover todos os tokens - " . $e->getMessage(), LOG_ERR);
            return 0;
        }
    }
}

