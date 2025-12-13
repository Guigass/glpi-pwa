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
 * Service Worker específico para Firebase Cloud Messaging
 * Este arquivo é usado quando o Firebase precisa de um SW dedicado
 * A configuração é injetada dinamicamente
 */

include('../../../inc/includes.php');

// Obter configuração Firebase
$config = PluginGlpipwaConfig::getAll();

$firebaseConfig = [
    'apiKey' => $config['firebase_api_key'] ?? '',
    'authDomain' => ($config['firebase_project_id'] ?? '') . '.firebaseapp.com',
    'projectId' => $config['firebase_project_id'] ?? '',
    'storageBucket' => ($config['firebase_project_id'] ?? '') . '.appspot.com',
    'messagingSenderId' => $config['firebase_messaging_sender_id'] ?? '',
    'appId' => $config['firebase_app_id'] ?? '',
];

$firebaseConfigJson = json_encode($firebaseConfig);

// Headers para Service Worker
header('Content-Type: application/javascript');
header('Service-Worker-Allowed: /');
header('Cache-Control: no-cache, no-store, must-revalidate');

$swContent = <<<JAVASCRIPT
/**
 * Service Worker específico para Firebase Cloud Messaging
 * Configuração Firebase injetada dinamicamente pelo servidor
 */

importScripts('https://www.gstatic.com/firebasejs/9.17.1/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/9.17.1/firebase-messaging-compat.js');

const FIREBASE_CONFIG = {$firebaseConfigJson};

// Inicializar Firebase se configurado
if (FIREBASE_CONFIG.apiKey) {
    firebase.initializeApp(FIREBASE_CONFIG);
    const messaging = firebase.messaging();

    // ESTRATÉGIA DATA-ONLY: Mensagens agora usam apenas message.data (sem message.notification)
    // O Service Worker é o único responsável por exibir notificações via showNotification()
    // Isso elimina duplicação que ocorria quando FCM exibia automaticamente + SW também exibia
    // Referência: https://firebase.google.com/docs/cloud-messaging/concept-options#notifications_and_data_messages
    messaging.onBackgroundMessage((payload) => {
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

        self.registration.showNotification(notificationTitle, notificationOptions);
    });
}
JAVASCRIPT;

echo $swContent;

