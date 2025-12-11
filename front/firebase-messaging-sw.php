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

    messaging.onBackgroundMessage((payload) => {
        const notificationTitle = payload.notification?.title || 'GLPI';
        
        // Usar notification_id único se disponível, senão criar um baseado em timestamp
        // Isso garante que cada notificação seja exibida separadamente
        const notificationTag = payload.data?.notification_id || 
                               `glpi-${payload.data?.ticket_id || 'notification'}-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
        
        const notificationOptions = {
            body: payload.notification?.body || '',
            icon: payload.notification?.icon || '/pics/glpi.png?v1',
            badge: '/pics/glpi.png?v1',
            data: payload.data || {},
            tag: notificationTag, // Tag único para cada notificação
            requireInteraction: false,
            timestamp: Date.now(), // Timestamp para ordenação
        };

        self.registration.showNotification(notificationTitle, notificationOptions);
    });
}
JAVASCRIPT;

echo $swContent;

