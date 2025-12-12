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
                    // Aguardar Service Worker estar ativo antes de inicializar Firebase
                    waitForServiceWorkerActive(registration).then(function () {
                        initializeFirebase(registration);
                    }).catch(function (error) {
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
                            // Aguardar Service Worker estar ativo antes de inicializar Firebase
                            waitForServiceWorkerActive(registration).then(function () {
                                initializeFirebase(registration);
                            }).catch(function (error) {
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
     * Chave para armazenar token FCM no localStorage
     */
    const FCM_TOKEN_STORAGE_KEY = 'glpipwa_fcm_token';
    const FCM_TOKEN_TIMESTAMP_KEY = 'glpipwa_fcm_token_timestamp';

    /**
     * Obtém o token FCM armazenado no localStorage
     * 
     * @return {string|null} Token FCM ou null se não existir
     */
    function getStoredFCMToken() {
        try {
            if (typeof Storage !== 'undefined' && localStorage) {
                return localStorage.getItem(FCM_TOKEN_STORAGE_KEY);
            }
        } catch (e) {
            // localStorage pode não estar disponível (modo privado, etc)
            console.warn('[GLPI PWA] Não foi possível acessar localStorage:', e);
        }
        return null;
    }

    /**
     * Armazena o token FCM no localStorage
     * 
     * @param {string} token Token FCM para armazenar
     */
    function storeFCMToken(token) {
        try {
            if (typeof Storage !== 'undefined' && localStorage && token) {
                localStorage.setItem(FCM_TOKEN_STORAGE_KEY, token);
                localStorage.setItem(FCM_TOKEN_TIMESTAMP_KEY, Date.now().toString());
            }
        } catch (e) {
            // localStorage pode não estar disponível (modo privado, etc)
            console.warn('[GLPI PWA] Não foi possível armazenar token no localStorage:', e);
        }
    }

    /**
     * Remove o token FCM do localStorage
     */
    function clearStoredFCMToken() {
        try {
            if (typeof Storage !== 'undefined' && localStorage) {
                localStorage.removeItem(FCM_TOKEN_STORAGE_KEY);
                localStorage.removeItem(FCM_TOKEN_TIMESTAMP_KEY);
            }
        } catch (e) {
            // localStorage pode não estar disponível
            console.warn('[GLPI PWA] Não foi possível limpar token do localStorage:', e);
        }
    }

    /**
     * Verifica se o token armazenado ainda é válido
     * Tokens FCM geralmente são válidos por muito tempo, mas verificamos se não é muito antigo
     * 
     * @param {number} maxAge Tempo máximo em milissegundos (padrão: 30 dias)
     * @return {boolean} true se o token é válido, false caso contrário
     */
    function isStoredTokenValid(maxAge = 30 * 24 * 60 * 60 * 1000) {
        try {
            if (typeof Storage !== 'undefined' && localStorage) {
                const timestamp = localStorage.getItem(FCM_TOKEN_TIMESTAMP_KEY);
                if (!timestamp) {
                    return false;
                }
                const age = Date.now() - parseInt(timestamp, 10);
                return age < maxAge;
            }
        } catch (e) {
            // localStorage pode não estar disponível
        }
        return false;
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
        // Verificar se Firebase está configurado
        fetchFirebaseConfig().then((firebaseConfig) => {
            if (!firebaseConfig || !firebaseConfig.apiKey) {
                // Firebase não configurado - silenciosamente ignora
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
                    try {
                        firebase.initializeApp(firebaseConfig);
                    } catch (error) {
                        console.error('[GLPI PWA] Erro ao inicializar Firebase app:', error);
                        return;
                    }
                }

                try {
                    const messaging = firebase.messaging();
                    // Solicitar permissão e obter token (passando o service worker registration)
                    requestNotificationPermission(messaging, firebaseConfig.vapidKey, swRegistration);
                } catch (error) {
                    console.error('[GLPI PWA] Erro ao obter Firebase Messaging:', error);
                }
            }).catch((error) => {
                console.error('[GLPI PWA] Erro ao carregar Firebase SDK:', error);
            });
        }).catch((error) => {
            // Silenciosamente ignora erros de configuração
        });
    }

    /**
     * Solicita permissão de notificação e registra token
     */
    function requestNotificationPermission(messaging, vapidKey, swRegistration) {
        // Verificar se Notification API está disponível
        if (!('Notification' in window)) {
            return;
        }

        // Função auxiliar para obter token FCM
        // IMPORTANTE: Deve aguardar o Service Worker estar activated
        // OTIMIZAÇÃO: Verifica token armazenado antes de solicitar novo ao Firebase
        function getFCMToken(forceRefresh = false) {
            // Função auxiliar para verificar e registrar token se necessário
            // Esta função sempre verifica as condições atuais, mesmo para tokens armazenados
            // Rastreia as condições quando registra para detectar mudanças futuras
            const checkAndRegisterTokenIfNeeded = (token) => {
                if (!token) {
                    return;
                }

                const shouldRegister = shouldRegisterToken();
                const registrationStateKey = 'glpipwa_token_state_' + token;

                try {
                    const storedState = localStorage.getItem(registrationStateKey);
                    // storedState contém: "registered|isMobile|isPWA" ou null
                    // Exemplo: "registered|1|0" significa: registrado quando era mobile não-PWA
                    const storedParts = storedState ? storedState.split('|') : [];
                    const wasRegistered = storedParts[0] === 'registered';
                    const storedIsMobile = storedParts[1] === '1';
                    const storedIsPWA = storedParts[2] === '1';

                    // Verificar se as condições atuais requerem registro
                    const currentIsMobile = isMobile();
                    const currentIsPWA = isPWA();
                    const conditionsChanged = storedIsMobile !== currentIsMobile || storedIsPWA !== currentIsPWA;

                    if (shouldRegister) {
                        // Deve registrar: verificar se precisa fazer registro
                        if (!wasRegistered || conditionsChanged) {
                            // Nunca foi registrado ou condições mudaram - registrar
                            registerToken(token);
                            // Armazenar estado atual
                            const newState = 'registered|' + (currentIsMobile ? '1' : '0') + '|' + (currentIsPWA ? '1' : '0');
                            localStorage.setItem(registrationStateKey, newState);
                        }
                        // Se já estava registrado e condições não mudaram, não precisa fazer nada
                    } else {
                        // Não deve registrar - limpar flag se existia
                        if (wasRegistered) {
                            localStorage.removeItem(registrationStateKey);
                        }
                    }
                } catch (e) {
                    // Se localStorage falhar, tentar registrar mesmo assim se deve registrar
                    if (shouldRegister) {
                        registerToken(token);
                    }
                }
            };

            // Função auxiliar para processar token obtido do Firebase
            const processToken = (currentToken) => {
                if (currentToken) {
                    // IMPORTANTE: Verificar token armazenado ANTES de armazenar o novo
                    const storedToken = getStoredFCMToken();
                    const needsRegistration = currentToken !== storedToken;

                    // Armazenar token no localStorage
                    storeFCMToken(currentToken);

                    // Se o token mudou, limpar flag de registro do token anterior
                    if (needsRegistration && storedToken) {
                        try {
                            const oldStateKey = 'glpipwa_token_state_' + storedToken;
                            localStorage.removeItem(oldStateKey);
                        } catch (e) {
                            // Ignorar erros de localStorage
                        }
                    }

                    // Verificar e registrar se necessário (sempre verifica condições atuais)
                    checkAndRegisterTokenIfNeeded(currentToken);

                    return currentToken;
                } else {
                    // Se não há token, limpar armazenamento
                    clearStoredFCMToken();
                    return null;
                }
            };

            // Função auxiliar para obter token do Firebase (após SW estar pronto)
            const fetchTokenFromFirebase = (activeRegistration) => {
                // Verificar se o pushManager está disponível
                if (!activeRegistration || !activeRegistration.pushManager) {
                    // Tentar sem serviceWorkerRegistration
                    const tokenOptions = {
                        vapidKey: vapidKey
                    };
                    return messaging.getToken(tokenOptions)
                        .then(processToken)
                        .catch((error) => {
                            console.error('[GLPI PWA] Erro ao obter token FCM:', error);
                            return null;
                        });
                }

                // Service Worker está ativo e tem pushManager
                const tokenOptions = {
                    vapidKey: vapidKey,
                    serviceWorkerRegistration: activeRegistration
                };

                return messaging.getToken(tokenOptions)
                    .then(processToken)
                    .catch((error) => {
                        console.error('[GLPI PWA] Erro ao obter token FCM:', error);
                        // Tentar novamente sem serviceWorkerRegistration como fallback
                        const fallbackOptions = {
                            vapidKey: vapidKey
                        };
                        return messaging.getToken(fallbackOptions)
                            .then(processToken)
                            .catch((fallbackError) => {
                                console.error('[GLPI PWA] Erro no fallback ao obter token:', fallbackError);
                                return null;
                            });
                    });
            };

            // Verificar se já temos um token válido armazenado (e não forçamos refresh)
            if (!forceRefresh) {
                const storedToken = getStoredFCMToken();
                if (storedToken && isStoredTokenValid()) {
                    // Token válido encontrado
                    // IMPORTANTE: Verificar se deve registrar mesmo para token armazenado
                    // Isso garante que se as condições mudaram (ex: PWA instalado), o token será registrado
                    checkAndRegisterTokenIfNeeded(storedToken);

                    // IMPORTANTE: Mesmo com token armazenado, precisamos aguardar o SW estar pronto
                    // para evitar erros do Firebase ao tentar acessar pushManager
                    if (!swRegistration) {
                        // Sem SW, podemos retornar o token armazenado diretamente
                        return Promise.resolve(storedToken);
                    }

                    // Com SW, aguardar estar pronto antes de retornar
                    // Isso evita erros do Firebase ao tentar acessar pushManager de undefined
                    return waitForServiceWorkerActive(swRegistration)
                        .then(() => {
                            // SW está pronto, verificar novamente se deve registrar (pode ter mudado durante a espera)
                            checkAndRegisterTokenIfNeeded(storedToken);
                            return storedToken;
                        })
                        .catch((error) => {
                            // Se houver erro ao aguardar SW, tentar obter token do Firebase
                            console.warn('[GLPI PWA] Erro ao aguardar SW, tentando obter token do Firebase:', error);
                            const tokenOptions = {
                                vapidKey: vapidKey
                            };
                            return messaging.getToken(tokenOptions)
                                .then(processToken)
                                .catch(() => {
                                    // Se falhar, verificar se deve registrar o token armazenado
                                    checkAndRegisterTokenIfNeeded(storedToken);
                                    return storedToken;
                                });
                        });
                }
            }

            // Não temos token válido ou forçamos refresh - obter do Firebase
            // Verificar se temos Service Worker registration
            if (!swRegistration) {
                const tokenOptions = {
                    vapidKey: vapidKey
                };
                return messaging.getToken(tokenOptions)
                    .then(processToken)
                    .catch((error) => {
                        console.error('[GLPI PWA] Erro ao obter token FCM:', error);
                        return null;
                    });
            }

            // Aguardar Service Worker estar ativo antes de obter token
            return waitForServiceWorkerActive(swRegistration)
                .then(fetchTokenFromFirebase)
                .catch((error) => {
                    console.error('[GLPI PWA] Erro ao aguardar Service Worker ativo:', error);
                    // Tentar obter token mesmo sem Service Worker (pode funcionar em alguns casos)
                    const tokenOptions = {
                        vapidKey: vapidKey
                    };
                    return messaging.getToken(tokenOptions)
                        .then(processToken)
                        .catch((error) => {
                            console.error('[GLPI PWA] Erro ao obter token:', error);
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
                    // Obter token inicial (a função getFCMToken já verifica token armazenado)
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
                                        // Forçar refresh do token quando o SW é atualizado
                                        setTimeout(() => {
                                            getFCMToken(true);
                                        }, 1000);
                                    }
                                });
                            }
                        });

                        // Verificar periodicamente se o token mudou (a cada 30 minutos)
                        // Reduzido de 5 para 30 minutos, pois agora verificamos token armazenado
                        setInterval(() => {
                            // Verificar se o token armazenado ainda é válido antes de chamar Firebase
                            const storedToken = getStoredFCMToken();
                            if (!storedToken || !isStoredTokenValid()) {
                                // Só chamar Firebase se não temos token válido
                                getFCMToken(true);
                            } else {
                                // Temos token válido, apenas verificar se ainda é o mesmo (sem forçar refresh)
                                getFCMToken(false);
                            }
                        }, 30 * 60 * 1000); // 30 minutos
                    }
                }
            })
            .catch((error) => {
                // Erro ao solicitar permissão - silenciosamente ignora
            });

        // Nota: Na API v9 compat, onMessage não está disponível da mesma forma
        // As mensagens em foreground são tratadas pelo Service Worker
        // O Service Worker exibirá as notificações automaticamente
    }

    /**
     * Obtém um token CSRF fresco do servidor
     * 
     * No GLPI 11, tokens CSRF são single-use. Precisamos obter um token novo
     * especificamente para o registro do FCM, para não consumir o token da página.
     */
    function getNewCSRFToken() {
        const pluginUrl = getPluginUrl();
        const csrfUrl = pluginUrl + '/front/ajax/get-csrf-token.php';

        return fetch(csrfUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json'
            }
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Erro ao obter token CSRF: ' + response.status);
                }
                return response.json();
            })
            .then((data) => {
                if (data.success && data.csrf_token) {
                    return data.csrf_token;
                }
                throw new Error('Token CSRF não retornado');
            });
    }

    /**
     * Registra token FCM no servidor
     * 
     * Primeiro obtém um token CSRF fresco via GET, depois usa esse token na requisição POST.
     * Isso evita consumir o token CSRF da página, que causaria problemas em outras ações.
     * 
     * NOTA: A verificação se o token precisa ser registrado é feita ANTES de chamar esta função,
     * em processToken() ou no fluxo de token armazenado. Esta função sempre tenta registrar.
     */
    function registerToken(token) {
        if (!token || typeof token !== 'string' || token.length === 0) {
            console.error('[GLPI PWA] Token inválido para registro');
            return;
        }

        const pluginUrl = getPluginUrl();

        // Primeiro obter token CSRF fresco
        getNewCSRFToken()
            .then((csrfToken) => {
                const data = {
                    token: token,
                    user_agent: navigator.userAgent,
                    _glpi_csrf_token: csrfToken
                };

                // Preparar headers
                const headers = {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'X-Glpi-Csrf-Token': csrfToken
                };

                // Construir URL completa para garantir que o Origin seja enviado
                const registerUrl = pluginUrl + '/front/register.php';

                return fetch(registerUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: headers,
                    body: JSON.stringify(data)
                });
            })
            .then((response) => {
                if (!response.ok) {
                    // Tentar obter detalhes do erro
                    return response.json().then((errorData) => {
                        const errorMessage = errorData.error || errorData.message || response.statusText;
                        console.error('[GLPI PWA] Erro ao registrar token:', response.status, errorMessage);
                        throw new Error(errorMessage);
                    }).catch((parseError) => {
                        // Se não conseguir parsear JSON, usar statusText
                        console.error('[GLPI PWA] Erro ao registrar token:', response.status, response.statusText);
                        throw new Error('Erro ' + response.status + ': ' + response.statusText);
                    });
                }
                return response.json();
            })
            .then((result) => {
                if (!result || !result.success) {
                    console.error('[GLPI PWA] Falha ao registrar token no servidor');
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

