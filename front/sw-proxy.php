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
 * Proxy PHP para servir o Service Worker com escopo ampliado
 * Este arquivo permite que o SW controle todo o GLPI usando o header Service-Worker-Allowed
 */

// Limpar qualquer output anterior ANTES de qualquer coisa
if (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

// Definir GLPI_ROOT se não estiver definido
if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(dirname(dirname(__DIR__))));
}

// Carregar MinimalLoader ao invés de includes.php para evitar interferência com sessão/CSRF
$minimalLoaderFile = __DIR__ . '/../inc/MinimalLoader.php';
if (!file_exists($minimalLoaderFile)) {
    // Se não encontrar MinimalLoader, tentar método alternativo
    http_response_code(200);
    header('Content-Type: application/javascript; charset=utf-8');
    header('Service-Worker-Allowed: /');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    ob_end_clean();
    echo "// Service Worker - MinimalLoader não encontrado\n";
    echo "self.addEventListener('install', (event) => { self.skipWaiting(); });\n";
    echo "self.addEventListener('activate', (event) => { return self.clients.claim(); });\n";
    exit;
}

try {
    require_once($minimalLoaderFile);
    
    // Carregar usando MinimalLoader (sem sessão)
    if (!class_exists('PluginGlpipwaMinimalLoader') || !PluginGlpipwaMinimalLoader::load()) {
        throw new Exception('Falha ao carregar MinimalLoader');
    }
} catch (Exception $e) {
    http_response_code(200);
    header('Content-Type: application/javascript; charset=utf-8');
    header('Service-Worker-Allowed: /');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    ob_end_clean();
    echo "// Service Worker - Erro ao carregar MinimalLoader\n";
    echo "self.addEventListener('install', (event) => { self.skipWaiting(); });\n";
    echo "self.addEventListener('activate', (event) => { return self.clients.claim(); });\n";
    exit;
} catch (Throwable $e) {
    http_response_code(200);
    header('Content-Type: application/javascript; charset=utf-8');
    header('Service-Worker-Allowed: /');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    ob_end_clean();
    echo "// Service Worker - Erro fatal ao carregar MinimalLoader\n";
    echo "self.addEventListener('install', (event) => { self.skipWaiting(); });\n";
    echo "self.addEventListener('activate', (event) => { return self.clients.claim(); });\n";
    exit;
}

// Obter configuração Firebase para injetar no SW
$config = [];
try {
    if (class_exists('PluginGlpipwaConfig')) {
        $config = PluginGlpipwaConfig::getAll();
    }
} catch (Exception $e) {
    // Se houver erro ao obter configuração, usar valores vazios
    $config = [];
} catch (Throwable $e) {
    // Erro fatal também
    $config = [];
}

$firebaseConfig = [
    'apiKey' => $config['firebase_api_key'] ?? '',
    'authDomain' => ($config['firebase_project_id'] ?? '') . '.firebaseapp.com',
    'projectId' => $config['firebase_project_id'] ?? '',
    'storageBucket' => ($config['firebase_project_id'] ?? '') . '.appspot.com',
    'messagingSenderId' => $config['firebase_messaging_sender_id'] ?? '',
    'appId' => $config['firebase_app_id'] ?? '',
];

// Garantir que o JSON seja válido e seguro para inserção no JavaScript
// Limpar valores para garantir que são strings válidas
foreach ($firebaseConfig as $key => $value) {
    $firebaseConfig[$key] = (string)($value ?? '');
}

$firebaseConfigJson = json_encode($firebaseConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($firebaseConfigJson === false) {
    // Se houver erro ao gerar JSON, usar objeto vazio
    $firebaseConfigJson = '{}';
}

// Limpar qualquer output anterior antes de enviar headers
ob_end_clean();
ob_start();

// Headers para Service Worker
header('Content-Type: application/javascript; charset=utf-8');
header('Service-Worker-Allowed: /');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');

// Gerar o Service Worker com configuração injetada
try {
    $swContent = <<<JAVASCRIPT
/**
 * Service Worker para GLPI PWA
 * Gerencia cache offline e notificações push
 * Configuração Firebase injetada dinamicamente
 */

const CACHE_NAME = 'glpipwa-cache-v1';
const FIREBASE_CONFIG = {$firebaseConfigJson};

const urlsToCache = [
    '/',
    '/index.php',
];

// Importar Firebase SDK compat para messaging
importScripts('https://www.gstatic.com/firebasejs/9.17.1/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/9.17.1/firebase-messaging-compat.js');

// Inicializar Firebase se configurado
if (FIREBASE_CONFIG.apiKey) {
    firebase.initializeApp(FIREBASE_CONFIG);
    const messaging = firebase.messaging();

    // Handler para mensagens em background
    messaging.onBackgroundMessage((payload) => {
        const notificationTitle = payload.notification?.title || 'GLPI';
        
        // Usar notification_id único se disponível, senão criar um baseado em timestamp
        // Isso garante que cada notificação seja exibida separadamente
        const notificationTag = payload.data?.notification_id || 
                               `glpi-\${payload.data?.ticket_id || 'notification'}-\${Date.now()}-\${Math.random().toString(36).substr(2, 9)}`;
        
        const notificationOptions = {
            body: payload.notification?.body || '',
            icon: payload.notification?.icon || '/pics/logos/logo-GLPI-250-white.png',
            badge: '/pics/logos/logo-GLPI-250-white.png',
            data: payload.data || {},
            tag: notificationTag, // Tag único para cada notificação
            requireInteraction: false,
            timestamp: Date.now(), // Timestamp para ordenação
        };

        self.registration.showNotification(notificationTitle, notificationOptions);
    });
}

// Instalação do Service Worker
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                return cache.addAll(urlsToCache);
            })
            .catch((error) => {
                // Silenciosamente ignora erros de cache
            })
    );
    self.skipWaiting();
});

// Ativação do Service Worker
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => {
                    if (cacheName !== CACHE_NAME) {
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
    return self.clients.claim();
});

// Função auxiliar para verificar se uma URL é de autenticação
function isAuthUrl(url) {
    const urlLower = url.toLowerCase();
    const authPatterns = [
        '/front/login.php',
        '/index.php',
        '/login',
        '/logout',
        'ajax/login.php',
        'ajax/logout.php'
    ];
    
    return authPatterns.some(pattern => urlLower.includes(pattern));
}

// Função auxiliar para verificar se uma URL é um arquivo estático
function isStaticFile(url) {
    try {
        const urlLower = url.toLowerCase();
        const urlObj = new URL(url);
        const pathname = urlObj.pathname.toLowerCase();
    
    // Extensões de arquivos estáticos
    const staticExtensions = [
        '.js', '.css', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.webp', '.ico',
        '.woff', '.woff2', '.ttf', '.eot', '.otf', // Fontes
        '.mp4', '.webm', '.mp3', '.wav', // Mídia
        '.pdf', '.zip', '.tar', '.gz', // Documentos/Arquivos
        '.json', '.xml', '.txt', // Dados
        '.map' // Source maps
    ];
    
    // Verificar extensão do arquivo
    const hasStaticExtension = staticExtensions.some(ext => pathname.endsWith(ext));
    
    // Verificar paths comuns de arquivos estáticos
    const staticPaths = [
        '/pics/', '/css/', '/js/', '/lib/', '/vendor/',
        '/public/', '/assets/', '/static/', '/dist/', '/build/',
        '/fonts/', '/images/', '/img/', '/media/'
    ];
    
    const hasStaticPath = staticPaths.some(path => pathname.includes(path));
    
    // Verificar se é um arquivo de plugin estático
    const isPluginStatic = pathname.includes('/plugins/') && 
                          (hasStaticExtension || hasStaticPath);
    
    return hasStaticExtension || hasStaticPath || isPluginStatic;
    } catch (e) {
        // Se houver erro ao processar URL, não interceptar
        return false;
    }
}

// Função auxiliar para verificar se uma resposta indica autenticação necessária
function isAuthResponse(response) {
    // Não cachear respostas de redirecionamento ou erro de autenticação
    if (!response) {
        return true;
    }
    
    const status = response.status;
    if (status === 302 || status === 401 || status === 403) {
        return true;
    }
    
    // Verificar se há redirecionamento para login no header Location
    const location = response.headers.get('Location');
    if (location && isAuthUrl(location)) {
        return true;
    }
    
    return false;
}

// Interceptação de requisições (APENAS arquivos estáticos)
self.addEventListener('fetch', (event) => {
    const requestUrl = event.request.url;
    
    // Ignorar requisições não-GET
    if (event.request.method !== 'GET') {
        return;
    }
    
    // Ignorar requisições para APIs externas
    if (!requestUrl.startsWith(self.location.origin)) {
        return;
    }
    
    // INTERCEPTAR APENAS ARQUIVOS ESTÁTICOS
    // Deixar todas as outras requisições (PHP, AJAX, etc.) passarem direto
    if (!isStaticFile(requestUrl)) {
        return;
    }
    
    // NÃO interceptar páginas de autenticação - deixar passar direto
    if (isAuthUrl(requestUrl)) {
        return;
    }

    event.respondWith(
        fetch(event.request, {
            credentials: 'include', // Sempre incluir cookies de sessão
            cache: 'no-store' // Não usar cache do navegador para garantir requisições frescas
        })
            .then((response) => {
                // Não cachear respostas de autenticação
                if (isAuthResponse(response)) {
                    return response;
                }
                
                // Só cachear respostas válidas (200 OK) e do tipo basic
                if (!response || response.status !== 200 || response.type !== 'basic') {
                    return response;
                }

                // Clonar a resposta antes de cachear
                const responseToCache = response.clone();
                
                caches.open(CACHE_NAME)
                    .then((cache) => {
                        cache.put(event.request, responseToCache);
                    });
                
                return response;
            })
            .catch(() => {
                // Se a rede falhar, tentar buscar do cache
                // Mas NUNCA retornar cache para páginas de autenticação
                if (isAuthUrl(requestUrl)) {
                    return fetch(event.request, { credentials: 'include' });
                }
                return caches.match(event.request);
            })
    );
});

// Recebimento de notificações push (fallback se Firebase não processar)
self.addEventListener('push', (event) => {
    let data = {};
    
    if (event.data) {
        try {
            data = event.data.json();
        } catch (e) {
            data = { body: event.data.text() };
        }
    }

    const title = data.notification?.title || data.title || 'GLPI';
    
    // Usar notification_id único se disponível, senão criar um baseado em timestamp
    const notificationTag = data.data?.notification_id || 
                           `glpi-\${data.data?.ticket_id || 'notification'}-\${Date.now()}-\${Math.random().toString(36).substr(2, 9)}`;
    
    const options = {
        body: data.notification?.body || data.body || '',
        icon: data.notification?.icon || '/pics/logos/logo-GLPI-250-white.png',
        badge: '/pics/logos/logo-GLPI-250-white.png',
        data: data.data || {},
        tag: notificationTag, // Tag único para cada notificação
        requireInteraction: false,
        timestamp: Date.now(), // Timestamp para ordenação
    };

    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

// Clique em notificação
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const urlToOpen = event.notification.data?.url || '/';

    event.waitUntil(
        clients.matchAll({
            type: 'window',
            includeUncontrolled: true,
        })
        .then((windowClients) => {
            // Verificar se já existe uma janela aberta com o GLPI
            for (let client of windowClients) {
                if (client.url.includes(self.location.origin) && 'focus' in client) {
                    return client.focus().then(() => {
                        // Navegar para a URL se necessário
                        if (urlToOpen && client.url !== urlToOpen) {
                            return client.navigate(urlToOpen);
                        }
                    });
                }
            }
            
            // Se não houver janela aberta, abrir uma nova
            if (clients.openWindow) {
                return clients.openWindow(urlToOpen);
            }
        })
    );
});
JAVASCRIPT;

    ob_end_clean();
    echo $swContent;
    exit;
    
} catch (Exception $e) {
    // Em caso de erro, retornar um service worker mínimo que não causa problemas
    ob_end_clean();
    http_response_code(200);
    header('Content-Type: application/javascript; charset=utf-8');
    header('Service-Worker-Allowed: /');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo "// Service Worker - Erro ao carregar configuração\n";
    echo "// O Service Worker será registrado mas não funcionará até que o erro seja corrigido\n";
    echo "self.addEventListener('install', (event) => { self.skipWaiting(); });\n";
    echo "self.addEventListener('activate', (event) => { return self.clients.claim(); });\n";
    exit;
} catch (Throwable $e) {
    // Erro fatal
    ob_end_clean();
    http_response_code(200);
    header('Content-Type: application/javascript; charset=utf-8');
    header('Service-Worker-Allowed: /');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo "// Service Worker - Erro fatal\n";
    echo "self.addEventListener('install', (event) => { self.skipWaiting(); });\n";
    echo "self.addEventListener('activate', (event) => { return self.clients.claim(); });\n";
    exit;
}

