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
 * Classe para gerenciamento de configurações do plugin
 */
class PluginGlpipwaConfig
{
    const CONTEXT = 'plugin:glpipwa';

    /**
     * Obtém um valor de configuração
     */
    public static function get($key, $default = null)
    {
        $config = Config::getConfigurationValues(self::CONTEXT, [$key]);
        return $config[$key] ?? $default;
    }

    /**
     * Define um valor de configuração
     */
    public static function set($key, $value)
    {
        Config::setConfigurationValues(self::CONTEXT, [$key => $value]);
    }

    /**
     * Obtém todas as configurações do plugin
     */
    public static function getAll()
    {
        return Config::getConfigurationValues(self::CONTEXT);
    }

    /**
     * Define múltiplas configurações
     */
    public static function setMultiple(array $configs)
    {
        Config::setConfigurationValues(self::CONTEXT, $configs);
    }

    /**
     * Valida configurações do Firebase
     */
    public static function validateFirebaseConfig(array $config)
    {
        // Campos obrigatórios básicos
        $required = [
            'firebase_api_key',
            'firebase_project_id',
            'firebase_messaging_sender_id',
            'firebase_app_id',
            'firebase_vapid_key',
        ];

        foreach ($required as $key) {
            if (empty($config[$key])) {
                return false;
            }
        }

        // Validar Service Account (apenas via JSON)
        $jsonData = $config['firebase_service_account_json'] ?? '';
        if (!empty($jsonData)) {
            $decoded = json_decode($jsonData, true);
            if ($decoded && isset($decoded['client_email']) && isset($decoded['private_key'])) {
                if (filter_var($decoded['client_email'], FILTER_VALIDATE_EMAIL)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Valida configurações do PWA
     */
    public static function validatePWAConfig(array $config)
    {
        // Validar cores hexadecimais
        if (!empty($config['pwa_theme_color']) && !preg_match('/^#[0-9A-Fa-f]{6}$/', $config['pwa_theme_color'])) {
            return false;
        }

        if (!empty($config['pwa_background_color']) && !preg_match('/^#[0-9A-Fa-f]{6}$/', $config['pwa_background_color'])) {
            return false;
        }

        // Validar display mode
        $validDisplays = ['standalone', 'fullscreen', 'minimal-ui', 'browser'];
        if (!empty($config['pwa_display']) && !in_array($config['pwa_display'], $validDisplays)) {
            return false;
        }

        // Validar orientação
        $validOrientations = ['any', 'portrait', 'landscape'];
        if (!empty($config['pwa_orientation']) && !in_array($config['pwa_orientation'], $validOrientations)) {
            return false;
        }

        // Validar idioma (formato ISO 639-1 com código de país opcional)
        if (!empty($config['pwa_lang']) && !preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $config['pwa_lang'])) {
            return false;
        }

        // Validar direção (ltr ou rtl)
        if (!empty($config['pwa_dir']) && !in_array($config['pwa_dir'], ['ltr', 'rtl'])) {
            return false;
        }

        // Validar scope (deve ser uma URL relativa válida começando com /)
        if (!empty($config['pwa_scope']) && !preg_match('/^\/.*$/', $config['pwa_scope'])) {
            return false;
        }

        // Validar start_url (deve ser uma URL relativa válida)
        if (!empty($config['pwa_start_url']) && !preg_match('/^\/.*$/', $config['pwa_start_url'])) {
            return false;
        }

        // Validar categories (deve ser JSON array válido)
        if (!empty($config['pwa_categories'])) {
            $categories = json_decode($config['pwa_categories'], true);
            if (!is_array($categories)) {
                return false;
            }
            // Validar que são strings válidas
            foreach ($categories as $category) {
                if (!is_string($category)) {
                    return false;
                }
            }
        }

        // Validar shortcuts customizados (deve ser JSON array válido)
        if (!empty($config['pwa_shortcuts_custom'])) {
            $shortcuts = json_decode($config['pwa_shortcuts_custom'], true);
            if (!is_array($shortcuts)) {
                return false;
            }
            // Validar estrutura dos shortcuts
            foreach ($shortcuts as $shortcut) {
                if (!is_array($shortcut) || empty($shortcut['name']) || empty($shortcut['url'])) {
                    return false;
                }
                // Validar URL do shortcut
                if (!preg_match('/^\/.*$/', $shortcut['url'])) {
                    return false;
                }
            }
        }

        // Validar edge panel width (deve ser inteiro positivo)
        if (isset($config['pwa_edge_panel_width'])) {
            $width = (int)$config['pwa_edge_panel_width'];
            if ($width < 0 || $width > 1000) {
                return false;
            }
        }

        // Validar related app URL (deve ser URL válida)
        if (!empty($config['pwa_related_app_url'])) {
            if (!filter_var($config['pwa_related_app_url'], FILTER_VALIDATE_URL)) {
                return false;
            }
        }

        return true;
    }
}

