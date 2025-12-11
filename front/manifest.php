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

// Carregar MinimalLoader ao invés de includes.php para evitar interferência com sessão/CSRF
$minimalLoaderFile = __DIR__ . '/../inc/MinimalLoader.php';
if (!file_exists($minimalLoaderFile)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'MinimalLoader not found']);
    exit;
}

try {
    require_once($minimalLoaderFile);
    
    // Carregar usando MinimalLoader (sem sessão)
    if (!class_exists('PluginGlpipwaMinimalLoader') || !PluginGlpipwaMinimalLoader::load()) {
        throw new Exception('Failed to load MinimalLoader');
    }
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Error loading MinimalLoader: ' . $e->getMessage()]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Fatal error loading MinimalLoader: ' . $e->getMessage()]);
    exit;
}

// Verificar se as classes necessárias estão disponíveis
if (!class_exists('PluginGlpipwaManifest') || !class_exists('PluginGlpipwaConfig')) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Plugin classes not loaded']);
    exit;
}

// Headers devem ser definidos antes de qualquer output
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');

try {
    $manifest = PluginGlpipwaManifest::generate();
    echo PluginGlpipwaManifest::toJSON();
    exit;
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Error generating manifest: ' . $e->getMessage()]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Error generating manifest: ' . $e->getMessage()]);
    exit;
}
