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

/**
 * Carrega as classes do plugin quando necessário
 */
function plugin_glpipwa_load_classes() {
    static $loaded = false;
    if (!$loaded && defined('GLPI_ROOT')) {
        try {
            $files = [
                __DIR__ . '/inc/Config.php',
                __DIR__ . '/inc/Token.php',
                __DIR__ . '/inc/FirebaseAuth.php',
                __DIR__ . '/inc/NotificationPush.php',
                __DIR__ . '/inc/Manifest.php',
                __DIR__ . '/inc/Icon.php',
                __DIR__ . '/inc/Cron.php',
                __DIR__ . '/inc/StaticFileServer.php',
            ];
            
            foreach ($files as $file) {
                if (file_exists($file)) {
                    require_once($file);
                }
            }
            $loaded = true;
        } catch (Exception $e) {
            // Silenciosamente ignora erros ao carregar classes
        } catch (Throwable $e) {
            // Silenciosamente ignora erros fatais também
        }
    }
}

/**
 * Hook executado durante a sequência de inicialização do GLPI, antes da sessão ser carregada
 * e antes da inicialização dos plugins ativos.
 */
function plugin_glpipwa_boot() {
    // Verificar se a classe SessionManager existe antes de usar
    if (class_exists('\Glpi\Http\SessionManager') && method_exists('\Glpi\Http\SessionManager', 'registerPluginStatelessPath')) {
        try {
            // Indica ao GLPI que os seguintes caminhos são stateless e portanto
            // não devem usar cookies de sessão nem verificar uma sessão válida.
            // Isso é necessário para manifest, service workers e outros recursos públicos
            // O padrão corresponde a: /plugins/glpipwa/front/(manifest|sw-proxy|sw|icon).php
            \Glpi\Http\SessionManager::registerPluginStatelessPath('glpipwa', '#^/front/(manifest|sw-proxy|sw|icon)\\.php#');
        } catch (Exception $e) {
            // Silenciosamente ignora se não for possível registrar
        } catch (Throwable $e) {
            // Silenciosamente ignora erros fatais também
        }
    }
}

/**
 * Inicialização do plugin
 */
function plugin_init_glpipwa() {
    global $PLUGIN_HOOKS;

    // Verificar se GLPI_ROOT está definido antes de continuar
    if (!defined('GLPI_ROOT')) {
        return;
    }

    // Carregar classes do plugin
    plugin_glpipwa_load_classes();

    // Registrar hooks básicos - sempre
    $PLUGIN_HOOKS['csrf_compliant']['glpipwa'] = true;
    
    // Hook de configuração - IMPORTANTE: sempre registrar para mostrar o ícone de engrenagem
    $PLUGIN_HOOKS['config_page']['glpipwa'] = 'front/config.form.php';
    
    // Hook para adicionar JavaScript em todas as páginas
    // Segundo a documentação oficial do GLPI (https://glpi-developer-documentation.readthedocs.io/en/master/plugins/hooks.html)
    // O hook add_javascript adiciona JavaScript em todas as páginas headers
    // No GLPI 11, usamos um proxy PHP para servir o arquivo JS (front/glpipwa.php)
    // que serve o arquivo js/glpipwa.js
    $PLUGIN_HOOKS['add_javascript']['glpipwa'] = ['front/glpipwa.php'];

    // Hooks de eventos do GLPI para notificações
    $PLUGIN_HOOKS['item_add']['glpipwa'] = [
        'Ticket' => 'plugin_glpipwa_item_add',
        'ITILFollowup' => 'plugin_glpipwa_followup_add',
    ];
    
    $PLUGIN_HOOKS['item_update']['glpipwa'] = [
        'Ticket' => 'plugin_glpipwa_item_update',
    ];
}

/**
 * Retorna informações de versão do plugin
 * Esta função DEVE existir e ser acessível para o GLPI carregar o plugin
 */
function plugin_version_glpipwa() {
    // Não usar __() aqui pois pode não estar disponível quando o plugin é carregado
    // Retornar array simples sem try/catch para evitar qualquer problema
    return [
        'name'           => 'GLPI PWA',
        'version'        => defined('PLUGIN_GLPIPWA_VERSION') ? PLUGIN_GLPIPWA_VERSION : '1.0.0',
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

