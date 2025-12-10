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
        const notificationOptions = {
            body: payload.notification?.body || '',
            icon: payload.notification?.icon || '/pics/logos/logo-GLPI-250-white.png',
            badge: '/pics/logos/logo-GLPI-250-white.png',
            data: payload.data || {},
            tag: payload.data?.ticket_id || 'glpi-notification',
            requireInteraction: false,
        };

        self.registration.showNotification(notificationTitle, notificationOptions);
    });
}
JAVASCRIPT;

echo $swContent;

