/**
 * Service Worker para GLPI PWA (Fallback)
 * Este arquivo é usado como fallback caso o sw-proxy.php não funcione
 * Gerencia cache offline e notificações push
 */

const CACHE_NAME = 'glpipwa-cache-v1';
const urlsToCache = [
    '/',
    '/index.php',
];

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

// Recebimento de notificações push
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
    // Isso garante que cada notificação seja exibida separadamente
    const notificationTag = data.data?.notification_id || 
                           `glpi-${data.data?.ticket_id || 'notification'}-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
    
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
