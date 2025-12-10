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
            }
            $existing->update($input);
            return $existing->getID();
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

        return $tokenObj->add($input);
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
            Toolbox::logWarning("GLPI PWA: Nenhum ID de usuário válido fornecido para getUsersTokens. IDs originais: " . implode(', ', $users_ids));
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
}

