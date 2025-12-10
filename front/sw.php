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

/**
 * Proxy PHP para servir o Service Worker (fallback)
 * Este arquivo serve o sw.js quando o sw-proxy.php não funcionar
 * Permite que o SW seja acessível mesmo com o diretório public/ do GLPI 11
 */

if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(dirname(dirname(__DIR__))));
}
include(GLPI_ROOT . '/inc/includes.php');

// Carregar classes do plugin
plugin_glpipwa_load_classes();

// Verificar se o arquivo existe
$jsFile = __DIR__ . '/../js/sw.js';
if (!file_exists($jsFile) || !is_readable($jsFile)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '// File not found';
    exit;
}

// Headers para Service Worker (incluindo Service-Worker-Allowed para permitir escopo /)
header('Content-Type: application/javascript; charset=utf-8');
header('Service-Worker-Allowed: /');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');

// Servir o arquivo
readfile($jsFile);
exit;

