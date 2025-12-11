/**
 * GLPI PWA Plugin - Injeção de Manifest e Registro de Service Worker
 * Este arquivo é carregado automaticamente pelo hook add_javascript do GLPI
 */

(function () {
    'use strict';

    // Obter URL do plugin dinamicamente
    function getPluginUrl() {
        // Tentar obter do caminho do script atual
        const scripts = document.getElementsByTagName('script');
        for (let i = 0; i < scripts.length; i++) {
            const src = scripts[i].src;
            if (src && src.includes('/plugins/glpipwa/')) {
                const match = src.match(/^(.*\/plugins\/glpipwa)\//);
                if (match) {
                    return match[1];
                }
            }
        }
        // Fallback
        return '/plugins/glpipwa';
    }

    // Injetar manifest no head
    function injectManifest() {
        const pluginUrl = getPluginUrl();
        const manifestUrl = pluginUrl + '/front/manifest.php';

        // Verificar se o manifest já foi injetado
        if (document.querySelector('link[rel="manifest"]')) {
            return;
        }

        const manifestLink = document.createElement('link');
        manifestLink.rel = 'manifest';
        manifestLink.href = manifestUrl;
        document.head.appendChild(manifestLink);
    }

    // Registrar Service Worker
    function registerServiceWorker() {
        if (!('serviceWorker' in navigator)) {
            console.warn('[GLPI PWA] Service Worker não suportado');
            return;
        }

        const pluginUrl = getPluginUrl();
        // Registrar diretamente o sw-proxy.php que já tem o header Service-Worker-Allowed: /
        const swUrl = pluginUrl + '/front/sw-proxy.php';

        // Aguardar o DOM estar pronto
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                registerSW();
            });
        } else {
            registerSW();
        }

        function registerSW() {
            navigator.serviceWorker.register(swUrl, {
                scope: '/'
            })
                .then(function (registration) {
                    console.log('[GLPI PWA] Service Worker registrado com sucesso');
                    // Aguardar Service Worker estar ativo antes de inicializar Firebase
                    waitForServiceWorkerActive(registration).then(function () {
                        initializeFirebase(registration);
                    }).catch(function (error) {
                        console.warn('[GLPI PWA] Erro ao aguardar Service Worker ativo:', error);
                        // Tentar inicializar mesmo assim
                        initializeFirebase(registration);
                    });
                })
                .catch(function (error) {
                    console.error('[GLPI PWA] Erro ao registrar Service Worker:', error);
                    // Fallback: tentar registrar sw.php se sw-proxy.php falhar
                    navigator.serviceWorker.register(pluginUrl + '/front/sw.php', {
                        scope: '/'
                    })
                        .then(function (registration) {
                            console.log('[GLPI PWA] Service Worker registrado com sucesso (fallback)');
                            // Aguardar Service Worker estar ativo antes de inicializar Firebase
                            waitForServiceWorkerActive(registration).then(function () {
                                initializeFirebase(registration);
                            }).catch(function (error) {
                                console.warn('[GLPI PWA] Erro ao aguardar Service Worker ativo (fallback):', error);
                                // Tentar inicializar mesmo assim
                                initializeFirebase(registration);
                            });
                        })
                        .catch(function (fallbackError) {
                            console.error('[GLPI PWA] Erro ao registrar Service Worker (fallback):', fallbackError);
                        });
                });
        }
    }

    /**
     * Obtém configuração do Firebase do servidor via fetch
     */
    function fetchFirebaseConfig() {
        const pluginUrl = getPluginUrl();
        return fetch(pluginUrl + '/front/firebase-config.php', {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json'
            }
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Erro ao buscar configuração Firebase');
                }
                return response.json();
            })
            .catch((error) => {
                // Silenciosamente ignora erros (Firebase pode não estar configurado)
                return null;
            });
    }

    /**
     * Carrega o Firebase SDK compat dinamicamente
     */
    function loadFirebaseSDK() {
        return new Promise((resolve, reject) => {
            // Verificar se já está carregado
            if (typeof firebase !== 'undefined') {
                resolve();
                return;
            }

            // Carregar scripts do Firebase compat (v9 compat = API v8)
            const appScript = document.createElement('script');
            appScript.src = 'https://www.gstatic.com/firebasejs/9.17.1/firebase-app-compat.js';
            appScript.async = false; // Carregar em ordem

            const messagingScript = document.createElement('script');
            messagingScript.src = 'https://www.gstatic.com/firebasejs/9.17.1/firebase-messaging-compat.js';
            messagingScript.async = false;

            appScript.onload = () => {
                document.head.appendChild(messagingScript);
            };

            messagingScript.onload = () => {
                resolve();
            };

            appScript.onerror = reject;
            messagingScript.onerror = reject;

            document.head.appendChild(appScript);
        });
    }

    /**
     * Aguarda o Service Worker estar no estado 'activated'
     */
    function waitForServiceWorkerActive(registration) {
        return new Promise(function (resolve, reject) {
            // Se já está ativo, resolver imediatamente
            if (registration.active && registration.active.state === 'activated') {
                resolve(registration);
                return;
            }

            // Aguardar o ready primeiro
            const readyPromise = registration.ready || Promise.resolve(registration);

            readyPromise.then(function () {
                // Verificar se já está ativo após ready
                if (registration.active && registration.active.state === 'activated') {
                    resolve(registration);
                    return;
                }

                // Se está instalando, aguardar statechange
                if (registration.installing) {
                    registration.installing.addEventListener('statechange', function () {
                        if (registration.installing.state === 'activated') {
                            resolve(registration);
                        }
                    });
                    return;
                }

                // Se está waiting, aguardar statechange
                if (registration.waiting) {
                    registration.waiting.addEventListener('statechange', function () {
                        if (registration.waiting.state === 'activated') {
                            resolve(registration);
                        }
                    });
                    return;
                }

                // Se já tem active, aguardar um pouco e verificar novamente
                if (registration.active) {
                    // Aguardar até 5 segundos para o SW estar ativo
                    let attempts = 0;
                    const maxAttempts = 50; // 5 segundos (50 * 100ms)
                    const checkInterval = setInterval(function () {
                        attempts++;
                        if (registration.active && registration.active.state === 'activated') {
                            clearInterval(checkInterval);
                            resolve(registration);
                        } else if (attempts >= maxAttempts) {
                            clearInterval(checkInterval);
                            // Resolver mesmo assim, pois pode estar funcionando
                            resolve(registration);
                        }
                    }, 100);
                    return;
                }

                // Se não há nenhum worker, rejeitar
                reject(new Error('Service Worker não está disponível'));
            }).catch(reject);
        });
    }

    /**
     * Inicializa Firebase e solicita token FCM
     */
    function initializeFirebase(swRegistration) {
        // Verificar se temos Service Worker registration
        if (!swRegistration) {
            console.warn('[GLPI PWA] Service Worker registration não disponível para Firebase');
        } else {
            // Verificar se o Service Worker está ativo
            if (swRegistration.active && swRegistration.active.state === 'activated') {
                console.log('[GLPI PWA] Service Worker está ativo, inicializando Firebase');
            } else {
                console.debug('[GLPI PWA] Service Worker ainda não está ativo, mas prosseguindo com Firebase');
            }
        }

        // Verificar se Firebase está configurado
        fetchFirebaseConfig().then((firebaseConfig) => {
            if (!firebaseConfig || !firebaseConfig.apiKey) {
                // Firebase não configurado - silenciosamente ignora
                console.debug('[GLPI PWA] Firebase não configurado, pulando inicialização');
                return;
            }

            console.log('[GLPI PWA] Configuração Firebase obtida, carregando SDK');

            // Carregar Firebase SDK compat dinamicamente
            loadFirebaseSDK().then(() => {
                if (typeof firebase === 'undefined') {
                    console.error('[GLPI PWA] Firebase SDK não carregado após tentativa');
                    return;
                }

                console.log('[GLPI PWA] Firebase SDK carregado, inicializando app');

                // Verificar se já foi inicializado
                if (!firebase.apps.length) {
                    try {
                        firebase.initializeApp(firebaseConfig);
                        console.log('[GLPI PWA] Firebase app inicializado com sucesso');
                    } catch (error) {
                        console.error('[GLPI PWA] Erro ao inicializar Firebase app:', error);
                        return;
                    }
                } else {
                    console.debug('[GLPI PWA] Firebase app já estava inicializado');
                }

                try {
                    const messaging = firebase.messaging();
                    console.log('[GLPI PWA] Firebase Messaging obtido, solicitando permissão');

                    // Solicitar permissão e obter token (passando o service worker registration)
                    requestNotificationPermission(messaging, firebaseConfig.vapidKey, swRegistration);
                } catch (error) {
                    console.error('[GLPI PWA] Erro ao obter Firebase Messaging:', error);
                }
            }).catch((error) => {
                console.error('[GLPI PWA] Erro ao carregar Firebase SDK:', error);
            });
        }).catch((error) => {
            console.debug('[GLPI PWA] Erro ao obter configuração Firebase:', error);
        });
    }

    /**
     * Solicita permissão de notificação e registra token
     */
    function requestNotificationPermission(messaging, vapidKey, swRegistration) {
        // Verificar se Notification API está disponível
        if (!('Notification' in window)) {
            console.debug('[GLPI PWA] Notification API não suportada');
            return;
        }

        // Função auxiliar para obter token FCM
        // IMPORTANTE: Deve aguardar o Service Worker estar activated
        function getFCMToken() {
            console.log('[GLPI PWA] Tentando obter token FCM...');

            // Verificar se temos Service Worker registration
            if (!swRegistration) {
                console.debug('[GLPI PWA] Sem Service Worker registration, tentando obter token sem SW');
                const tokenOptions = {
                    vapidKey: vapidKey
                };
                return messaging.getToken(tokenOptions)
                    .then((currentToken) => {
                        if (currentToken) {
                            console.log('[GLPI PWA] Token FCM obtido sem Service Worker');
                            registerToken(currentToken);
                            return currentToken;
                        } else {
                            console.warn('[GLPI PWA] Não foi possível obter token FCM sem Service Worker');
                        }
                        return null;
                    })
                    .catch((error) => {
                        console.error('[GLPI PWA] Erro ao obter token FCM sem SW:', error);
                        return null;
                    });
            }

            console.log('[GLPI PWA] Aguardando Service Worker estar ativo...');

            // Aguardar Service Worker estar ativo
            return waitForServiceWorkerActive(swRegistration).then((activeRegistration) => {
                console.log('[GLPI PWA] Service Worker está ativo, verificando pushManager...');

                // Verificar se o pushManager está disponível
                if (!activeRegistration || !activeRegistration.pushManager) {
                    console.warn('[GLPI PWA] Service Worker não tem pushManager disponível, tentando sem serviceWorkerRegistration');
                    // Tentar sem serviceWorkerRegistration
                    const tokenOptions = {
                        vapidKey: vapidKey
                    };
                    return messaging.getToken(tokenOptions)
                        .then((currentToken) => {
                            if (currentToken) {
                                console.log('[GLPI PWA] Token FCM obtido sem pushManager');
                                registerToken(currentToken);
                                return currentToken;
                            } else {
                                console.warn('[GLPI PWA] Não foi possível obter token FCM sem pushManager');
                            }
                            return null;
                        })
                        .catch((error) => {
                            console.error('[GLPI PWA] Erro ao obter token FCM sem pushManager:', error);
                            return null;
                        });
                }

                console.log('[GLPI PWA] Service Worker tem pushManager, obtendo token FCM...');

                // Service Worker está ativo e tem pushManager
                const tokenOptions = {
                    vapidKey: vapidKey,
                    serviceWorkerRegistration: activeRegistration
                };

                return messaging.getToken(tokenOptions)
                    .then((currentToken) => {
                        if (currentToken) {
                            console.log('[GLPI PWA] Token FCM obtido com sucesso');
                            registerToken(currentToken);
                            return currentToken;
                        } else {
                            console.warn('[GLPI PWA] Não foi possível obter token FCM (resposta vazia)');
                            return null;
                        }
                    })
                    .catch((error) => {
                        console.error('[GLPI PWA] Erro ao obter token FCM:', error);
                        console.log('[GLPI PWA] Tentando obter token sem serviceWorkerRegistration como fallback...');
                        // Tentar novamente sem serviceWorkerRegistration como fallback
                        const fallbackOptions = {
                            vapidKey: vapidKey
                        };
                        return messaging.getToken(fallbackOptions)
                            .then((currentToken) => {
                                if (currentToken) {
                                    console.log('[GLPI PWA] Token FCM obtido com fallback (sem serviceWorkerRegistration)');
                                    registerToken(currentToken);
                                    return currentToken;
                                } else {
                                    console.warn('[GLPI PWA] Fallback também não retornou token');
                                }
                                return null;
                            })
                            .catch((fallbackError) => {
                                console.error('[GLPI PWA] Erro no fallback ao obter token:', fallbackError);
                                return null;
                            });
                    });
            }).catch((error) => {
                console.error('[GLPI PWA] Erro ao aguardar Service Worker ativo:', error);
                console.log('[GLPI PWA] Tentando obter token mesmo sem Service Worker ativo...');
                // Tentar obter token mesmo sem Service Worker (pode funcionar em alguns casos)
                const tokenOptions = {
                    vapidKey: vapidKey
                };
                return messaging.getToken(tokenOptions)
                    .then((currentToken) => {
                        if (currentToken) {
                            console.log('[GLPI PWA] Token FCM obtido mesmo sem SW ativo');
                            registerToken(currentToken);
                            return currentToken;
                        } else {
                            console.warn('[GLPI PWA] Não foi possível obter token sem SW ativo');
                        }
                        return null;
                    })
                    .catch((error) => {
                        console.error('[GLPI PWA] Erro ao obter token sem SW ativo:', error);
                        return null;
                    });
            });
        }

        // Função auxiliar para normalizar requestPermission conforme MDN
        // Verifica se o navegador suporta Promise-based API ou usa callback (Safari antigo)
        function requestNotificationPermissionCompat() {
            // Verificar se a API está disponível
            if (typeof Notification === 'undefined' || typeof Notification.requestPermission !== 'function') {
                return Promise.reject(new Error('Notification.requestPermission não está disponível'));
            }

            // Verificar se já temos permissão (não precisa solicitar novamente)
            if (Notification.permission === 'granted') {
                return Promise.resolve('granted');
            }

            if (Notification.permission === 'denied') {
                return Promise.resolve('denied');
            }

            // Tentar verificar se suporta Promise-based API
            // Conforme MDN: tentar chamar .then() e capturar erro
            try {
                const permission = Notification.requestPermission();

                // Se retornou uma Promise (navegadores modernos - Chrome, Firefox, Edge)
                if (permission instanceof Promise) {
                    return permission;
                }

                // Se retornou uma string diretamente (navegadores muito antigos)
                // Isso não deveria acontecer na prática, mas tratamos para compatibilidade
                if (typeof permission === 'string') {
                    return Promise.resolve(permission);
                }
            } catch (error) {
                // Se deu erro ao chamar, pode ser que use callback (Safari antigo)
                // Mas na prática, navegadores modernos sempre retornam Promise
                return Promise.reject(error);
            }

            // Fallback: usar callback para Safari antigo (se necessário)
            // Mas na prática, navegadores modernos sempre retornam Promise
            return new Promise((resolve) => {
                try {
                    Notification.requestPermission((permission) => {
                        resolve(permission);
                    });
                } catch (error) {
                    // Se callback também falhar, verificar permissão atual
                    resolve(Notification.permission || 'default');
                }
            });
        }

        requestNotificationPermissionCompat()
            .then((permission) => {
                if (permission === 'granted') {
                    // Obter token inicial (a função getFCMToken já aguarda o SW estar ativo)
                    getFCMToken();

                    // Configurar listeners para atualizações do token
                    if (swRegistration) {
                        // Na API v9 compat, não há onTokenRefresh
                        // Usar eventos do Service Worker para detectar atualizações
                        swRegistration.addEventListener('updatefound', () => {
                            // Quando o SW é atualizado, aguardar novo SW estar pronto
                            const newWorker = swRegistration.installing;
                            if (newWorker) {
                                newWorker.addEventListener('statechange', () => {
                                    if (newWorker.state === 'activated') {
                                        // Aguardar um pouco para garantir que está totalmente ativo
                                        setTimeout(() => {
                                            getFCMToken();
                                        }, 1000);
                                    }
                                });
                            }
                        });

                        // Verificar periodicamente se o token mudou (a cada 5 minutos)
                        setInterval(() => {
                            getFCMToken();
                        }, 5 * 60 * 1000);
                    }
                } else {
                    // Permissão negada - silenciosamente ignora
                    console.debug('[GLPI PWA] Permissão de notificação negada:', permission);
                }
            })
            .catch((error) => {
                // Erro ao solicitar permissão - silenciosamente ignora
                console.debug('[GLPI PWA] Erro ao solicitar permissão:', error);
            });

        // Nota: Na API v9 compat, onMessage não está disponível da mesma forma
        // As mensagens em foreground são tratadas pelo Service Worker
        // O Service Worker exibirá as notificações automaticamente
    }

    /**
     * Registra token FCM no servidor
     * 
     * NOTA: Não enviamos token CSRF propositalmente!
     * No GLPI 11, tokens CSRF são single-use. Se enviarmos e o servidor validar,
     * o token será consumido e invalidado, causando falha em outras ações na mesma página.
     * A autenticação de sessão (cookies) é suficiente para segurança neste endpoint.
     */
    function registerToken(token) {
        if (!token || typeof token !== 'string' || token.length === 0) {
            console.error('[GLPI PWA] Token inválido para registro:', token);
            return;
        }

        const pluginUrl = getPluginUrl();

        const data = {
            token: token,
            user_agent: navigator.userAgent
        };

        // Preparar headers
        const headers = {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        };

        // Construir URL completa para garantir que o Origin seja enviado
        const registerUrl = pluginUrl + '/front/register.php';

        console.log('[GLPI PWA] Registrando token FCM no servidor...');

        fetch(registerUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers,
            body: JSON.stringify(data)
        })
            .then((response) => {
                if (!response.ok) {
                    // Tentar obter detalhes do erro
                    return response.json().then((errorData) => {
                        const errorMessage = errorData.error || errorData.message || response.statusText;
                        console.error('[GLPI PWA] Erro ao registrar token:', response.status, errorMessage);
                        if (errorData.debug) {
                            console.error('[GLPI PWA] Debug info:', errorData.debug);
                        }
                        if (errorData.title) {
                            console.error('[GLPI PWA] Título do erro:', errorData.title);
                        }
                        throw new Error(errorMessage);
                    }).catch((parseError) => {
                        // Se não conseguir parsear JSON, usar statusText
                        console.error('[GLPI PWA] Erro ao registrar token (sem detalhes):', response.status, response.statusText);
                        console.error('[GLPI PWA] Erro ao parsear resposta:', parseError);
                        throw new Error('Erro ' + response.status + ': ' + response.statusText);
                    });
                }
                return response.json();
            })
            .then((result) => {
                if (result && result.success) {
                    console.log('[GLPI PWA] Token registrado com sucesso no servidor');
                    if (result.message) {
                        console.log('[GLPI PWA] Mensagem do servidor:', result.message);
                    }
                } else {
                    console.warn('[GLPI PWA] Resposta do servidor não indica sucesso:', result);
                }
            })
            .catch((error) => {
                console.error('[GLPI PWA] Erro ao registrar token no servidor:', error);
                // Não fazer throw para não interromper o fluxo
            });
    }

    // Executar quando o DOM estiver pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            injectManifest();
            registerServiceWorker();
        });
    } else {
        injectManifest();
        registerServiceWorker();
    }
})();

