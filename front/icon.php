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
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '// MinimalLoader not found';
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
    header('Content-Type: text/plain; charset=utf-8');
    echo '// Error loading MinimalLoader';
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo '// Fatal error loading MinimalLoader';
    exit;
}

// Verificar se as classes necessárias estão disponíveis
if (!class_exists('PluginGlpipwaIcon')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo '// Icon class not found';
    exit;
}

// Obter tamanho do ícone
$size = isset($_GET['size']) ? (int)$_GET['size'] : 192;
$maskable = isset($_GET['maskable']) && $_GET['maskable'] == '1';

// Validar tamanho (permitir todos os tamanhos suportados)
$valid_sizes = [48, 72, 96, 128, 144, 152, 192, 256, 384, 512];
if (!in_array($size, $valid_sizes)) {
    $size = 192;
}

// Maskable só está disponível para 512px
if ($maskable && $size !== 512) {
    $maskable = false;
}

// Obter caminho do ícone usando a classe
$icon_path = PluginGlpipwaIcon::getPath($size, $maskable);

if (!$icon_path) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '// Icon not found';
    exit;
}

// Construir caminho físico do arquivo
$plugin_root = dirname(__DIR__);
$plugin_root_real = realpath($plugin_root);
if ($plugin_root_real === false) {
    $plugin_root_real = $plugin_root;
}

$filename = $maskable ? 'icon-' . $size . '-maskable.png' : 'icon-' . $size . '.png';
$icon_file = $plugin_root_real . DIRECTORY_SEPARATOR . 'pics' . DIRECTORY_SEPARATOR . 'icons' . DIRECTORY_SEPARATOR . $filename;

// Servir o arquivo usando a classe auxiliar padronizada
PluginGlpipwaStaticFileServer::serve($icon_file, 'image/png', 'public, max-age=86400');

