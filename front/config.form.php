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

include('../../../inc/includes.php');

// URL correta do plugin para GLPI 11
$plugin_url = Plugin::getWebDir('glpipwa', true) . '/front/config.form.php';

Html::header(__('PWA Configuration', 'glpipwa'), $plugin_url, 'config', 'plugins');

if (!Session::haveRight('config', UPDATE)) {
    Html::displayRightError();
    exit;
}

// Processar formulário
if (isset($_POST['update'])) {
    
    $config = [];
    
    // Firebase - sanitizar inputs
    $config['firebase_api_key'] = trim($_POST['firebase_api_key'] ?? '');
    $config['firebase_project_id'] = trim($_POST['firebase_project_id'] ?? '');
    $config['firebase_messaging_sender_id'] = trim($_POST['firebase_messaging_sender_id'] ?? '');
    $config['firebase_app_id'] = trim($_POST['firebase_app_id'] ?? '');
    $config['firebase_vapid_key'] = trim($_POST['firebase_vapid_key'] ?? '');
    
    // Service Account - processar upload de JSON
    if (isset($_FILES['firebase_service_account_json']) && $_FILES['firebase_service_account_json']['error'] === UPLOAD_ERR_OK) {
        $jsonContent = file_get_contents($_FILES['firebase_service_account_json']['tmp_name']);
        $jsonData = json_decode($jsonContent, true);
        
        if ($jsonData && isset($jsonData['client_email']) && isset($jsonData['private_key'])) {
            // Validar formato
            if (filter_var($jsonData['client_email'], FILTER_VALIDATE_EMAIL)) {
                $config['firebase_service_account_json'] = $jsonContent;
            } else {
                Session::addMessageAfterRedirect(__('Invalid Service Account JSON: invalid email', 'glpipwa'), true, ERROR);
            }
        } else {
            Session::addMessageAfterRedirect(__('Invalid Service Account JSON format', 'glpipwa'), true, ERROR);
        }
    } else {
        // Se não foi feito upload, manter o JSON existente (não limpar)
        $currentConfig = PluginGlpipwaConfig::getAll();
        $config['firebase_service_account_json'] = $currentConfig['firebase_service_account_json'] ?? '';
    }
    
    // PWA - sanitizar inputs
    $config['pwa_name'] = trim($_POST['pwa_name'] ?? 'GLPI Service Desk');
    $config['pwa_short_name'] = trim($_POST['pwa_short_name'] ?? 'GLPI');
    $config['pwa_theme_color'] = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['pwa_theme_color'] ?? '') ? $_POST['pwa_theme_color'] : '#0d6efd';
    $config['pwa_background_color'] = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['pwa_background_color'] ?? '') ? $_POST['pwa_background_color'] : '#ffffff';
    $config['pwa_start_url'] = filter_var($_POST['pwa_start_url'] ?? '/', FILTER_SANITIZE_URL);
    $config['pwa_display'] = in_array($_POST['pwa_display'] ?? 'standalone', ['standalone', 'fullscreen', 'minimal-ui', 'browser']) ? $_POST['pwa_display'] : 'standalone';
    $config['pwa_orientation'] = in_array($_POST['pwa_orientation'] ?? 'any', ['any', 'portrait', 'landscape']) ? $_POST['pwa_orientation'] : 'any';
    
    // Salvar configurações
    PluginGlpipwaConfig::setMultiple($config);
    Session::addMessageAfterRedirect(__('Settings saved successfully', 'glpipwa'), true, INFO);
    
    // Upload de ícones
    if (isset($_FILES['icon_192']) && !empty($_FILES['icon_192']['name'])) {
        if ($_FILES['icon_192']['error'] === UPLOAD_ERR_OK) {
            $result = PluginGlpipwaIcon::upload($_FILES['icon_192'], 192);
            if ($result['success']) {
                Session::addMessageAfterRedirect($result['message'], true, INFO);
            } else {
                Session::addMessageAfterRedirect($result['message'], true, ERROR);
            }
        } else {
            // Tratar erros de upload do PHP
            $error_messages = [
                UPLOAD_ERR_INI_SIZE => __('Icon 192x192: File exceeds upload_max_filesize directive', 'glpipwa'),
                UPLOAD_ERR_FORM_SIZE => __('Icon 192x192: File exceeds MAX_FILE_SIZE directive', 'glpipwa'),
                UPLOAD_ERR_PARTIAL => __('Icon 192x192: File was only partially uploaded', 'glpipwa'),
                UPLOAD_ERR_NO_FILE => __('Icon 192x192: No file was uploaded', 'glpipwa'),
                UPLOAD_ERR_NO_TMP_DIR => __('Icon 192x192: Missing temporary folder', 'glpipwa'),
                UPLOAD_ERR_CANT_WRITE => __('Icon 192x192: Failed to write file to disk', 'glpipwa'),
                UPLOAD_ERR_EXTENSION => __('Icon 192x192: A PHP extension stopped the file upload', 'glpipwa'),
            ];
            $message = $error_messages[$_FILES['icon_192']['error']] ?? __('Icon 192x192: Unknown upload error', 'glpipwa');
            Session::addMessageAfterRedirect($message, true, ERROR);
        }
    }
    
    if (isset($_FILES['icon_512']) && !empty($_FILES['icon_512']['name'])) {
        if ($_FILES['icon_512']['error'] === UPLOAD_ERR_OK) {
            $result = PluginGlpipwaIcon::upload($_FILES['icon_512'], 512);
            if ($result['success']) {
                Session::addMessageAfterRedirect($result['message'], true, INFO);
            } else {
                Session::addMessageAfterRedirect($result['message'], true, ERROR);
            }
        } else {
            // Tratar erros de upload do PHP
            $error_messages = [
                UPLOAD_ERR_INI_SIZE => __('Icon 512x512: File exceeds upload_max_filesize directive', 'glpipwa'),
                UPLOAD_ERR_FORM_SIZE => __('Icon 512x512: File exceeds MAX_FILE_SIZE directive', 'glpipwa'),
                UPLOAD_ERR_PARTIAL => __('Icon 512x512: File was only partially uploaded', 'glpipwa'),
                UPLOAD_ERR_NO_FILE => __('Icon 512x512: No file was uploaded', 'glpipwa'),
                UPLOAD_ERR_NO_TMP_DIR => __('Icon 512x512: Missing temporary folder', 'glpipwa'),
                UPLOAD_ERR_CANT_WRITE => __('Icon 512x512: Failed to write file to disk', 'glpipwa'),
                UPLOAD_ERR_EXTENSION => __('Icon 512x512: A PHP extension stopped the file upload', 'glpipwa'),
            ];
            $message = $error_messages[$_FILES['icon_512']['error']] ?? __('Icon 512x512: Unknown upload error', 'glpipwa');
            Session::addMessageAfterRedirect($message, true, ERROR);
        }
    }
    
    Html::redirect($plugin_url);
}

// Teste de notificação
if (isset($_POST['test_notification'])) {
    $notification = new PluginGlpipwaNotificationPush();
    $users_id = Session::getLoginUserID();
    
    $result = $notification->sendToUser(
        $users_id,
        __('Test Notification', 'glpipwa'),
        __('This is a test notification from the PWA plugin', 'glpipwa'),
        ['type' => 'test']
    );
    
    if ($result) {
        Session::addMessageAfterRedirect(__('Test notification sent', 'glpipwa'), true, INFO);
    } else {
        Session::addMessageAfterRedirect(__('Error sending test notification', 'glpipwa'), true, ERROR);
    }
    
    Html::redirect($plugin_url);
}

// Obter configurações atuais
$config = PluginGlpipwaConfig::getAll();

echo "<div class='center'>";
echo "<form method='post' action='" . $plugin_url . "' enctype='multipart/form-data'>";
echo "<table class='tab_cadre_fixe'>";

// Seção Firebase
echo "<tr class='tab_bg_1'><th colspan='2'>" . __('Firebase Configuration', 'glpipwa') . "</th></tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('API Key', 'glpipwa') . "</td>";
echo "<td><input type='text' name='firebase_api_key' value='" . htmlspecialchars($config['firebase_api_key'] ?? '') . "' size='60'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Project ID', 'glpipwa') . "</td>";
echo "<td><input type='text' name='firebase_project_id' value='" . htmlspecialchars($config['firebase_project_id'] ?? '') . "' size='60'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Messaging Sender ID', 'glpipwa') . "</td>";
echo "<td><input type='text' name='firebase_messaging_sender_id' value='" . htmlspecialchars($config['firebase_messaging_sender_id'] ?? '') . "' size='60'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('App ID', 'glpipwa') . "</td>";
echo "<td><input type='text' name='firebase_app_id' value='" . htmlspecialchars($config['firebase_app_id'] ?? '') . "' size='60'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('VAPID Key', 'glpipwa') . "</td>";
echo "<td><input type='text' name='firebase_vapid_key' value='" . htmlspecialchars($config['firebase_vapid_key'] ?? '') . "' size='60'></td>";
echo "</tr>";

// Seção Service Account
echo "<tr class='tab_bg_1'><th colspan='2'>" . __('Firebase Service Account (FCM v1)', 'glpipwa') . "</th></tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Service Account JSON File', 'glpipwa') . "</td>";
echo "<td><input type='file' name='firebase_service_account_json' accept='application/json'><br>";
echo "<small>" . __('Upload the service account JSON file from Firebase Console', 'glpipwa') . "</small></td>";
echo "</tr>";

// Seção PWA
echo "<tr class='tab_bg_1'><th colspan='2'>" . __('PWA Configuration', 'glpipwa') . "</th></tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Application Name', 'glpipwa') . "</td>";
echo "<td><input type='text' name='pwa_name' value='" . htmlspecialchars($config['pwa_name'] ?? 'GLPI Service Desk') . "' size='60'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Short Name', 'glpipwa') . "</td>";
echo "<td><input type='text' name='pwa_short_name' value='" . htmlspecialchars($config['pwa_short_name'] ?? 'GLPI') . "' size='60'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Theme Color', 'glpipwa') . "</td>";
echo "<td><input type='color' name='pwa_theme_color' value='" . htmlspecialchars($config['pwa_theme_color'] ?? '#0d6efd') . "'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Background Color', 'glpipwa') . "</td>";
echo "<td><input type='color' name='pwa_background_color' value='" . htmlspecialchars($config['pwa_background_color'] ?? '#ffffff') . "'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Start URL', 'glpipwa') . "</td>";
echo "<td><input type='text' name='pwa_start_url' value='" . htmlspecialchars($config['pwa_start_url'] ?? '/') . "' size='60'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Display Mode', 'glpipwa') . "</td>";
echo "<td>";
echo "<select name='pwa_display'>";
$displays = ['standalone' => __('Standalone', 'glpipwa'), 'fullscreen' => __('Fullscreen', 'glpipwa'), 'minimal-ui' => __('Minimal UI', 'glpipwa'), 'browser' => __('Browser', 'glpipwa')];
foreach ($displays as $value => $label) {
    $selected = ($config['pwa_display'] ?? 'standalone') === $value ? 'selected' : '';
    echo "<option value='$value' $selected>$label</option>";
}
echo "</select>";
echo "</td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Orientation', 'glpipwa') . "</td>";
echo "<td>";
echo "<select name='pwa_orientation'>";
$orientations = ['any' => __('Any', 'glpipwa'), 'portrait' => __('Portrait', 'glpipwa'), 'landscape' => __('Landscape', 'glpipwa')];
foreach ($orientations as $value => $label) {
    $selected = ($config['pwa_orientation'] ?? 'any') === $value ? 'selected' : '';
    echo "<option value='$value' $selected>$label</option>";
}
echo "</select>";
echo "</td>";
echo "</tr>";

// Seção Ícones
echo "<tr class='tab_bg_1'><th colspan='2'>" . __('Icons', 'glpipwa') . "</th></tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Icon 192x192', 'glpipwa') . "</td>";
echo "<td><input type='file' name='icon_192' accept='image/png'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Icon 512x512', 'glpipwa') . "</td>";
echo "<td><input type='file' name='icon_512' accept='image/png'></td>";
echo "</tr>";

// Botões
echo "<tr class='tab_bg_2'>";
echo "<td colspan='2' class='center'>";
echo "<input type='submit' name='update' value='" . __('Save', 'glpipwa') . "' class='submit'>";
echo "&nbsp;";
echo "<input type='submit' name='test_notification' value='" . __('Send Test Notification', 'glpipwa') . "' class='submit'>";
echo "</td>";
echo "</tr>";

echo "</table>";
Html::closeForm();
echo "</div>";

Html::footer();

