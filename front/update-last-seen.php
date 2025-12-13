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

header('Content-Type: application/json');

// Verificar autenticação
if (!Session::getLoginUserID()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

// Verificar método - apenas POST
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

// Validação CSRF é feita automaticamente pelo listener do Symfony (CheckCsrfListener)
// O JavaScript obtém um token CSRF fresco via GET antes de fazer esta requisição POST

// Validar device_id
if (!isset($data['device_id']) || empty($data['device_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'device_id não fornecido']);
    exit;
}

$device_id = trim($data['device_id']);
$users_id = Session::getLoginUserID();

// Validar device_id (deve ser UUID v4)
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $device_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'device_id inválido']);
    exit;
}

// ticket_id é opcional
$ticket_id = null;
$ticket_updated_at = null;

if (isset($data['ticket_id']) && !empty($data['ticket_id'])) {
    $ticket_id = (int)$data['ticket_id'];
    if ($ticket_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ticket_id inválido']);
        exit;
    }

    // Se ticket_updated_at fornecido, usar; senão buscar do ticket
    // IMPORTANTE: Sempre usar o valor REAL de ticket.date_mod do banco,
    // NUNCA usar NOW() ou timestamp atual, pois isso quebraria a lógica de comparação
    if (isset($data['ticket_updated_at']) && !empty($data['ticket_updated_at'])) {
        $ticket_updated_at = $data['ticket_updated_at'];
    } else {
        // Buscar ticket.date_mod do banco
        // O GLPI garante que date_mod é atualizado automaticamente em todos os eventos
        $ticket = new Ticket();
        if ($ticket->getFromDB($ticket_id)) {
            $ticket_updated_at = $ticket->getField('date_mod');
        }
    }
}

// Log para debug

// Atualizar last_seen_at
try {
    $result = PluginGlpipwaDevice::updateLastSeen($users_id, $device_id, $ticket_id, $ticket_updated_at);

    if ($result === true) {
        echo json_encode(['success' => true, 'message' => 'last_seen atualizado com sucesso']);
    } elseif ($result === 'NOT_FOUND') {
        // Dispositivo não encontrado - retornar 404 para indicar que o frontend deve limpar localStorage
        Toolbox::logInFile('glpipwa', "GLPI PWA update-last-seen.php: Dispositivo não encontrado (users_id: {$users_id}, device_id: {$device_id})", LOG_WARNING);
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Dispositivo não encontrado', 'code' => 'DEVICE_NOT_FOUND']);
    } else {
        // Outro tipo de erro
        Toolbox::logInFile('glpipwa', "GLPI PWA update-last-seen.php: Falha ao atualizar last_seen (users_id: {$users_id}, device_id: {$device_id})", LOG_ERR);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao atualizar last_seen']);
    }
} catch (Exception $e) {
    Toolbox::logInFile('glpipwa', 'GLPI PWA update-last-seen.php: Exceção ao atualizar last_seen: ' . $e->getMessage() . ' - Trace: ' . $e->getTraceAsString(), LOG_ERR);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
} catch (Throwable $e) {
    Toolbox::logInFile('glpipwa', 'GLPI PWA update-last-seen.php: Erro fatal ao atualizar last_seen: ' . $e->getMessage() . ' - Trace: ' . $e->getTraceAsString(), LOG_ERR);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}
