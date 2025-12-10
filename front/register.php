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

include('../../../inc/includes.php');

header('Content-Type: application/json');

// Verificar autenticação
if (!Session::getLoginUserID()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

// Verificar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

// Obter dados do POST
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'JSON inválido']);
    exit;
}

// Validar CSRF token ou Origin/Referer
$csrfToken = $data['_glpi_csrf_token'] ?? '';
$validCsrf = false;
$validOrigin = false;

// Tentar validar CSRF token
if (!empty($csrfToken)) {
    $_POST['_glpi_csrf_token'] = $csrfToken;
    $validCsrf = Session::validateCSRF(['_glpi_csrf_token' => $csrfToken]);
}

// Validar Origin ou Referer como fallback para PWA
if (!$validCsrf) {
    $serverHost = $_SERVER['HTTP_HOST'] ?? '';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    
    // Verificar se Origin ou Referer correspondem ao host do servidor
    if (!empty($serverHost)) {
        if (!empty($origin) && strpos($origin, $serverHost) !== false) {
            $validOrigin = true;
        } elseif (!empty($referer) && strpos($referer, $serverHost) !== false) {
            $validOrigin = true;
        }
    }
}

// Bloquear se nem CSRF nem Origin/Referer forem válidos
if (!$validCsrf && !$validOrigin) {
    Toolbox::logWarning('GLPIPWA: Tentativa de registro de token FCM sem CSRF ou Origin válido');
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden - Invalid security token']);
    exit;
}

// Validar token FCM
if (!isset($data['token']) || empty($data['token'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Token não fornecido']);
    exit;
}

// Validar e sanitizar token
$token = isset($data['token']) ? trim($data['token']) : '';
if (empty($token) || strlen($token) > 255) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Token inválido']);
    exit;
}

// Validar formato do token FCM (deve ser alfanumérico com alguns caracteres especiais)
if (!preg_match('/^[a-zA-Z0-9_:\-]+$/', $token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Formato de token inválido']);
    exit;
}

$user_agent = isset($data['user_agent']) ? substr(trim($data['user_agent']), 0, 255) : ($_SERVER['HTTP_USER_AGENT'] ?? null);
$users_id = Session::getLoginUserID();

// Registrar token
try {
    $result = PluginGlpipwaToken::addToken($users_id, $token, $user_agent);

    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Token registrado com sucesso']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao registrar token']);
    }
} catch (Exception $e) {
    Toolbox::logError('GLPIPWA: Erro ao registrar token: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}
