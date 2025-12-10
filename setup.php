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

define('PLUGIN_GLPIPWA_VERSION', '1.0.0');

// Incluir classes
require_once(__DIR__ . '/inc/Config.php');
require_once(__DIR__ . '/inc/Token.php');
require_once(__DIR__ . '/inc/NotificationPush.php');
require_once(__DIR__ . '/inc/Manifest.php');
require_once(__DIR__ . '/inc/Icon.php');

/**
 * Hook executado durante a sequência de inicialização do GLPI, antes da sessão ser carregada
 * e antes da inicialização dos plugins ativos.
 */
function plugin_glpipwa_boot() {
    // Indica ao GLPI que o caminho `/plugins/glpipwa/api.php` é stateless e portanto
    // não deve usar cookies de sessão nem verificar uma sessão válida.
    \Glpi\Http\SessionManager::registerPluginStatelessPath('glpipwa', '#^/api\\.php#');
}

/**
 * Inicialização do plugin
 */
function plugin_init_glpipwa() {
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['glpipwa'] = true;
    $PLUGIN_HOOKS['config_page']['glpipwa'] = 'front/config.form.php';
    
    // Hook para adicionar manifest e scripts no head
    $PLUGIN_HOOKS['add_javascript']['glpipwa'] = [
        'js/register-sw.js',
    ];
    
    $PLUGIN_HOOKS['add_html_header']['glpipwa'] = 'plugin_glpipwa_add_html_header';

    // Hooks de eventos do GLPI
    $PLUGIN_HOOKS['item_add']['glpipwa'] = [
        'Ticket' => 'plugin_glpipwa_item_add',
        'ITILFollowup' => 'plugin_glpipwa_followup_add',
    ];
    
    $PLUGIN_HOOKS['item_update']['glpipwa'] = [
        'Ticket' => 'plugin_glpipwa_item_update',
    ];

    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS['menu_toadd']['glpipwa']['config'] = 'PluginGlpipwaConfig';
    }
}

/**
 * Adiciona manifest no head
 */
function plugin_glpipwa_add_html_header() {
    $plugin_url = Plugin::getWebDir('glpipwa', false);
    echo '<link rel="manifest" href="' . $plugin_url . '/front/manifest.php">' . "\n";
}

/**
 * Retorna informações de versão do plugin
 */
function plugin_version_glpipwa() {
    return [
        'name'           => __('GLPI PWA', 'glpipwa'),
        'version'        => PLUGIN_GLPIPWA_VERSION,
        'author'         => 'GLPI Community',
        'license'        => 'GPLv2+',
        'homepage'       => 'https://github.com/glpi-project/glpi-pwa',
        'requirements'   => [
            'glpi' => [
                'min' => '11.0',
                'max' => '11.99',
            ],
            'php' => [
                'min' => '8.2',
                'exts' => [
                    'curl' => [
                        'required' => true,
                    ],
                ],
            ],
        ],
    ];
}

/**
 * Verifica pré-requisitos do plugin
 */
function plugin_glpipwa_check_prerequisites() {
    if (!defined('GLPI_VERSION')) {
        return false;
    }
    
    if (version_compare(GLPI_VERSION, '11.0', 'lt')) {
        if (method_exists('Plugin', 'messageIncompatible')) {
            Plugin::messageIncompatible('core', '11.0', '11.99');
        }
        return false;
    }
    
    if (version_compare(PHP_VERSION, '8.2', 'lt')) {
        if (method_exists('Plugin', 'messageIncompatible')) {
            Plugin::messageIncompatible('php', '8.2');
        }
        return false;
    }
    
    if (!extension_loaded('curl')) {
        if (method_exists('Plugin', 'messageMissingRequirement')) {
            Plugin::messageMissingRequirement('php', 'exts', 'curl');
        }
        return false;
    }
    
    return true;
}

/**
 * Verifica configuração do plugin
 */
function plugin_glpipwa_check_config($verbose = false) {
    return true;
}

