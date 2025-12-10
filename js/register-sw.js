/**
 * Script de registro do Service Worker e inicialização do Firebase
 * Usa Firebase SDK v9 compat para compatibilidade com API legacy
 */

(function() {
    'use strict';

    // Verificar suporte a Service Worker
    if ('serviceWorker' in navigator) {
        const pluginUrl = getPluginUrl();
        
        // Registrar Service Worker com escopo ampliado via PHP proxy
        navigator.serviceWorker.register(pluginUrl + '/front/sw-proxy.php', {
            scope: '/'
        })
            .then((registration) => {
                console.log('Service Worker registrado com sucesso:', registration.scope);
                
                // Inicializar Firebase após registro do SW
                initializeFirebase(registration);
            })
            .catch((error) => {
                console.error('Erro ao registrar Service Worker:', error);
                // Fallback: tentar registrar sem escopo ampliado
                navigator.serviceWorker.register(pluginUrl + '/js/sw.js')
                    .then((registration) => {
                        console.log('Service Worker registrado (fallback):', registration.scope);
                        initializeFirebase(registration);
                    })
                    .catch((err) => {
                        console.error('Erro ao registrar Service Worker (fallback):', err);
                    });
            });
    } else {
        console.warn('Service Worker não suportado neste navegador');
    }

    /**
     * Obtém a URL base do plugin
     */
    function getPluginUrl() {
        const scripts = document.getElementsByTagName('script');
        for (let script of scripts) {
            if (script.src && script.src.includes('register-sw.js')) {
                return script.src.substring(0, script.src.lastIndexOf('/js/register-sw.js'));
            }
        }
        return '/plugins/glpipwa';
    }

    /**
     * Inicializa Firebase e solicita token FCM
     */
    function initializeFirebase(swRegistration) {
        // Verificar se Firebase está configurado
        fetchFirebaseConfig().then((firebaseConfig) => {
            if (!firebaseConfig || !firebaseConfig.apiKey) {
                console.warn('Firebase não configurado');
                return;
            }

            // Carregar Firebase SDK compat dinamicamente
            loadFirebaseSDK().then(() => {
                if (typeof firebase === 'undefined') {
                    console.error('Firebase SDK não carregado');
                    return;
                }

                // Verificar se já foi inicializado
                if (!firebase.apps.length) {
                    firebase.initializeApp(firebaseConfig);
                }
                
                const messaging = firebase.messaging();

                // Usar o service worker registrado
                messaging.useServiceWorker(swRegistration);

                // Solicitar permissão e obter token
                requestNotificationPermission(messaging, firebaseConfig.vapidKey);
            }).catch((error) => {
                console.error('Erro ao carregar Firebase SDK:', error);
            });
        }).catch((error) => {
            console.error('Erro ao obter configuração Firebase:', error);
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
     * Obtém configuração do Firebase do servidor via fetch
     */
    function fetchFirebaseConfig() {
        return fetch(getPluginUrl() + '/front/firebase-config.php', {
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
            console.error('Erro ao parsear configuração Firebase:', error);
            return null;
        });
    }

    /**
     * Solicita permissão de notificação e registra token
     */
    function requestNotificationPermission(messaging, vapidKey) {
        // Verificar se Notification API está disponível
        if (!('Notification' in window)) {
            console.warn('Notification API não suportada');
            return;
        }

        Notification.requestPermission().then((permission) => {
            if (permission === 'granted') {
                messaging.getToken({ vapidKey: vapidKey })
                    .then((currentToken) => {
                        if (currentToken) {
                            registerToken(currentToken);
                        } else {
                            console.warn('Não foi possível obter token FCM');
                        }
                    })
                    .catch((error) => {
                        console.error('Erro ao obter token FCM:', error);
                    });
            } else {
                console.warn('Permissão de notificação negada');
            }
        });

        // Listener para quando o token for atualizado
        messaging.onTokenRefresh(() => {
            messaging.getToken({ vapidKey: vapidKey })
                .then((refreshedToken) => {
                    if (refreshedToken) {
                        registerToken(refreshedToken);
                    }
                })
                .catch((error) => {
                    console.error('Erro ao atualizar token FCM:', error);
                });
        });

        // Listener para mensagens em foreground
        messaging.onMessage((payload) => {
            console.log('Mensagem recebida:', payload);
            // Exibir notificação mesmo com app aberto
            if (payload.notification && Notification.permission === 'granted') {
                new Notification(payload.notification.title, {
                    body: payload.notification.body,
                    icon: payload.notification.icon || '/pics/logo-glpi.png',
                });
            }
        });
    }

    /**
     * Registra token FCM no servidor com CSRF token
     */
    function registerToken(token) {
        const pluginUrl = getPluginUrl();
        
        // Obter CSRF token do meta tag ou do formulário
        let csrfToken = '';
        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta) {
            csrfToken = csrfMeta.getAttribute('content');
        } else {
            // Tentar obter do input hidden padrão do GLPI
            const csrfInput = document.querySelector('input[name="_glpi_csrf_token"]');
            if (csrfInput) {
                csrfToken = csrfInput.value;
            }
        }

        const data = {
            token: token,
            user_agent: navigator.userAgent,
            _glpi_csrf_token: csrfToken
        };

        fetch(pluginUrl + '/front/register.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(data)
        })
        .then((response) => {
            if (response.ok) {
                console.log('Token registrado com sucesso');
            } else {
                console.error('Erro ao registrar token:', response.statusText);
            }
        })
        .catch((error) => {
            console.error('Erro ao registrar token:', error);
        });
    }
})();
