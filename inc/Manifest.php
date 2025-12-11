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
     * Gera o manifest.json completo
     */
    public static function generate()
    {
        $config = PluginGlpipwaConfig::getAll();
        
        $start_url = $config['pwa_start_url'] ?? '/index.php?from=pwa';
        if (empty($start_url) || $start_url === '/') {
            $start_url = '/index.php?from=pwa';
        }
        $scope = $config['pwa_scope'] ?? '/';
        if (empty($scope)) {
            $scope = '/';
        }
        
        $manifest = [
            'name' => $config['pwa_name'] ?? 'GLPI Service Desk',
            'short_name' => $config['pwa_short_name'] ?? 'GLPI',
            'description' => $config['pwa_description'] ?? '',
            'lang' => $config['pwa_lang'] ?? 'pt-BR',
            'dir' => $config['pwa_dir'] ?? 'ltr',
            'id' => $scope,
            'start_url' => $start_url,
            'scope' => $scope,
            'display' => $config['pwa_display'] ?? 'standalone',
            'orientation' => self::normalizeOrientation($config['pwa_orientation'] ?? 'any'),
            'background_color' => $config['pwa_background_color'] ?? '#ffffff',
            'theme_color' => $config['pwa_theme_color'] ?? '#0d6efd',
            'icons' => self::getIcons(),
        ];

        // Categories
        $categories = self::getCategories($config);
        if (!empty($categories)) {
            $manifest['categories'] = $categories;
        }

        // Shortcuts
        $shortcuts = self::getShortcuts($config);
        if (!empty($shortcuts)) {
            $manifest['shortcuts'] = $shortcuts;
        }

        // Edge Side Panel
        $edge_panel_width = isset($config['pwa_edge_panel_width']) ? (int)$config['pwa_edge_panel_width'] : 420;
        if ($edge_panel_width > 0) {
            $manifest['edge_side_panel'] = [
                'preferred_width' => $edge_panel_width,
            ];
        }

        // Related Applications
        $related_apps = self::getRelatedApplications($config);
        if (!empty($related_apps)) {
            $manifest['related_applications'] = $related_apps;
            $manifest['prefer_related_applications'] = isset($config['pwa_prefer_related']) && $config['pwa_prefer_related'] == '1';
        }

        return $manifest;
    }

    /**
     * Normaliza a orientação para o formato correto do manifest
     */
    private static function normalizeOrientation($orientation)
    {
        $mapping = [
            'any' => 'any',
            'portrait' => 'portrait-primary',
            'landscape' => 'landscape-primary',
        ];
        return $mapping[$orientation] ?? 'any';
    }

    /**
     * Obtém os ícones do manifest (todos os tamanhos disponíveis)
     */
    private static function getIcons()
    {
        $icons = [];
        $sizes = [48, 72, 96, 128, 144, 152, 192, 256, 384, 512];

        // Adicionar todos os tamanhos disponíveis
        foreach ($sizes as $size) {
            $icon_path = PluginGlpipwaIcon::getPath($size);
            if ($icon_path) {
                $icons[] = [
                    'src' => $icon_path,
                    'sizes' => $size . 'x' . $size,
                    'type' => 'image/png',
                ];
            }
        }

        // Adicionar versão maskable (apenas 512px)
        $maskable_path = PluginGlpipwaIcon::getPath(512, true);
        if ($maskable_path) {
            $icons[] = [
                'src' => $maskable_path,
                'sizes' => '512x512',
                'type' => 'image/png',
                'purpose' => 'any maskable',
            ];
        }

        // Se não houver ícones customizados, usar padrão do GLPI
        if (empty($icons)) {
            $icons = [
                [
                    'src' => '/pics/logos/logo-GLPI-250-white.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                ],
                [
                    'src' => '/pics/logos/logo-GLPI-250-white.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                ],
            ];
        }

        return $icons;
    }

    /**
     * Obtém as categorias do manifest
     */
    private static function getCategories($config)
    {
        if (empty($config['pwa_categories'])) {
            return [];
        }

        $categories = json_decode($config['pwa_categories'], true);
        if (!is_array($categories)) {
            return [];
        }

        // Validar categorias permitidas
        $valid_categories = [
            'productivity',
            'business',
            'utilities',
            'collaboration',
            'education',
            'entertainment',
            'finance',
            'food',
            'games',
            'health',
            'lifestyle',
            'magazines',
            'medical',
            'music',
            'news',
            'photo',
            'shopping',
            'social',
            'sports',
            'travel',
            'weather',
        ];

        return array_intersect($categories, $valid_categories);
    }

    /**
     * Obtém os shortcuts do manifest (padrão + customizados)
     */
    private static function getShortcuts($config)
    {
        $shortcuts = [];

        // Shortcuts padrão do GLPI (se habilitados)
        $default_enabled = !isset($config['pwa_shortcuts_default_enabled']) || $config['pwa_shortcuts_default_enabled'] == '1';
        if ($default_enabled) {
            $default_shortcuts = self::getDefaultShortcuts();
            $shortcuts = array_merge($shortcuts, $default_shortcuts);
        }

        // Shortcuts customizados
        if (!empty($config['pwa_shortcuts_custom'])) {
            $custom_shortcuts = json_decode($config['pwa_shortcuts_custom'], true);
            if (is_array($custom_shortcuts)) {
                foreach ($custom_shortcuts as $shortcut) {
                    if (isset($shortcut['name']) && isset($shortcut['url'])) {
                        $shortcut_data = [
                            'name' => $shortcut['name'],
                            'short_name' => $shortcut['short_name'] ?? $shortcut['name'],
                            'url' => $shortcut['url'],
                        ];

                        // Adicionar ícone se fornecido
                        if (!empty($shortcut['icon'])) {
                            $shortcut_data['icons'] = [
                                [
                                    'src' => $shortcut['icon'],
                                    'sizes' => $shortcut['icon_sizes'] ?? '96x96',
                                ],
                            ];
                        }

                        $shortcuts[] = $shortcut_data;
                    }
                }
            }
        }

        return $shortcuts;
    }

    /**
     * Retorna os shortcuts padrão do GLPI
     */
    private static function getDefaultShortcuts()
    {
        $shortcuts = [
            [
                'name' => __('Open Ticket', 'glpipwa'),
                'short_name' => __('New Ticket', 'glpipwa'),
                'url' => '/front/ticket.form.php',
            ],
            [
                'name' => __('My Tickets', 'glpipwa'),
                'short_name' => __('Tickets', 'glpipwa'),
                'url' => '/front/ticket.php',
            ],
            [
                'name' => __('Knowledge Base', 'glpipwa'),
                'short_name' => __('KB', 'glpipwa'),
                'url' => '/front/knowbaseitem.php',
            ],
        ];

        // Adicionar ícone 96x96 gerado automaticamente para todos os shortcuts
        $icon_96_path = PluginGlpipwaIcon::getPath(96);
        if ($icon_96_path) {
            foreach ($shortcuts as &$shortcut) {
                $shortcut['icons'] = [
                    [
                        'src' => $icon_96_path,
                        'sizes' => '96x96',
                        'type' => 'image/png',
                    ],
                ];
            }
        }

        return $shortcuts;
    }

    /**
     * Obtém aplicações relacionadas
     */
    private static function getRelatedApplications($config)
    {
        if (empty($config['pwa_related_app_url'])) {
            return [];
        }

        return [
            [
                'platform' => 'webapp',
                'url' => $config['pwa_related_app_url'],
            ],
        ];
    }

    /**
     * Retorna o manifest como JSON
     */
    public static function toJSON()
    {
        return json_encode(self::generate(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}

