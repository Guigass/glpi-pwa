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
 * Classe para geração do manifest.json do PWA
 */
class PluginGlpipwaManifest
{
    /**
     * Gera o manifest.json
     */
    public static function generate()
    {
        $config = PluginGlpipwaConfig::getAll();
        
        $manifest = [
            'name' => $config['pwa_name'] ?? 'GLPI Service Desk',
            'short_name' => $config['pwa_short_name'] ?? 'GLPI',
            'start_url' => $config['pwa_start_url'] ?? '/',
            'display' => $config['pwa_display'] ?? 'standalone',
            'background_color' => $config['pwa_background_color'] ?? '#ffffff',
            'theme_color' => $config['pwa_theme_color'] ?? '#0d6efd',
            'icons' => self::getIcons(),
        ];

        if (!empty($config['pwa_orientation'])) {
            $manifest['orientation'] = $config['pwa_orientation'];
        }

        return $manifest;
    }

    /**
     * Obtém os ícones do manifest
     */
    private static function getIcons()
    {
        $icons = [];
        $icon = new PluginGlpipwaIcon();

        // Ícone 192x192
        $icon192 = $icon->getPath(192);
        if ($icon192) {
            $icons[] = [
                'src' => $icon192,
                'sizes' => '192x192',
                'type' => 'image/png',
            ];
        }

        // Ícone 512x512
        $icon512 = $icon->getPath(512);
        if ($icon512) {
            $icons[] = [
                'src' => $icon512,
                'sizes' => '512x512',
                'type' => 'image/png',
            ];
        }

        // Se não houver ícones customizados, usar padrão do GLPI
        if (empty($icons)) {
            $icons = [
                [
                    'src' => '/pics/logo-glpi.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                ],
                [
                    'src' => '/pics/logo-glpi.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                ],
            ];
        }

        return $icons;
    }

    /**
     * Retorna o manifest como JSON
     */
    public static function toJSON()
    {
        return json_encode(self::generate(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}

