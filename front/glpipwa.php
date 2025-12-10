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
 * Proxy PHP para servir o arquivo JavaScript principal do plugin
 * Este arquivo permite que o JS seja acessível mesmo com o diretório public/ do GLPI 11
 */

if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(dirname(dirname(__DIR__))));
}
include(GLPI_ROOT . '/inc/includes.php');

// Carregar classes do plugin
plugin_glpipwa_load_classes();

// Servir o arquivo JavaScript usando a classe auxiliar padronizada
$jsFile = __DIR__ . '/../js/glpipwa.js';
PluginGlpipwaStaticFileServer::serve($jsFile, 'application/javascript');

