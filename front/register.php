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

// Validar CSRF token - CRÍTICO: deve ser feito antes de qualquer outra operação
// O GLPI 11 tem um listener do Symfony que verifica CSRF automaticamente
// Aceitar token tanto do body JSON quanto do header HTTP
$csrfToken = $data['_glpi_csrf_token'] ?? '';
if (empty($csrfToken)) {
    // Tentar obter do header HTTP (padrão do GLPI 11)
    $csrfToken = $_SERVER['HTTP_X_GLPI_CSRF_TOKEN'] ?? '';
}
if (empty($csrfToken)) {
    // Tentar obter do header alternativo
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
}

if (!empty($csrfToken)) {
    // Colocar no $_POST para que o listener do Symfony encontre
    $_POST['_glpi_csrf_token'] = $csrfToken;
    // Validar explicitamente
    if (!Session::validateCSRF(['_glpi_csrf_token' => $csrfToken])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Token de segurança inválido',
            'title' => 'Acesso negado',
            'message' => 'A ação que você requisitou não é permitida.'
        ]);
        exit;
    }
} else {
    // CSRF token ausente - bloquear para segurança
    // No entanto, se o usuário está autenticado e a requisição vem do mesmo domínio,
    // podemos ser mais permissivos para endpoints que não são críticos
    // Mas para manter segurança, vamos requerer o token
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Token de segurança ausente',
        'title' => 'Acesso negado',
        'message' => 'Token de segurança CSRF não fornecido.'
    ]);
    exit;
}

// Validar segurança: Origin/Referer (apenas como medida adicional de segurança)
// Como já verificamos autenticação de sessão acima, confiamos na autenticação
// Requisições fetch com credentials: 'same-origin' garantem que vem do mesmo domínio
// A validação de Origin/Referer serve apenas para logging e detecção de anormalidades
$serverHost = $_SERVER['HTTP_HOST'] ?? '';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$originValidated = false;

// Verificar Origin ou Referer apenas para logging
if (!empty($serverHost)) {
    // Normalizar host (remover porta se presente)
    $serverHostNormalized = preg_replace('/:\d+$/', '', $serverHost);
    
    // Verificar Origin
    if (!empty($origin)) {
        $originHost = parse_url($origin, PHP_URL_HOST);
        if ($originHost && ($originHost === $serverHost || $originHost === $serverHostNormalized)) {
            $originValidated = true;
        }
    }
    
    // Verificar Referer se Origin não validou
    if (!$originValidated && !empty($referer)) {
        $refererHost = parse_url($referer, PHP_URL_HOST);
        if ($refererHost && ($refererHost === $serverHost || $refererHost === $serverHostNormalized)) {
            $originValidated = true;
        }
    }
}

// Log de aviso apenas se Origin/Referer não foi validado (mas não bloquear)
// Isso ajuda a detectar possíveis problemas sem bloquear requisições válidas
// de usuários autenticados
if (!$originValidated && class_exists('Toolbox')) {
    Toolbox::logWarning('GLPIPWA: Registro de token FCM sem validação de Origin/Referer. Host: ' . ($serverHost ?? 'N/A') . ', Origin: ' . ($origin ?? 'N/A') . ', Referer: ' . ($referer ?? 'N/A') . ', UserID: ' . Session::getLoginUserID());
}

// Não bloquear - o usuário está autenticado, o que é suficiente para segurança
// A validação de sessão do GLPI já garante que a requisição é legítima

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
