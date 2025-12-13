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

// Função auxiliar para logging de erros no Service Worker
function swLogError(message, data = null) {
    const timestamp = new Date().toISOString();
    const logMessage = `[GLPI PWA SW \${timestamp}] \${message}`;
    console.error(logMessage, data || '');
}

// Importar Firebase SDK compat para messaging
try {
    importScripts('https://www.gstatic.com/firebasejs/9.17.1/firebase-app-compat.js');
    importScripts('https://www.gstatic.com/firebasejs/9.17.1/firebase-messaging-compat.js');
} catch (error) {
    swLogError('ERRO ao carregar Firebase SDK', error);
}

// Inicializar Firebase se configurado
if (FIREBASE_CONFIG && FIREBASE_CONFIG.apiKey) {
    try {
        firebase.initializeApp(FIREBASE_CONFIG);
        
        const messaging = firebase.messaging();

        // Handler para mensagens em background
        // ESTRATÉGIA DATA-ONLY: Mensagens agora usam apenas message.data (sem message.notification)
        // O Service Worker é o único responsável por exibir notificações via showNotification()
        // Isso elimina duplicação que ocorria quando FCM exibia automaticamente + SW também exibia
        // Referência: https://firebase.google.com/docs/cloud-messaging/concept-options#notifications_and_data_messages
        messaging.onBackgroundMessage((payload) => {
            try {
                if (!self.registration) {
                    swLogError('ERRO: self.registration não está disponível');
                    return Promise.reject(new Error('self.registration não disponível'));
                }

                // ESTRATÉGIA DATA-ONLY: Ler title e body de payload.data
                // Fallback temporário para compatibilidade com payload antigo (com message.notification)
                // TODO: Remover fallback após migração completa (data de remoção: 2025-06-01)
                const notificationTitle = payload.data?.title || payload.notification?.title || 'GLPI';
                const notificationBody = payload.data?.body || payload.notification?.body || '';
                
                // Usar tag simplificado baseado em ticket_id para substituição de notificações
                // Tag = "ticket-{ticket_id}" - já é por dispositivo porque é aplicado localmente
                const ticketId = payload.data?.ticket_id || null;
                const notificationTag = ticketId ? ('ticket-' + ticketId) : ('notification-' + Date.now());

                const notificationOptions = {
                    body: notificationBody,
                    icon: payload.notification?.icon || payload.data?.icon || '/pics/glpi.png?v1',
                    badge: '/pics/glpi.png?v1',
                    data: payload.data || {},
                    tag: notificationTag, // Tag para substituição de notificações
                    renotify: false, // Não alertar se substituindo notificação
                    requireInteraction: false,
                    timestamp: Date.now(), // Timestamp para ordenação
                };

                return self.registration.showNotification(notificationTitle, notificationOptions)
                    .catch((error) => {
                        swLogError('ERRO ao exibir notificação', error);
                        throw error;
                    });
            } catch (error) {
                swLogError('ERRO em onBackgroundMessage', {
                    error: error.message,
                    stack: error.stack,
                    payload: payload
                });
                return Promise.reject(error);
            }
        });
    } catch (error) {
        swLogError('ERRO ao inicializar Firebase ou registrar onBackgroundMessage', {
            error: error.message,
            stack: error.stack,
            config: FIREBASE_CONFIG ? {
                hasApiKey: !!FIREBASE_CONFIG.apiKey,
                hasProjectId: !!FIREBASE_CONFIG.projectId
            } : 'config vazio'
        });
    }
}

// Instalação do Service Worker
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                return cache.addAll(urlsToCache);
            })
            .catch((error) => {
                swLogError('Erro ao instalar cache (não crítico)', error);
                // Silenciosamente ignora erros de cache - não é crítico
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
        .then(() => {
            return self.clients.claim();
        })
        .catch((error) => {
            swLogError('Erro ao ativar Service Worker', error);
        })
    );
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

// Recebimento de notificações push (fallback APENAS se Firebase não estiver configurado)
// Se Firebase está configurado, ele processa via onBackgroundMessage acima
// Não registrar listener push quando Firebase está ativo para evitar duplicação
// ESTRATÉGIA DATA-ONLY: Ler title e body de data ao invés de notification
if (!FIREBASE_CONFIG || !FIREBASE_CONFIG.apiKey) {
    self.addEventListener('push', (event) => {
        try {
            let data = {};
            
            if (event.data) {
                try {
                    data = event.data.json();
                } catch (e) {
                    swLogError('Erro ao parsear push data', e);
                    data = { body: event.data.text() };
                }
            }

            // ESTRATÉGIA DATA-ONLY: Ler title e body de data.title e data.body
            // Fallback temporário para compatibilidade com payload antigo (com notification)
            // TODO: Remover fallback após migração completa (data de remoção: 2025-06-01)
            const title = data.data?.title || data.notification?.title || data.title || 'GLPI';
            const body = data.data?.body || data.notification?.body || data.body || '';

            // Usar tag simplificado baseado em ticket_id para substituição de notificações
            // Tag = "ticket-{ticket_id}" - já é por dispositivo porque é aplicado localmente
            const ticketId = data.data?.ticket_id || null;
            const notificationTag = ticketId ? 'ticket-' + ticketId : 'notification-' + Date.now();

            const options = {
                body: body,
                icon: data.notification?.icon || data.data?.icon || '/pics/glpi.png?v1',
                badge: '/pics/glpi.png?v1',
                data: data.data || {},
                tag: notificationTag, // Tag para substituição de notificações
                renotify: false, // Não alertar se substituindo notificação
                requireInteraction: false,
                timestamp: Date.now(), // Timestamp para ordenação
            };

            if (!self.registration) {
                swLogError('ERRO: self.registration não está disponível no push event');
                return;
            }

            event.waitUntil(
                self.registration.showNotification(title, options)
                    .catch((error) => {
                        swLogError('ERRO ao exibir notificação push (fallback)', error);
                    })
            );
        } catch (error) {
            swLogError('ERRO no push event listener (fallback)', {
                error: error.message,
                stack: error.stack
            });
        }
    });
}

// Clique em notificação
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const urlToOpen = event.notification.data?.url || '/';
    const ticketId = event.notification.data?.ticket_id;

    // Função para atualizar last_seen_at
    function updateLastSeen(deviceId, ticketId) {
        if (!deviceId) {
            return Promise.resolve();
        }

        // Obter token CSRF via mensagem para o cliente
        return clients.matchAll({
            type: 'window',
            includeUncontrolled: true,
        })
        .then((windowClients) => {
            if (windowClients.length === 0) {
                return Promise.resolve();
            }

            // Enviar mensagem para o cliente para obter CSRF token e device_id
            const client = windowClients[0];
            return client.postMessage({
                type: 'GET_CSRF_TOKEN',
                action: 'update_last_seen',
                ticket_id: ticketId
            });
        })
        .catch((error) => {
            // Silenciosamente ignora erros
            return Promise.resolve();
        });
    }

    // Tentar obter device_id do localStorage via mensagem para o cliente
    // Se não conseguir, continuar sem atualizar last_seen
    clients.matchAll({
        type: 'window',
        includeUncontrolled: true,
    })
    .then((windowClients) => {
        if (windowClients.length > 0) {
            // Enviar mensagem para o cliente para atualizar last_seen
            const client = windowClients[0];
            client.postMessage({
                type: 'UPDATE_LAST_SEEN',
                ticket_id: ticketId
            });
        }
    })
    .catch(() => {
        // Silenciosamente ignora erros
    });

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

