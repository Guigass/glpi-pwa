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
    
    // PWA - Identidade
    $config['pwa_name'] = trim($_POST['pwa_name'] ?? 'GLPI Service Desk');
    $config['pwa_short_name'] = trim($_POST['pwa_short_name'] ?? 'GLPI');
    $config['pwa_description'] = trim($_POST['pwa_description'] ?? '');
    $config['pwa_lang'] = preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $_POST['pwa_lang'] ?? 'pt-BR') ? $_POST['pwa_lang'] : 'pt-BR';
    $config['pwa_dir'] = in_array($_POST['pwa_dir'] ?? 'ltr', ['ltr', 'rtl']) ? $_POST['pwa_dir'] : 'ltr';
    
    // PWA - Navegação
    $config['pwa_start_url'] = preg_match('/^\/.*$/', $_POST['pwa_start_url'] ?? '/index.php?from=pwa') ? $_POST['pwa_start_url'] : '/index.php?from=pwa';
    $config['pwa_scope'] = preg_match('/^\/.*$/', $_POST['pwa_scope'] ?? '/') ? $_POST['pwa_scope'] : '/';
    
    // PWA - Aparência
    $config['pwa_theme_color'] = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['pwa_theme_color'] ?? '') ? $_POST['pwa_theme_color'] : '#0d6efd';
    $config['pwa_background_color'] = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['pwa_background_color'] ?? '') ? $_POST['pwa_background_color'] : '#ffffff';
    $config['pwa_display'] = in_array($_POST['pwa_display'] ?? 'standalone', ['standalone', 'fullscreen', 'minimal-ui', 'browser']) ? $_POST['pwa_display'] : 'standalone';
    $config['pwa_orientation'] = in_array($_POST['pwa_orientation'] ?? 'any', ['any', 'portrait', 'landscape']) ? $_POST['pwa_orientation'] : 'any';
    
    // PWA - Categories
    if (isset($_POST['pwa_categories']) && is_array($_POST['pwa_categories'])) {
        $config['pwa_categories'] = json_encode($_POST['pwa_categories']);
    } else {
        $config['pwa_categories'] = '';
    }
    
    // PWA - Shortcuts padrão
    $config['pwa_shortcuts_default_enabled'] = isset($_POST['pwa_shortcuts_default_enabled']) ? '1' : '0';
    
    // PWA - Shortcuts customizados
    if (isset($_POST['pwa_shortcuts_custom'])) {
        $custom_shortcuts = json_decode($_POST['pwa_shortcuts_custom'], true);
        if (is_array($custom_shortcuts)) {
            $config['pwa_shortcuts_custom'] = $_POST['pwa_shortcuts_custom'];
        } else {
            $config['pwa_shortcuts_custom'] = '';
        }
    } else {
        $config['pwa_shortcuts_custom'] = '';
    }
    
    // PWA - Edge Side Panel
    $edge_width = isset($_POST['pwa_edge_panel_width']) ? (int)$_POST['pwa_edge_panel_width'] : 420;
    $config['pwa_edge_panel_width'] = ($edge_width >= 0 && $edge_width <= 1000) ? $edge_width : 420;
    
    // PWA - Related Applications
    $config['pwa_related_app_url'] = !empty($_POST['pwa_related_app_url']) && filter_var($_POST['pwa_related_app_url'], FILTER_VALIDATE_URL) ? $_POST['pwa_related_app_url'] : '';
    $config['pwa_prefer_related'] = isset($_POST['pwa_prefer_related']) ? '1' : '0';
    
    // Validar configurações antes de salvar
    if (!PluginGlpipwaConfig::validatePWAConfig($config)) {
        Session::addMessageAfterRedirect(__('Invalid PWA configuration. Please check your inputs', 'glpipwa'), true, ERROR);
        Html::redirect($plugin_url);
        exit;
    }
    
    // Salvar configurações
    PluginGlpipwaConfig::setMultiple($config);
    Session::addMessageAfterRedirect(__('Settings saved successfully', 'glpipwa'), true, INFO);
    
    // Upload de ícone base (gera todos os tamanhos automaticamente)
    if (isset($_FILES['icon_base']) && !empty($_FILES['icon_base']['name'])) {
        if ($_FILES['icon_base']['error'] === UPLOAD_ERR_OK) {
            $result = PluginGlpipwaIcon::uploadBase($_FILES['icon_base']);
            if ($result['success']) {
                Session::addMessageAfterRedirect($result['message'], true, INFO);
            } else {
                Session::addMessageAfterRedirect($result['message'], true, ERROR);
            }
        } else {
            // Tratar erros de upload do PHP
            $error_messages = [
                UPLOAD_ERR_INI_SIZE => __('Icon base: File exceeds upload_max_filesize directive', 'glpipwa'),
                UPLOAD_ERR_FORM_SIZE => __('Icon base: File exceeds MAX_FILE_SIZE directive', 'glpipwa'),
                UPLOAD_ERR_PARTIAL => __('Icon base: File was only partially uploaded', 'glpipwa'),
                UPLOAD_ERR_NO_FILE => __('Icon base: No file was uploaded', 'glpipwa'),
                UPLOAD_ERR_NO_TMP_DIR => __('Icon base: Missing temporary folder', 'glpipwa'),
                UPLOAD_ERR_CANT_WRITE => __('Icon base: Failed to write file to disk', 'glpipwa'),
                UPLOAD_ERR_EXTENSION => __('Icon base: A PHP extension stopped the file upload', 'glpipwa'),
            ];
            $message = $error_messages[$_FILES['icon_base']['error']] ?? __('Icon base: Unknown upload error', 'glpipwa');
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

// Seção PWA - Identidade
echo "<tr class='tab_bg_1'><th colspan='2'>" . __('PWA Identity', 'glpipwa') . "</th></tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Application Name', 'glpipwa') . "</td>";
echo "<td><input type='text' name='pwa_name' value='" . htmlspecialchars($config['pwa_name'] ?? 'GLPI Service Desk') . "' size='60'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Short Name', 'glpipwa') . "</td>";
echo "<td><input type='text' name='pwa_short_name' value='" . htmlspecialchars($config['pwa_short_name'] ?? 'GLPI') . "' size='60'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Description', 'glpipwa') . "</td>";
echo "<td><textarea name='pwa_description' rows='3' cols='60'>" . htmlspecialchars($config['pwa_description'] ?? '') . "</textarea></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Language', 'glpipwa') . "</td>";
echo "<td><input type='text' name='pwa_lang' value='" . htmlspecialchars($config['pwa_lang'] ?? 'pt-BR') . "' size='10' placeholder='pt-BR'><br>";
echo "<small>" . __('ISO 639-1 language code (e.g., pt-BR, en-US)', 'glpipwa') . "</small></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Text Direction', 'glpipwa') . "</td>";
echo "<td>";
echo "<select name='pwa_dir'>";
$directions = ['ltr' => __('Left to Right', 'glpipwa'), 'rtl' => __('Right to Left', 'glpipwa')];
foreach ($directions as $value => $label) {
    $selected = ($config['pwa_dir'] ?? 'ltr') === $value ? 'selected' : '';
    echo "<option value='$value' $selected>$label</option>";
}
echo "</select>";
echo "</td>";
echo "</tr>";

// Seção PWA - Navegação
echo "<tr class='tab_bg_1'><th colspan='2'>" . __('PWA Navigation', 'glpipwa') . "</th></tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Start URL', 'glpipwa') . "</td>";
echo "<td><input type='text' name='pwa_start_url' value='" . htmlspecialchars($config['pwa_start_url'] ?? '/index.php?from=pwa') . "' size='60'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Scope', 'glpipwa') . "</td>";
echo "<td><input type='text' name='pwa_scope' value='" . htmlspecialchars($config['pwa_scope'] ?? '/') . "' size='60'><br>";
echo "<small>" . __('URL scope of the PWA (usually /)', 'glpipwa') . "</small></td>";
echo "</tr>";

// Seção PWA - Aparência
echo "<tr class='tab_bg_1'><th colspan='2'>" . __('PWA Appearance', 'glpipwa') . "</th></tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Theme Color', 'glpipwa') . "</td>";
echo "<td><input type='color' name='pwa_theme_color' value='" . htmlspecialchars($config['pwa_theme_color'] ?? '#0d6efd') . "'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Background Color', 'glpipwa') . "</td>";
echo "<td><input type='color' name='pwa_background_color' value='" . htmlspecialchars($config['pwa_background_color'] ?? '#ffffff') . "'></td>";
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
echo "<td>" . __('Base Icon', 'glpipwa') . "</td>";
echo "<td><input type='file' name='icon_base' accept='image/png'><br>";
echo "<small>" . __('Upload a square PNG icon (minimum 512x512px). All sizes will be generated automatically.', 'glpipwa') . "</small></td>";
echo "</tr>";

// Mostrar ícones disponíveis
$available_sizes = PluginGlpipwaIcon::getAvailableSizes();
if (!empty($available_sizes)) {
    echo "<tr class='tab_bg_2'>";
    echo "<td>" . __('Available Sizes', 'glpipwa') . "</td>";
    echo "<td><small>" . __('Generated sizes', 'glpipwa') . ": " . implode(', ', $available_sizes) . "px";
    if (PluginGlpipwaIcon::exists(512, true)) {
        echo " (+ maskable)";
    }
    echo "</small></td>";
    echo "</tr>";
}

// Seção Shortcuts
echo "<tr class='tab_bg_1'><th colspan='2'>" . __('Shortcuts', 'glpipwa') . "</th></tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Default Shortcuts', 'glpipwa') . "</td>";
echo "<td><input type='checkbox' name='pwa_shortcuts_default_enabled' value='1' " . (($config['pwa_shortcuts_default_enabled'] ?? '1') == '1' ? 'checked' : '') . "> ";
echo __('Enable default GLPI shortcuts (New Ticket, My Tickets, Knowledge Base)', 'glpipwa') . "</td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Custom Shortcuts', 'glpipwa') . "</td>";
echo "<td><textarea name='pwa_shortcuts_custom' rows='5' cols='60' placeholder='[{\"name\":\"Custom Shortcut\",\"short_name\":\"Shortcut\",\"url\":\"/front/page.php\",\"icon\":\"/path/to/icon.png\",\"icon_sizes\":\"96x96\"}]'>" . htmlspecialchars($config['pwa_shortcuts_custom'] ?? '') . "</textarea><br>";
echo "<small>" . __('JSON array of custom shortcuts. Each shortcut must have: name, url. Optional: short_name, icon, icon_sizes', 'glpipwa') . "</small></td>";
echo "</tr>";

// Seção Avançado
echo "<tr class='tab_bg_1'><th colspan='2'>" . __('Advanced', 'glpipwa') . "</th></tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Categories', 'glpipwa') . "</td>";
echo "<td>";
$all_categories = ['productivity', 'business', 'utilities', 'collaboration', 'education', 'entertainment', 'finance', 'food', 'games', 'health', 'lifestyle', 'magazines', 'medical', 'music', 'news', 'photo', 'shopping', 'social', 'sports', 'travel', 'weather'];
$selected_categories = !empty($config['pwa_categories']) ? json_decode($config['pwa_categories'], true) : [];
if (!is_array($selected_categories)) {
    $selected_categories = [];
}
foreach ($all_categories as $cat) {
    $checked = in_array($cat, $selected_categories) ? 'checked' : '';
    echo "<input type='checkbox' name='pwa_categories[]' value='$cat' $checked> " . ucfirst($cat) . "<br>";
}
echo "</td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Edge Side Panel Width', 'glpipwa') . "</td>";
echo "<td><input type='number' name='pwa_edge_panel_width' value='" . htmlspecialchars($config['pwa_edge_panel_width'] ?? '420') . "' min='0' max='1000' size='10'> px<br>";
echo "<small>" . __('Preferred width for Edge Side Panel (0 to disable)', 'glpipwa') . "</small></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Related Application URL', 'glpipwa') . "</td>";
echo "<td><input type='url' name='pwa_related_app_url' value='" . htmlspecialchars($config['pwa_related_app_url'] ?? '') . "' size='60'><br>";
echo "<small>" . __('URL to a related web application manifest', 'glpipwa') . "</small></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Prefer Related Applications', 'glpipwa') . "</td>";
echo "<td><input type='checkbox' name='pwa_prefer_related' value='1' " . (($config['pwa_prefer_related'] ?? '0') == '1' ? 'checked' : '') . "> ";
echo __('Prefer related applications over this PWA', 'glpipwa') . "</td>";
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

