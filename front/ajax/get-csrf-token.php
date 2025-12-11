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
 * Endpoint para obter um novo token CSRF para registro de token FCM
 * 
 * Este endpoint é necessário porque:
 * 1. O GLPI 11 usa tokens CSRF single-use (consumidos após uso)
 * 2. Usar o token da página invalidaria o token para outras ações
 * 3. Este endpoint gera um token novo especificamente para o registro FCM
 * 
 * Segurança:
 * - Requer autenticação via sessão
 * - É uma requisição GET (não modifica dados, apenas gera token)
 * - O token gerado será usado imediatamente pelo cliente
 */

include('../../../../inc/includes.php');

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Verificar autenticação
if (!Session::getLoginUserID()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

// Apenas GET é permitido (não precisa de CSRF)
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

// Gerar novo token CSRF
// O Session::getNewCSRFToken() gera um token único que é armazenado na sessão
try {
    $csrfToken = Session::getNewCSRFToken();
    
    echo json_encode([
        'success' => true,
        'csrf_token' => $csrfToken
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao gerar token CSRF']);
}

