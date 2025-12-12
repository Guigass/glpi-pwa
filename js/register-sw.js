/**
 * Script de registro do Service Worker e inicialização do Firebase
 * Usa Firebase SDK v9 compat para compatibilidade com API legacy
 */

(function () {
    'use strict';

    // Verificar suporte a Service Worker
    if ('serviceWorker' in navigator) {
        const pluginUrl = getPluginUrl();

        // Registrar Service Worker com escopo ampliado via PHP proxy
        navigator.serviceWorker.register(pluginUrl + '/front/sw-proxy.php', {
            scope: '/'
        })
            .then((registration) => {
                // Inicializar Firebase após registro do SW
                initializeFirebase(registration);
            })
            .catch((error) => {
                console.error('[GLPI PWA] Erro ao registrar Service Worker:', error);
                // Fallback: tentar registrar sem escopo ampliado usando endpoint PHP
                navigator.serviceWorker.register(pluginUrl + '/front/sw.php')
                    .then((registration) => {
                        initializeFirebase(registration);
                    })
                    .catch((err) => {
                        console.error('[GLPI PWA] Erro ao registrar Service Worker (fallback):', err);
                    });
            });
    }

    /**
     * Obtém a URL base do plugin
     */
    function getPluginUrl() {
        const scripts = document.getElementsByTagName('script');
        for (let script of scripts) {
            if (script.src && script.src.includes('register-sw')) {
                // Suporta tanto /js/register-sw.js quanto /front/register-sw.php
                if (script.src.includes('/front/register-sw.php')) {
                    return script.src.substring(0, script.src.lastIndexOf('/front/register-sw.php'));
                }
                if (script.src.includes('/js/register-sw.js')) {
                    return script.src.substring(0, script.src.lastIndexOf('/js/register-sw.js'));
                }
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
                return;
            }

            // Carregar Firebase SDK compat dinamicamente
            loadFirebaseSDK().then(() => {
                if (typeof firebase === 'undefined') {
                    console.error('[GLPI PWA] Firebase SDK não carregado');
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
                console.error('[GLPI PWA] Erro ao carregar Firebase SDK:', error);
            });
        }).catch((error) => {
            // Silenciosamente ignora erros de configuração
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
                return null;
            });
    }

    /**
     * Verifica se o dispositivo está rodando como PWA instalado
     * 
     * @return {boolean} true se está rodando como PWA, false caso contrário
     */
    function isPWA() {
        // Android/Chrome/Edge - verificar display-mode standalone ou fullscreen
        if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) {
            return true;
        }
        if (window.matchMedia && window.matchMedia('(display-mode: fullscreen)').matches) {
            return true;
        }
        // iOS Safari - verificar navigator.standalone
        if (window.navigator.standalone === true) {
            return true;
        }
        return false;
    }

    /**
     * Verifica se o dispositivo é mobile
     * 
     * @return {boolean} true se é mobile, false caso contrário
     */
    function isMobile() {
        const ua = navigator.userAgent.toLowerCase();
        const mobileRegex = /android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini/i;
        // Verificar user agent ou combinação de touch e largura da tela
        return mobileRegex.test(ua) || ('ontouchstart' in window && window.innerWidth < 1024);
    }

    /**
     * Verifica se deve registrar o token FCM baseado no tipo de dispositivo e modo de execução
     * 
     * Regras:
     * - Desktop: sempre registra
     * - Mobile no navegador: não registra
     * - Mobile no PWA: registra
     * 
     * @return {boolean} true se deve registrar, false caso contrário
     */
    function shouldRegisterToken() {
        // Desktop sempre registra
        if (!isMobile()) {
            return true;
        }
        // Mobile só registra se for PWA
        return isPWA();
    }

    /**
     * Solicita permissão de notificação e registra token
     */
    function requestNotificationPermission(messaging, vapidKey) {
        // Verificar se Notification API está disponível
        if (!('Notification' in window)) {
            return;
        }

        Notification.requestPermission().then((permission) => {
            if (permission === 'granted') {
                messaging.getToken({ vapidKey: vapidKey })
                    .then((currentToken) => {
                        if (currentToken && shouldRegisterToken()) {
                            registerToken(currentToken);
                        }
                    })
                    .catch((error) => {
                        console.error('[GLPI PWA] Erro ao obter token FCM:', error);
                    });
            }
        });

        // Listener para quando o token for atualizado
        messaging.onTokenRefresh(() => {
            messaging.getToken({ vapidKey: vapidKey })
                .then((refreshedToken) => {
                    if (refreshedToken && shouldRegisterToken()) {
                        registerToken(refreshedToken);
                    }
                })
                .catch((error) => {
                    console.error('[GLPI PWA] Erro ao atualizar token FCM:', error);
                });
        });

        // Listener para mensagens em foreground
        messaging.onMessage((payload) => {
            // Exibir notificação mesmo com app aberto
            if (payload.notification && Notification.permission === 'granted') {
                new Notification(payload.notification.title, {
                    body: payload.notification.body,
                    icon: payload.notification.icon || '/pics/glpi.png?v1',
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
                if (!response.ok) {
                    console.error('[GLPI PWA] Erro ao registrar token:', response.statusText);
                }
            })
            .catch((error) => {
                console.error('[GLPI PWA] Erro ao registrar token:', error);
            });
    }
})();
