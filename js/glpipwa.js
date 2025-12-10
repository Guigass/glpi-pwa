/**
 * GLPI PWA Plugin - Injeção de Manifest e Registro de Service Worker
 * Este arquivo é carregado automaticamente pelo hook add_javascript do GLPI
 */

(function() {
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
            document.addEventListener('DOMContentLoaded', function() {
                registerSW();
            });
        } else {
            registerSW();
        }

        function registerSW() {
            navigator.serviceWorker.register(swUrl, {
                scope: '/'
            })
            .catch(function(error) {
                console.error('[GLPI PWA] Erro ao registrar Service Worker:', error);
                // Fallback: tentar registrar sw.php se sw-proxy.php falhar
                navigator.serviceWorker.register(pluginUrl + '/front/sw.php', {
                    scope: '/'
                }).catch(function(fallbackError) {
                    console.error('[GLPI PWA] Erro ao registrar Service Worker (fallback):', fallbackError);
                });
            });
        }
    }

    // Executar quando o DOM estiver pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            injectManifest();
            registerServiceWorker();
        });
    } else {
        injectManifest();
        registerServiceWorker();
    }
})();

