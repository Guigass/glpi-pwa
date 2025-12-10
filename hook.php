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

// Incluir classes necessárias
require_once(__DIR__ . '/inc/NotificationPush.php');

/**
 * Hook chamado quando um item é adicionado
 */
function plugin_glpipwa_item_add($item) {
    if ($item instanceof Ticket) {
        $notification = new PluginGlpipwaNotificationPush();
        $notification->notifyNewTicket($item);
    }
}

/**
 * Hook chamado quando um item é atualizado
 */
function plugin_glpipwa_item_update($item) {
    if ($item instanceof Ticket) {
        $notification = new PluginGlpipwaNotificationPush();
        $notification->notifyTicketUpdate($item);
    }
}

/**
 * Hook chamado quando um follow-up é adicionado
 */
function plugin_glpipwa_followup_add($item) {
    if ($item instanceof ITILFollowup) {
        $notification = new PluginGlpipwaNotificationPush();
        $notification->notifyNewFollowup($item);
    }
}

/**
 * Instalação do plugin
 *
 * @return boolean
 */
function plugin_glpipwa_install() {
    global $DB;

    $migration = new Migration(PLUGIN_GLPIPWA_VERSION);
    
    // Incluir classes necessárias
    require_once(__DIR__ . '/inc/Config.php');

    // Criar tabela de tokens FCM usando Migration
    if (!$DB->tableExists('glpi_plugin_glpipwa_tokens')) {
        $table = 'glpi_plugin_glpipwa_tokens';
        
        $migration->displayMessage("Criando tabela $table...");
        
        $query = "CREATE TABLE `$table` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `users_id` int(11) NOT NULL,
            `token` varchar(255) NOT NULL,
            `user_agent` varchar(255) DEFAULT NULL,
            `date_creation` datetime DEFAULT NULL,
            `date_mod` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `token` (`token`),
            KEY `users_id` (`users_id`),
            KEY `date_mod` (`date_mod`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        
        $DB->doQuery($query);
    }

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

    return true;
}

/**
 * Desinstalação do plugin
 *
 * @return boolean
 */
function plugin_glpipwa_uninstall() {
    global $DB;

    $migration = new Migration(PLUGIN_GLPIPWA_VERSION);
    
    // Incluir classes necessárias
    require_once(__DIR__ . '/inc/Config.php');

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
}

