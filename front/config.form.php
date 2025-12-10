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

Html::header(__('Configuração PWA', 'glpipwa'), $_SERVER['PHP_SELF'], 'config', 'plugins');

if (!Session::haveRight('config', UPDATE)) {
    Html::displayRightError();
    exit;
}

// Processar formulário
if (isset($_POST['update']) && isset($_POST['_glpi_csrf_token'])) {
    // Validar CSRF token
    if (!Session::validateIDOR($_POST)) {
        Session::addMessageAfterRedirect(__('Token de segurança inválido', 'glpipwa'), true, ERROR);
        Html::redirect($_SERVER['PHP_SELF']);
    }
    
    $config = [];
    
    // Firebase - sanitizar inputs
    $config['firebase_api_key'] = trim($_POST['firebase_api_key'] ?? '');
    $config['firebase_project_id'] = trim($_POST['firebase_project_id'] ?? '');
    $config['firebase_messaging_sender_id'] = trim($_POST['firebase_messaging_sender_id'] ?? '');
    $config['firebase_app_id'] = trim($_POST['firebase_app_id'] ?? '');
    $config['firebase_vapid_key'] = trim($_POST['firebase_vapid_key'] ?? '');
    // Server key só atualiza se fornecido (não limpa se vazio)
    if (!empty($_POST['firebase_server_key'])) {
        $config['firebase_server_key'] = trim($_POST['firebase_server_key']);
    }
    
    // PWA - sanitizar inputs
    $config['pwa_name'] = trim($_POST['pwa_name'] ?? 'GLPI Service Desk');
    $config['pwa_short_name'] = trim($_POST['pwa_short_name'] ?? 'GLPI');
    $config['pwa_theme_color'] = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['pwa_theme_color'] ?? '') ? $_POST['pwa_theme_color'] : '#0d6efd';
    $config['pwa_background_color'] = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['pwa_background_color'] ?? '') ? $_POST['pwa_background_color'] : '#ffffff';
    $config['pwa_start_url'] = filter_var($_POST['pwa_start_url'] ?? '/', FILTER_SANITIZE_URL);
    $config['pwa_display'] = in_array($_POST['pwa_display'] ?? 'standalone', ['standalone', 'fullscreen', 'minimal-ui', 'browser']) ? $_POST['pwa_display'] : 'standalone';
    $config['pwa_orientation'] = in_array($_POST['pwa_orientation'] ?? 'any', ['any', 'portrait', 'landscape']) ? $_POST['pwa_orientation'] : 'any';
    
    // Validar
    if (PluginGlpipwaConfig::validatePWAConfig($config)) {
        // Preservar server key se não foi alterado
        if (!isset($config['firebase_server_key'])) {
            $current = PluginGlpipwaConfig::get('firebase_server_key');
            if ($current) {
                $config['firebase_server_key'] = $current;
            }
        }
        
        PluginGlpipwaConfig::setMultiple($config);
        Session::addMessageAfterRedirect(__('Configurações salvas com sucesso', 'glpipwa'), true, INFO);
    } else {
        Session::addMessageAfterRedirect(__('Erro ao validar configurações', 'glpipwa'), true, ERROR);
    }
    
    // Upload de ícones
    if (isset($_FILES['icon_192']) && $_FILES['icon_192']['error'] === UPLOAD_ERR_OK) {
        PluginGlpipwaIcon::upload($_FILES['icon_192'], 192);
    }
    
    if (isset($_FILES['icon_512']) && $_FILES['icon_512']['error'] === UPLOAD_ERR_OK) {
        PluginGlpipwaIcon::upload($_FILES['icon_512'], 512);
    }
    
    Html::redirect($_SERVER['PHP_SELF']);
}

// Teste de notificação
if (isset($_POST['test_notification'])) {
    $notification = new PluginGlpipwaNotificationPush();
    $users_id = Session::getLoginUserID();
    
    $result = $notification->sendToUser(
        $users_id,
        __('Notificação de Teste', 'glpipwa'),
        __('Esta é uma notificação de teste do plugin PWA', 'glpipwa'),
        ['type' => 'test']
    );
    
    if ($result) {
        Session::addMessageAfterRedirect(__('Notificação de teste enviada', 'glpipwa'), true, INFO);
    } else {
        Session::addMessageAfterRedirect(__('Erro ao enviar notificação de teste', 'glpipwa'), true, ERROR);
    }
    
    Html::redirect($_SERVER['PHP_SELF']);
}

// Obter configurações atuais
$config = PluginGlpipwaConfig::getAll();

echo "<div class='center'>";
echo "<form method='post' action='" . $_SERVER['PHP_SELF'] . "' enctype='multipart/form-data'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo "<table class='tab_cadre_fixe'>";

// Seção Firebase
echo "<tr class='tab_bg_1'><th colspan='2'>" . __('Configuração Firebase', 'glpipwa') . "</th></tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('API Key', 'glpipwa') . "</td>";
echo "<td><input type='text' name='firebase_api_key' value='" . Html::entities($config['firebase_api_key'] ?? '') . "' size='60'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Project ID', 'glpipwa') . "</td>";
echo "<td><input type='text' name='firebase_project_id' value='" . Html::entities($config['firebase_project_id'] ?? '') . "' size='60'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Messaging Sender ID', 'glpipwa') . "</td>";
echo "<td><input type='text' name='firebase_messaging_sender_id' value='" . Html::entities($config['firebase_messaging_sender_id'] ?? '') . "' size='60'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('App ID', 'glpipwa') . "</td>";
echo "<td><input type='text' name='firebase_app_id' value='" . Html::entities($config['firebase_app_id'] ?? '') . "' size='60'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('VAPID Key', 'glpipwa') . "</td>";
echo "<td><input type='text' name='firebase_vapid_key' value='" . Html::entities($config['firebase_vapid_key'] ?? '') . "' size='60'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Server Key', 'glpipwa') . "</td>";
echo "<td><input type='password' name='firebase_server_key' value='" . Html::entities($config['firebase_server_key'] ?? '') . "' size='60'></td>";
echo "</tr>";

// Seção PWA
echo "<tr class='tab_bg_1'><th colspan='2'>" . __('Configuração PWA', 'glpipwa') . "</th></tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Nome da Aplicação', 'glpipwa') . "</td>";
echo "<td><input type='text' name='pwa_name' value='" . Html::entities($config['pwa_name'] ?? 'GLPI Service Desk') . "' size='60'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Nome Curto', 'glpipwa') . "</td>";
echo "<td><input type='text' name='pwa_short_name' value='" . Html::entities($config['pwa_short_name'] ?? 'GLPI') . "' size='60'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Cor do Tema', 'glpipwa') . "</td>";
echo "<td><input type='color' name='pwa_theme_color' value='" . Html::entities($config['pwa_theme_color'] ?? '#0d6efd') . "'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Cor de Fundo', 'glpipwa') . "</td>";
echo "<td><input type='color' name='pwa_background_color' value='" . Html::entities($config['pwa_background_color'] ?? '#ffffff') . "'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('URL Inicial', 'glpipwa') . "</td>";
echo "<td><input type='text' name='pwa_start_url' value='" . Html::entities($config['pwa_start_url'] ?? '/') . "' size='60'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Modo de Exibição', 'glpipwa') . "</td>";
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
echo "<td>" . __('Orientação', 'glpipwa') . "</td>";
echo "<td>";
echo "<select name='pwa_orientation'>";
$orientations = ['any' => __('Qualquer', 'glpipwa'), 'portrait' => __('Retrato', 'glpipwa'), 'landscape' => __('Paisagem', 'glpipwa')];
foreach ($orientations as $value => $label) {
    $selected = ($config['pwa_orientation'] ?? 'any') === $value ? 'selected' : '';
    echo "<option value='$value' $selected>$label</option>";
}
echo "</select>";
echo "</td>";
echo "</tr>";

// Seção Ícones
echo "<tr class='tab_bg_1'><th colspan='2'>" . __('Ícones', 'glpipwa') . "</th></tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Ícone 192x192', 'glpipwa') . "</td>";
echo "<td><input type='file' name='icon_192' accept='image/png'></td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td>" . __('Ícone 512x512', 'glpipwa') . "</td>";
echo "<td><input type='file' name='icon_512' accept='image/png'></td>";
echo "</tr>";

// Botões
echo "<tr class='tab_bg_2'>";
echo "<td colspan='2' class='center'>";
echo "<input type='submit' name='update' value='" . __('Salvar', 'glpipwa') . "' class='submit'>";
echo "&nbsp;";
echo "<input type='submit' name='test_notification' value='" . __('Enviar Notificação de Teste', 'glpipwa') . "' class='submit'>";
echo "</td>";
echo "</tr>";

echo "</table>";
Html::closeForm();
echo "</div>";

Html::footer();

