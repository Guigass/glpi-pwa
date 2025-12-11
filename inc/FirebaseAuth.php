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
    die("Sorry. You can't access this file directly");
}

/**
 * Classe para autenticação OAuth2 com Firebase Service Account
 * Implementa JWT assinado e troca por access token OAuth2
 */
class PluginGlpipwaFirebaseAuth
{
    private static $accessTokenCache = null;
    private static $tokenExpiry = null;

    const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const TOKEN_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    const TOKEN_CACHE_DURATION = 3600; // 1 hora em segundos

    /**
     * Obtém access token OAuth2 para autenticação FCM v1
     * 
     * @return string|false Access token ou false em caso de erro
     */
    public static function getAccessToken()
    {
        // Verificar cache
        if (self::$accessTokenCache !== null && self::$tokenExpiry !== null) {
            if (time() < self::$tokenExpiry) {
                return self::$accessTokenCache;
            }
        }

        // Obter credenciais do Service Account
        $serviceAccount = self::getServiceAccountCredentials();
        
        if (!$serviceAccount) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Service Account não configurado", LOG_ERR);
            return false;
        }

        // Gerar JWT
        $jwt = self::generateJWT($serviceAccount);
        
        if (!$jwt) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro ao gerar JWT", LOG_ERR);
            return false;
        }

        // Trocar JWT por access token
        $accessToken = self::exchangeJWTForToken($jwt);
        
        if (!$accessToken) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro ao obter access token", LOG_ERR);
            return false;
        }

        // Cachear token
        self::$accessTokenCache = $accessToken;
        self::$tokenExpiry = time() + self::TOKEN_CACHE_DURATION - 60; // 1 minuto de margem

        return $accessToken;
    }

    /**
     * Obtém credenciais do Service Account da configuração
     * 
     * @return array|false Array com credenciais ou false
     */
    private static function getServiceAccountCredentials()
    {
        $config = PluginGlpipwaConfig::getAll();

        // Obter de JSON armazenado
        $jsonData = $config['firebase_service_account_json'] ?? '';
        if (!empty($jsonData)) {
            $decoded = json_decode($jsonData, true);
            if ($decoded && isset($decoded['client_email']) && isset($decoded['private_key'])) {
                $decoded['private_key'] = self::normalizePrivateKey($decoded['private_key']);
                return $decoded;
            }
        }

        return false;
    }

    /**
     * Normaliza a chave privada removendo espaços extras e garantindo formato correto
     * 
     * @param string $key Chave privada
     * @return string Chave privada normalizada
     */
    private static function normalizePrivateKey($key)
    {
        // Remover espaços extras e normalizar quebras de linha
        $key = trim($key);
        
        // Se não começar com BEGIN, adicionar
        if (strpos($key, '-----BEGIN') === false) {
            $key = "-----BEGIN PRIVATE KEY-----\n" . $key . "\n-----END PRIVATE KEY-----";
        }
        
        // Garantir que as quebras de linha sejam \n
        $key = str_replace(["\r\n", "\r"], "\n", $key);
        
        return $key;
    }

    /**
     * Gera JWT assinado para autenticação OAuth2
     * 
     * @param array $serviceAccount Credenciais do Service Account
     * @return string|false JWT assinado ou false em caso de erro
     */
    private static function generateJWT($serviceAccount)
    {
        $now = time();
        $exp = $now + 3600; // JWT válido por 1 hora

        // Header
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];

        // Payload
        $payload = [
            'iss' => $serviceAccount['client_email'],
            'scope' => self::TOKEN_SCOPE,
            'aud' => self::OAUTH_TOKEN_URL,
            'exp' => $exp,
            'iat' => $now,
        ];

        // Codificar header e payload
        $headerEncoded = self::base64UrlEncode(json_encode($header));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));

        // Criar assinatura
        $signatureInput = $headerEncoded . '.' . $payloadEncoded;
        
        $privateKey = openssl_pkey_get_private($serviceAccount['private_key']);
        if (!$privateKey) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro ao carregar chave privada: " . openssl_error_string(), LOG_ERR);
            return false;
        }

        $signature = '';
        if (!openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            openssl_free_key($privateKey);
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro ao assinar JWT: " . openssl_error_string(), LOG_ERR);
            return false;
        }

        openssl_free_key($privateKey);

        $signatureEncoded = self::base64UrlEncode($signature);

        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }

    /**
     * Troca JWT por access token OAuth2
     * 
     * @param string $jwt JWT assinado
     * @return string|false Access token ou false em caso de erro
     */
    private static function exchangeJWTForToken($jwt)
    {
        $data = [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ];

        $ch = curl_init(self::OAUTH_TOKEN_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro cURL ao obter access token: " . $error, LOG_ERR);
            return false;
        }

        if ($httpCode !== 200) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Erro HTTP ao obter access token: " . $httpCode . " - " . $response, LOG_ERR);
            return false;
        }

        $result = json_decode($response, true);
        
        if (!isset($result['access_token'])) {
            Toolbox::logInFile('glpipwa', "GLPI PWA: Access token não encontrado na resposta: " . $response, LOG_ERR);
            return false;
        }

        return $result['access_token'];
    }

    /**
     * Codifica string em Base64 URL-safe
     * 
     * @param string $data Dados para codificar
     * @return string String codificada
     */
    private static function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Limpa cache de access token (útil para testes ou forçar renovação)
     */
    public static function clearCache()
    {
        self::$accessTokenCache = null;
        self::$tokenExpiry = null;
    }
}

