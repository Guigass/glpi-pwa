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
    const DEVICE_ID_STORAGE_KEY = 'glpipwa_device_id';

    /**
     * Gera ou obtém device_id do localStorage
     * Usa crypto.randomUUID() se disponível, senão usa crypto.getRandomValues() com RFC 4122
     * 
     * @return {string} UUID v4 do dispositivo
     */
    function getOrCreateDeviceId() {
        try {
            if (typeof Storage !== 'undefined' && localStorage) {
                let deviceId = localStorage.getItem(DEVICE_ID_STORAGE_KEY);

                if (!deviceId) {
                    // Usar crypto.randomUUID() se disponível (MDN: https://developer.mozilla.org/en-US/docs/Web/API/Crypto/randomUUID)
                    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
                        deviceId = crypto.randomUUID();
                    } else if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
                        // Fallback: gerar UUID v4 usando crypto.getRandomValues() (RFC 4122)
                        // Referência: https://developer.mozilla.org/en-US/docs/Web/API/Crypto/getRandomValues
                        const array = new Uint8Array(16);
                        crypto.getRandomValues(array);

                        // Ajustar versão (4) e variante (10)
                        array[6] = (array[6] & 0x0f) | 0x40; // versão 4
                        array[8] = (array[8] & 0x3f) | 0x80; // variante 10

                        // Converter para string UUID format
                        const hex = Array.from(array, (byte) =>
                            byte.toString(16).padStart(2, '0')
                        ).join('');
                        deviceId = [
                            hex.slice(0, 8),
                            hex.slice(8, 12),
                            hex.slice(12, 16),
                            hex.slice(16, 20),
                            hex.slice(20, 32),
                        ].join('-');
                    } else {
                        // Fallback final: gerar UUID-like (não é RFC 4122, mas melhor que nada)
                        deviceId = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
                            const r = Math.random() * 16 | 0;
                            const v = c === 'x' ? r : (r & 0x3 | 0x8);
                            return v.toString(16);
                        });
                    }
                    localStorage.setItem(DEVICE_ID_STORAGE_KEY, deviceId);
                }

                return deviceId;
            }
        } catch (e) {
            console.warn('[GLPI PWA] Não foi possível acessar localStorage para device_id:', e);
        }

        // Fallback: gerar UUID temporário (não será persistido)
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

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
     * Remove device_id do localStorage
     * Usado quando o dispositivo não é mais encontrado no servidor
     * Isso força a geração de um novo device_id na próxima vez que getOrCreateDeviceId() for chamado
     */
    function clearStoredDeviceId() {
        try {
            if (typeof Storage !== 'undefined' && localStorage) {
                const oldDeviceId = localStorage.getItem(DEVICE_ID_STORAGE_KEY);

                // Limpar device_id
                localStorage.removeItem(DEVICE_ID_STORAGE_KEY);

                // Limpar token FCM e timestamp
                const storedToken = localStorage.getItem(FCM_TOKEN_STORAGE_KEY);
                if (storedToken) {
                    localStorage.removeItem(FCM_TOKEN_STORAGE_KEY);
                    localStorage.removeItem(FCM_TOKEN_TIMESTAMP_KEY);

                    // Limpar estado do token (glpipwa_token_state_{token})
                    const registrationStateKey = 'glpipwa_token_state_' + storedToken;
                    localStorage.removeItem(registrationStateKey);

                    console.info('[GLPI PWA] device_id, token FCM e estado removidos do localStorage (dispositivo não encontrado no servidor). Um novo será gerado na próxima vez.', oldDeviceId ? `Device ID anterior: ${oldDeviceId}` : '');
                } else {
                    console.info('[GLPI PWA] device_id removido do localStorage (dispositivo não encontrado no servidor). Um novo será gerado na próxima vez.', oldDeviceId ? `Device ID anterior: ${oldDeviceId}` : '');
                }
            }
        } catch (e) {
            // localStorage pode não estar disponível
            console.warn('[GLPI PWA] Não foi possível limpar dados do localStorage:', e);
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
     * Verifica se deve registrar o token FCM baseado no modo de execução
     * 
     * NOTA: Esta função é usada apenas para verificar se deve registrar novamente
     * quando as condições mudam (ex: PWA instalado/desinstalado).
     * 
     * O registro inicial do token sempre acontece quando o token é obtido do Firebase,
     * independente de estar como PWA ou não, pois o usuário já deu permissão.
     * 
     * @return {boolean} true se deve registrar (quando está como PWA), false caso contrário
     */
    function shouldRegisterToken() {
        // Registra apenas se estiver rodando como PWA instalado
        // Mas o registro inicial sempre acontece quando o token é obtido
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
                    const installingWorker = registration.installing;
                    installingWorker.addEventListener('statechange', function (event) {
                        // Usar event.target ou a referência capturada para evitar null
                        const worker = event.target || installingWorker;
                        if (worker && worker.state === 'activated') {
                            resolve(registration);
                        }
                    });
                    return;
                }

                // Se está waiting, aguardar statechange
                if (registration.waiting) {
                    const waitingWorker = registration.waiting;
                    waitingWorker.addEventListener('statechange', function (event) {
                        // Usar event.target ou a referência capturada para evitar null
                        const worker = event.target || waitingWorker;
                        if (worker && worker.state === 'activated') {
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
                    console.warn('[GLPI PWA] checkAndRegisterTokenIfNeeded: Token vazio');
                    return;
                }

                const shouldRegister = shouldRegisterToken();

                const registrationStateKey = 'glpipwa_token_state_' + token;

                try {
                    const storedState = localStorage.getItem(registrationStateKey);

                    // storedState contém: "registered|isPWA" ou null
                    // Exemplo: "registered|1" significa: registrado quando era PWA
                    const storedParts = storedState ? storedState.split('|') : [];
                    const wasRegistered = storedParts[0] === 'registered';
                    const storedIsPWA = storedParts[1] === '1';

                    // Verificar se as condições atuais requerem registro
                    const currentIsPWA = isPWA();
                    const conditionsChanged = storedIsPWA !== currentIsPWA;

                    if (shouldRegister) {
                        // Deve registrar: verificar se precisa fazer registro
                        if (!wasRegistered || conditionsChanged) {
                            // Nunca foi registrado ou condições mudaram (instalou/desinstalou PWA) - registrar
                            registerToken(token).then((success) => {
                                if (success) {
                                    // Só marcar como registrado se o registro foi bem-sucedido
                                    const newState = 'registered|' + (currentIsPWA ? '1' : '0');
                                    localStorage.setItem(registrationStateKey, newState);
                                } else {
                                    console.warn('[GLPI PWA] Token não foi registrado com sucesso, não marcando como registrado');
                                    // Remover flag se existir para tentar novamente na próxima vez
                                    localStorage.removeItem(registrationStateKey);
                                }
                            }).catch((error) => {
                                console.error('[GLPI PWA] Erro ao registrar token:', error);
                                // Remover flag se existir para tentar novamente na próxima vez
                                localStorage.removeItem(registrationStateKey);
                            });
                        }
                        // Se já estava registrado e condições não mudaram, não precisa fazer nada
                    } else {
                        // Não deve registrar AGORA (não está como PWA)
                        // MAS: Se o token já foi registrado anteriormente, manter a flag
                        // Isso evita tentar registrar novamente toda vez que a página carrega
                        if (wasRegistered) {
                            // NÃO remover a flag - o token já foi registrado no servidor
                            // Apenas atualizar o estado para refletir que não está como PWA agora
                            const newState = 'registered|' + (currentIsPWA ? '1' : '0');
                            localStorage.setItem(registrationStateKey, newState);
                        }
                    }
                } catch (e) {
                    console.error('[GLPI PWA] Erro ao verificar estado do token:', e);
                    // Se localStorage falhar, tentar registrar mesmo assim se deve registrar
                    if (shouldRegister) {
                        registerToken(token).catch((error) => {
                            console.error('[GLPI PWA] Erro ao registrar token após falha no localStorage:', error);
                        });
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

                    // SEMPRE registrar o token quando obtido do Firebase
                    // Independente de estar como PWA ou não, pois o usuário já deu permissão
                    const registrationStateKey = 'glpipwa_token_state_' + currentToken;
                    const storedState = localStorage.getItem(registrationStateKey);
                    const isRegistered = storedState && storedState.split('|')[0] === 'registered';

                    if (!isRegistered || needsRegistration) {
                        registerToken(currentToken).then((success) => {
                            if (success) {
                                const currentIsPWA = isPWA();
                                const newState = 'registered|' + (currentIsPWA ? '1' : '0');
                                localStorage.setItem(registrationStateKey, newState);
                            } else {
                                console.warn('[GLPI PWA] processToken: Falha ao registrar token');
                            }
                        }).catch((error) => {
                            console.error('[GLPI PWA] processToken: Erro ao registrar token:', error);
                        });
                    } else {
                        // Token já registrado, apenas verificar se precisa atualizar
                        checkAndRegisterTokenIfNeeded(currentToken);
                    }

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
                    // IMPORTANTE: Verificar se deve registrar mesmo para token armazenado
                    // Isso garante que se as condições mudaram (ex: PWA instalado), o token será registrado
                    // Também força o registro se a flag não existir (token nunca foi registrado)
                    const registrationStateKey = 'glpipwa_token_state_' + storedToken;
                    const storedState = localStorage.getItem(registrationStateKey);
                    const isRegistered = storedState && storedState.split('|')[0] === 'registered';

                    // Função auxiliar para continuar o fluxo após verificar registro
                    const continueFlow = () => {
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
                    };

                    if (!isRegistered) {
                        // Se o token não está registrado, tentar registrar mesmo que não esteja como PWA
                        // Isso garante que tokens existentes sejam registrados no servidor
                        // IMPORTANTE: Aguardar o registro antes de continuar
                        return registerToken(storedToken).then((success) => {
                            if (success) {
                                const currentIsPWA = isPWA();
                                const newState = 'registered|' + (currentIsPWA ? '1' : '0');
                                localStorage.setItem(registrationStateKey, newState);
                            } else {
                                console.warn('[GLPI PWA] Falha ao registrar token existente no localStorage - tentando novamente na próxima vez');
                                // Não remover a flag aqui, deixar para tentar novamente
                            }
                            // Continuar o fluxo normalmente após tentar registrar
                            return continueFlow();
                        }).catch((error) => {
                            console.error('[GLPI PWA] Erro ao registrar token existente:', error);
                            // Continuar mesmo com erro, retornando o token armazenado
                            return continueFlow();
                        });
                    } else {
                        // Token já está marcado como registrado, verificar se precisa atualizar
                        checkAndRegisterTokenIfNeeded(storedToken);
                        // Continuar o fluxo normalmente
                        return continueFlow();
                    }
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
                                newWorker.addEventListener('statechange', (event) => {
                                    // Usar event.target ou a referência capturada para evitar null
                                    const worker = event.target || newWorker;
                                    if (worker && worker.state === 'activated') {
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

        // Listener para mensagens em foreground
        // ESTRATÉGIA DATA-ONLY: Não exibir notificação quando app está em foreground
        // Com data-only, mensagens em foreground não devem exibir notificação para evitar
        // interrupção do usuário. O Service Worker exibirá quando app estiver em background.
        // Opcionalmente, pode-se atualizar badge/contador aqui (feature futura).
        // Referência: https://firebase.google.com/docs/cloud-messaging/js/receive#handle_messages_when_your_web_app_is_in_the_foreground
        try {
            messaging.onMessage((payload) => {
                // ESTRATÉGIA DATA-ONLY: Não exibir notificação em foreground
                // Apenas logar para debug (pode ser removido em produção)
                // TODO: Implementar atualização de badge/contador se necessário (feature futura)

                // Fallback temporário para compatibilidade com payload antigo (com message.notification)
                // TODO: Remover fallback após migração completa (data de remoção: 2025-06-01)
                const hasDataOnly = payload.data?.title && payload.data?.body;
                const hasLegacyNotification = payload.notification?.title && payload.notification?.body;

                // Não exibir notificação - usuário já está usando o app
                // Em versões futuras, pode-se atualizar badge/contador aqui
            });
        } catch (error) {
            console.error('[GLPI PWA] Erro ao registrar onMessage', error);
        }
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
     * 
     * @param {string} token Token FCM para registrar
     * @return {Promise<boolean>} Promise que resolve com true se o registro foi bem-sucedido, false caso contrário
     */
    function registerToken(token) {
        if (!token || typeof token !== 'string' || token.length === 0) {
            console.error('[GLPI PWA] Token inválido para registro');
            return Promise.resolve(false);
        }

        const pluginUrl = getPluginUrl();

        // Primeiro obter token CSRF fresco
        return getNewCSRFToken()
            .then((csrfToken) => {
                const deviceId = getOrCreateDeviceId();
                const data = {
                    token: token,
                    device_id: deviceId,
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
            .catch((error) => {
                console.error('[GLPI PWA] registerToken: Erro ao obter token CSRF:', error);
                throw error;
            })
            .then((response) => {
                if (!response.ok) {
                    // Tentar obter detalhes do erro
                    return response.text().then((text) => {
                        console.error('[GLPI PWA] registerToken: Resposta de erro (texto):', text);
                        // Tentar parsear JSON para obter mensagem de erro específica
                        let errorMessage = response.statusText;
                        try {
                            const errorData = JSON.parse(text);
                            errorMessage = errorData.error || errorData.message || response.statusText;
                        } catch (parseError) {
                            // Se não conseguir parsear JSON, usar statusText
                            // parseError é um SyntaxError do JSON.parse, não um erro intencional
                            console.error('[GLPI PWA] registerToken: Erro ao parsear JSON de erro:', parseError);
                        }
                        console.error('[GLPI PWA] Erro ao registrar token:', response.status, errorMessage);
                        console.error('[GLPI PWA] Resposta completa:', text);
                        throw new Error(errorMessage);
                    });
                }
                return response.text().then((text) => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('[GLPI PWA] registerToken: Erro ao parsear JSON:', e, 'Texto:', text);
                        throw new Error('Resposta inválida do servidor');
                    }
                });
            })
            .then((result) => {
                if (result && result.success) {
                    // Se o servidor retornou um device_id (gerado no servidor), armazenar no localStorage
                    if (result.device_id && typeof result.device_id === 'string') {
                        try {
                            localStorage.setItem(DEVICE_ID_STORAGE_KEY, result.device_id);
                        } catch (e) {
                            console.warn('[GLPI PWA] Não foi possível armazenar device_id retornado pelo servidor:', e);
                        }
                    }
                    return true;
                } else {
                    const errorMsg = result?.error || result?.message || 'Resposta sem sucesso';
                    console.error('[GLPI PWA] Falha ao registrar token no servidor - resposta:', result);
                    console.error('[GLPI PWA] Mensagem de erro:', errorMsg);
                    return false;
                }
            })
            .catch((error) => {
                console.error('[GLPI PWA] Erro ao registrar token no servidor:', error);
                console.error('[GLPI PWA] Mensagem:', error.message);
                // Não fazer throw para não interromper o fluxo, mas retornar false
                return false;
            });
    }

    /**
     * Atualiza last_seen_at quando GLPI é aberto
     * Se a URL contém ticket_id ou ticketId é fornecido como parâmetro, também atualiza last_seen_ticket_id e last_seen_ticket_updated_at
     * 
     * @param {number|null} ticketIdFromParam - ticket_id opcional (ex: da notificação clicada). Se fornecido, usa este ao invés de ler da URL
     */
    function updateLastSeen(ticketIdFromParam = null) {
        const deviceId = getOrCreateDeviceId();
        if (!deviceId) {
            return;
        }

        // Usar ticket_id do parâmetro se fornecido, senão verificar se URL contém ticket_id
        let ticketId = ticketIdFromParam;
        if (ticketId === null) {
            const urlParams = new URLSearchParams(window.location.search);
            ticketId = urlParams.get('id');
        }

        // Verificar se estamos em uma página de ticket ou se ticketId foi fornecido
        const isTicketPage = (window.location.pathname.includes('/front/ticket.form.php') && ticketId) || ticketIdFromParam !== null;

        // Obter token CSRF
        getNewCSRFToken()
            .then((csrfToken) => {
                const pluginUrl = getPluginUrl();
                const data = {
                    device_id: deviceId,
                    _glpi_csrf_token: csrfToken
                };

                // Se estamos em uma página de ticket ou ticketId foi fornecido, incluir ticket_id
                if (isTicketPage && ticketId) {
                    data.ticket_id = parseInt(ticketId, 10);
                }

                const headers = {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'X-Glpi-Csrf-Token': csrfToken
                };

                const updateUrl = pluginUrl + '/front/update-last-seen.php';

                return fetch(updateUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: headers,
                    body: JSON.stringify(data)
                });
            })
            .then((response) => {
                if (!response.ok) {
                    // Se for 404, significa que o dispositivo não foi encontrado no servidor
                    // Limpar device_id do localStorage para que um novo seja gerado na próxima vez
                    if (response.status === 404) {
                        console.warn('[GLPI PWA] Dispositivo não encontrado no servidor (404). Limpando device_id do localStorage para gerar um novo.');
                        clearStoredDeviceId();
                        // Tentar parsear JSON para log adicional, mas não é crítico
                        return response.json().catch(() => null).then((errorData) => {
                            if (errorData && errorData.code === 'DEVICE_NOT_FOUND') {
                                console.info('[GLPI PWA] Código de erro confirmado: DEVICE_NOT_FOUND');
                            }
                            return null;
                        });
                    }
                    console.warn('[GLPI PWA] Erro ao atualizar last_seen:', response.status);
                    return null;
                }
                return response.json();
            })
            .then((result) => {
                if (result && result.success) {
                    // Sucesso - silenciosamente ignora
                } else if (result && !result.success) {
                    // Verificar se é erro de dispositivo não encontrado (caso não tenha sido tratado acima)
                    if (result.code === 'DEVICE_NOT_FOUND' || result.error === 'Dispositivo não encontrado') {
                        console.warn('[GLPI PWA] Dispositivo não encontrado no servidor. Limpando device_id do localStorage.');
                        clearStoredDeviceId();
                    } else {
                        console.warn('[GLPI PWA] Falha ao atualizar last_seen:', result);
                    }
                }
            })
            .catch((error) => {
                // Silenciosamente ignora erros (pode não estar autenticado ainda, etc)
            });
    }

    /**
     * Fecha todas as notificações do Service Worker quando GLPI é aberto
     */
    function closeAllNotifications() {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.ready.then((registration) => {
                registration.getNotifications().then((notifications) => {
                    notifications.forEach((notification) => {
                        notification.close();
                    });
                });
            }).catch((error) => {
                // Silenciosamente ignora erros
            });
        }
    }

    // Executar quando o DOM estiver pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            injectManifest();
            registerServiceWorker();

            // Aguardar um pouco para garantir que a sessão está carregada
            setTimeout(() => {
                updateLastSeen();
                closeAllNotifications();
            }, 1000);
        });
    } else {
        injectManifest();
        registerServiceWorker();

        // Aguardar um pouco para garantir que a sessão está carregada
        setTimeout(() => {
            updateLastSeen();
            closeAllNotifications();
        }, 1000);
    }

    // Atualizar last_seen quando a página é focada (usuário volta para a aba)
    window.addEventListener('focus', () => {
        updateLastSeen();
    });

    // Listener para mensagens do Service Worker (para atualizar last_seen quando notificação é clicada)
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', (event) => {
            if (event.data && event.data.type === 'UPDATE_LAST_SEEN') {
                const ticketId = event.data.ticket_id;
                // Usar ticket_id da notificação ao invés de ler da URL
                // Isso garante que o ticket correto seja marcado como visualizado
                updateLastSeen(ticketId ? parseInt(ticketId, 10) : null);
            }
        });
    }
})();

