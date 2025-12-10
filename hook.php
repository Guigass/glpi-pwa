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
 * Carrega as classes necessárias do plugin
 */
function plugin_glpipwa_load_hook_classes() {
    static $loaded = false;
    if (!$loaded) {
        require_once(__DIR__ . '/inc/NotificationPush.php');
        $loaded = true;
    }
}

/**
 * Hook chamado quando um item é adicionado
 */
function plugin_glpipwa_item_add($item) {
    try {
        // Verificar se a classe Ticket existe antes de usar instanceof
        if (!class_exists('Ticket')) {
            return;
        }
        
        if ($item instanceof Ticket) {
            plugin_glpipwa_load_hook_classes();
            
            if (class_exists('PluginGlpipwaNotificationPush')) {
                $notification = new PluginGlpipwaNotificationPush();
                $notification->notifyNewTicket($item);
            }
        }
    } catch (Exception $e) {
        // Silenciosamente ignora erros para não quebrar o fluxo do GLPI
        if (class_exists('Toolbox')) {
            Toolbox::logError("GLPI PWA: Erro em plugin_glpipwa_item_add - " . $e->getMessage());
        }
    }
}

/**
 * Hook chamado quando um item é atualizado
 */
function plugin_glpipwa_item_update($item) {
    try {
        // Verificar se a classe Ticket existe antes de usar instanceof
        if (!class_exists('Ticket')) {
            return;
        }
        
        if ($item instanceof Ticket) {
            plugin_glpipwa_load_hook_classes();
            
            if (class_exists('PluginGlpipwaNotificationPush')) {
                $notification = new PluginGlpipwaNotificationPush();
                $notification->notifyTicketUpdate($item);
            }
        }
    } catch (Exception $e) {
        // Silenciosamente ignora erros para não quebrar o fluxo do GLPI
        if (class_exists('Toolbox')) {
            Toolbox::logError("GLPI PWA: Erro em plugin_glpipwa_item_update - " . $e->getMessage());
        }
    }
}

/**
 * Hook chamado quando um follow-up é adicionado
 */
function plugin_glpipwa_followup_add($item) {
    try {
        // Verificar se a classe ITILFollowup existe antes de usar instanceof
        if (!class_exists('ITILFollowup')) {
            return;
        }
        
        if ($item instanceof ITILFollowup) {
            plugin_glpipwa_load_hook_classes();
            
            if (class_exists('PluginGlpipwaNotificationPush')) {
                $notification = new PluginGlpipwaNotificationPush();
                $notification->notifyNewFollowup($item);
            }
        }
    } catch (Exception $e) {
        // Silenciosamente ignora erros para não quebrar o fluxo do GLPI
        if (class_exists('Toolbox')) {
            Toolbox::logError("GLPI PWA: Erro em plugin_glpipwa_followup_add - " . $e->getMessage());
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
        
        // Se a tabela já existe, dropar para recriar com a estrutura correta
        if ($DB->tableExists($table)) {
            $migration->displayMessage("Removendo tabela $table existente...");
            $migration->dropTable($table);
        }
        
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

        // Configurações padrão
        $defaults = [
        'firebase_api_key' => '',
        'firebase_project_id' => '',
        'firebase_messaging_sender_id' => '',
        'firebase_app_id' => '',
        'firebase_vapid_key' => '',
        'firebase_server_key' => '',
        'pwa_name' => 'GLPI Service Desk',
        'pwa_short_name' => 'GLPI',
        'pwa_theme_color' => '#0d6efd',
        'pwa_background_color' => '#ffffff',
        'pwa_start_url' => '/',
        'pwa_display' => 'standalone',
            'pwa_orientation' => 'any',
        ];

        PluginGlpipwaConfig::setMultiple($defaults);

        // Registrar tarefa cron para limpeza de tokens
        if (class_exists('PluginGlpipwaCron')) {
            PluginGlpipwaCron::install();
        }

        return true;
    } catch (Exception $e) {
        if (class_exists('Toolbox')) {
            Toolbox::logError("GLPI PWA: Erro na instalação - " . $e->getMessage());
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
            Toolbox::logError("GLPI PWA: Erro na desinstalação - " . $e->getMessage());
        }
        return false;
    }
}

