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
    define('GLPI_ROOT', dirname(dirname(dirname(__DIR__))));
}
include(GLPI_ROOT . '/inc/includes.php');

// Carregar classes do plugin
plugin_glpipwa_load_classes();

// Obter tamanho do ícone (192 ou 512)
$size = isset($_GET['size']) ? (int)$_GET['size'] : 192;
if (!in_array($size, [192, 512])) {
    $size = 192;
}

// Construir caminho do arquivo de ícone
$plugin_root = dirname(__DIR__);
$plugin_root_real = realpath($plugin_root);
if ($plugin_root_real === false) {
    $plugin_root_real = $plugin_root;
}
$icon_file = $plugin_root_real . DIRECTORY_SEPARATOR . 'pics' . DIRECTORY_SEPARATOR . 'icon-' . $size . '.png';

// Servir o arquivo usando a classe auxiliar padronizada
PluginGlpipwaStaticFileServer::serve($icon_file, 'image/png', 'public, max-age=86400');

