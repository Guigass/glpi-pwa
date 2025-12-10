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

include('../../../../inc/includes.php');

header('Content-Type: application/json');

// Verificar autenticação e permissões
if (!Session::getLoginUserID() || !Session::haveRight('config', UPDATE)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acesso negado']);
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

// Validar CSRF token
$csrfToken = $data['_glpi_csrf_token'] ?? '';
if (!empty($csrfToken)) {
    $_POST['_glpi_csrf_token'] = $csrfToken;
    if (!Session::validateCSRF(['_glpi_csrf_token' => $csrfToken])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Token de segurança inválido']);
        exit;
    }
} else {
    // CSRF token é obrigatório para este endpoint administrativo
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token de segurança ausente']);
    exit;
}

if (!isset($data['users_id']) || !isset($data['title']) || !isset($data['body'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
    exit;
}

// Validar e sanitizar dados
$users_id = filter_var($data['users_id'], FILTER_VALIDATE_INT);
$title = isset($data['title']) ? trim($data['title']) : '';
$body = isset($data['body']) ? trim($data['body']) : '';

if (!$users_id || empty($title) || empty($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit;
}

$notification = new PluginGlpipwaNotificationPush();
$result = $notification->sendToUser(
    $users_id,
    $title,
    $body,
    $data['data'] ?? []
);

if ($result) {
    echo json_encode(['success' => true, 'message' => 'Notificação enviada']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao enviar notificação']);
}

