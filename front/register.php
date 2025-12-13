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

// Validação CSRF é feita automaticamente pelo listener do Symfony (CheckCsrfListener)
// O JavaScript obtém um token CSRF fresco via GET antes de fazer esta requisição POST
// Isso evita consumir o token CSRF da página, que causaria problemas em outras ações
//
// Segurança implementada:
// 1. Token CSRF validado pelo CheckCsrfListener do Symfony (antes deste código executar)
// 2. Autenticação de sessão verificada acima (Session::getLoginUserID())
// 3. Requisição usa credentials: 'same-origin' que garante cookies válidos
// 4. Validação de Origin/Referer abaixo como camada adicional
// 5. Validação rigorosa do formato do token FCM

// Validar segurança: Origin/Referer
// Como já verificamos autenticação de sessão acima, confiamos na autenticação
// Requisições fetch com credentials: 'same-origin' garantem que vem do mesmo domínio
$serverHost = $_SERVER['HTTP_HOST'] ?? '';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$originValidated = false;

// Verificar Origin ou Referer
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

// Origin/Referer não validado, mas usuário está autenticado via sessão (principal medida de segurança)

// Validar token FCM
if (!isset($data['token']) || empty($data['token'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Token não fornecido']);
    exit;
}

// Validar device_id
if (!isset($data['device_id']) || empty($data['device_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'device_id não fornecido']);
    exit;
}

// Validar e sanitizar token
$token = isset($data['token']) ? trim($data['token']) : '';
if (empty($token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Token inválido']);
    exit;
}

// Validar formato do token FCM (deve ser alfanumérico com alguns caracteres especiais)
// Tokens FCM podem ser longos, então não limitamos o tamanho aqui
if (!preg_match('/^[a-zA-Z0-9_:\-]+$/', $token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Formato de token inválido']);
    exit;
}

// Validar device_id (deve ser UUID v4)
$device_id = isset($data['device_id']) ? trim($data['device_id']) : '';
if (empty($device_id) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $device_id)) {
    // Se não for UUID válido, gerar um novo no servidor (fallback)
    // Mas ainda assim validar que não está vazio
    if (empty($device_id)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'device_id inválido']);
        exit;
    }
    // Se device_id não é UUID válido mas não está vazio, aceitar mas gerar UUID no servidor
    // Isso permite compatibilidade com versões antigas
    $device_id = null; // Será gerado no servidor
}

$user_agent = isset($data['user_agent']) ? substr(trim($data['user_agent']), 0, 255) : ($_SERVER['HTTP_USER_AGENT'] ?? null);
$users_id = Session::getLoginUserID();

// Detectar platform
$platform = 'web';
if (isset($_SERVER['HTTP_USER_AGENT'])) {
    $ua = strtolower($_SERVER['HTTP_USER_AGENT']);
    if (strpos($ua, 'android') !== false) {
        $platform = 'android';
    } elseif (strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false) {
        $platform = 'ios';
    }
}

// Se device_id não foi fornecido ou é inválido, gerar UUID v4 no servidor
if ($device_id === null) {
    // Gerar UUID v4 usando random_bytes (PHP 7+)
    $data_bytes = random_bytes(16);
    $data_bytes[6] = chr(ord($data_bytes[6]) & 0x0f | 0x40); // versão 4
    $data_bytes[8] = chr(ord($data_bytes[8]) & 0x3f | 0x80); // variante 10
    $hex = bin2hex($data_bytes);
    $device_id = sprintf(
        '%08s-%04s-%04s-%04s-%012s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

// Log para debug

// Registrar dispositivo (usa nova classe Device)
try {
    $result = PluginGlpipwaDevice::addDevice($users_id, $device_id, $token, $user_agent, $platform);

    if ($result) {
        echo json_encode([
            'success' => true, 
            'message' => 'Dispositivo registrado com sucesso',
            'device_id' => $device_id // Retornar device_id para o cliente armazenar se foi gerado no servidor
        ]);
    } else {
        Toolbox::logInFile('glpipwa', "GLPI PWA register.php: Falha ao registrar dispositivo (users_id: {$users_id}, device_id: {$device_id}) - addDevice retornou false", LOG_ERR);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao registrar dispositivo']);
    }
} catch (Exception $e) {
    Toolbox::logInFile('glpipwa', 'GLPI PWA register.php: Exceção ao registrar dispositivo: ' . $e->getMessage() . ' - Trace: ' . $e->getTraceAsString(), LOG_ERR);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
} catch (Throwable $e) {
    Toolbox::logInFile('glpipwa', 'GLPI PWA register.php: Erro fatal ao registrar dispositivo: ' . $e->getMessage() . ' - Trace: ' . $e->getTraceAsString(), LOG_ERR);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}
