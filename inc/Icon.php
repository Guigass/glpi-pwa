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

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Classe para gerenciamento de ícones PWA
 */
class PluginGlpipwaIcon
{
    const ICON_DIR = 'pics';
    const SIZES = [192, 512];

    /**
     * Obtém o caminho do ícone para um tamanho específico
     * Retorna a URL do proxy PHP que serve o ícone
     */
    public static function getPath($size)
    {
        // Construir caminho absoluto do arquivo de ícone
        $plugin_root = dirname(__DIR__);
        $plugin_root_real = realpath($plugin_root);
        if ($plugin_root_real === false) {
            $plugin_root_real = $plugin_root;
        }
        $full_path = $plugin_root_real . DIRECTORY_SEPARATOR . self::ICON_DIR . DIRECTORY_SEPARATOR . 'icon-' . $size . '.png';

        if (file_exists($full_path)) {
            // Retornar URL do proxy PHP que serve o ícone
            // Sempre usar caminho absoluto começando com / para evitar duplicação
            // quando o manifest resolve URLs relativamente ao seu próprio caminho
            $plugin_url = Plugin::getWebDir('glpipwa', false);
            
            // Normalizar a URL para garantir que seja absoluta
            // Remover barras duplicadas e garantir que comece com /
            $plugin_url = '/' . ltrim($plugin_url, '/');
            $plugin_url = rtrim($plugin_url, '/');
            
            // Construir URL absoluta do ícone
            $icon_path = $plugin_url . '/front/icon.php?size=' . $size;
            
            return $icon_path;
        }

        return null;
    }

    /**
     * Faz upload e redimensiona um ícone
     * @param array $file Array do $_FILES
     * @param int $size Tamanho do ícone (192 ou 512)
     * @return array ['success' => bool, 'message' => string]
     */
    public static function upload($file, $size)
    {
        // Validar tamanho permitido
        if (!in_array($size, self::SIZES)) {
            return ['success' => false, 'message' => __('Invalid icon size', 'glpipwa')];
        }

        // Verificar se arquivo foi enviado
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'message' => __('No file uploaded', 'glpipwa')];
        }

        // Verificar erros de upload do PHP
        if (isset($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
            $error_messages = [
                UPLOAD_ERR_INI_SIZE => __('File exceeds upload_max_filesize directive', 'glpipwa'),
                UPLOAD_ERR_FORM_SIZE => __('File exceeds MAX_FILE_SIZE directive', 'glpipwa'),
                UPLOAD_ERR_PARTIAL => __('File was only partially uploaded', 'glpipwa'),
                UPLOAD_ERR_NO_FILE => __('No file was uploaded', 'glpipwa'),
                UPLOAD_ERR_NO_TMP_DIR => __('Missing temporary folder', 'glpipwa'),
                UPLOAD_ERR_CANT_WRITE => __('Failed to write file to disk', 'glpipwa'),
                UPLOAD_ERR_EXTENSION => __('A PHP extension stopped the file upload', 'glpipwa'),
            ];
            $message = $error_messages[$file['error']] ?? __('Unknown upload error', 'glpipwa');
            return ['success' => false, 'message' => $message];
        }

        // Verificar extensão GD
        if (!extension_loaded('gd')) {
            return ['success' => false, 'message' => __('GD extension is not available', 'glpipwa')];
        }

        // Validar tipo de arquivo
        $allowed_types = ['image/png'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (!$finfo) {
            return ['success' => false, 'message' => __('Could not determine file type', 'glpipwa')];
        }
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime_type, $allowed_types)) {
            return ['success' => false, 'message' => __('Invalid file type. Only PNG images are allowed', 'glpipwa')];
        }

        // Validar tamanho do arquivo (máximo 2MB)
        if ($file['size'] > 2 * 1024 * 1024) {
            return ['success' => false, 'message' => __('File size exceeds 2MB limit', 'glpipwa')];
        }

        // Construir caminho absoluto do diretório de ícones
        // __DIR__ aponta para inc/, então dirname(__DIR__) é a raiz do plugin
        $plugin_root = dirname(__DIR__);
        
        // Garantir que o caminho seja absoluto
        $plugin_root_real = realpath($plugin_root);
        if ($plugin_root_real === false) {
            // Se realpath falhar, usar o caminho original
            $plugin_root_real = $plugin_root;
        }
        
        $plugin_dir = $plugin_root_real . DIRECTORY_SEPARATOR . self::ICON_DIR;
        
        // Criar diretório se não existir
        if (!is_dir($plugin_dir)) {
            if (!@mkdir($plugin_dir, 0755, true)) {
                // Tentar obter informações de erro
                $error = error_get_last();
                $error_msg = $error ? $error['message'] : __('Could not create icons directory. Check permissions', 'glpipwa');
                return ['success' => false, 'message' => $error_msg];
            }
            // Após criar, garantir permissões
            @chmod($plugin_dir, 0755);
        }

        // Verificar se diretório existe e é gravável
        if (!is_dir($plugin_dir)) {
            return ['success' => false, 'message' => __('Icons directory does not exist and could not be created', 'glpipwa')];
        }
        
        // Se o diretório não for gravável, tentar corrigir permissões
        if (!is_writable($plugin_dir)) {
            // Tentar corrigir permissões
            @chmod($plugin_dir, 0755);
            
            // Verificar novamente após tentar corrigir
            if (!is_writable($plugin_dir)) {
                // Verificar permissões do diretório pai
                $parent_dir = dirname($plugin_dir);
                $parent_writable = is_writable($parent_dir);
                
                // Obter informações sobre o diretório para mensagem de erro mais útil
                $current_perms = substr(sprintf('%o', fileperms($plugin_dir)), -4);
                $owner_info = '';
                $web_user = '';
                if (function_exists('posix_getpwuid') && function_exists('fileowner')) {
                    $owner = @fileowner($plugin_dir);
                    if ($owner !== false) {
                        $owner_info = @posix_getpwuid($owner);
                        $owner_info = $owner_info ? $owner_info['name'] : '';
                    }
                    // Tentar obter usuário do processo atual
                    $current_uid = @posix_geteuid();
                    if ($current_uid !== false) {
                        $current_user = @posix_getpwuid($current_uid);
                        $web_user = $current_user ? $current_user['name'] : '';
                    }
                }
                
                $message = __('Icons directory is not writable', 'glpipwa');
                $message .= ' (permissions: ' . $current_perms;
                if ($owner_info) {
                    $message .= ', owner: ' . $owner_info;
                }
                if ($web_user && $web_user !== $owner_info) {
                    $message .= ', web server user: ' . $web_user;
                }
                $message .= ')';
                
                // Adicionar instruções de correção
                $message .= '. ' . __('To fix this, run the following command as root', 'glpipwa') . ':';
                $message .= ' chown -R ' . ($web_user ?: 'www-data') . ':' . ($web_user ?: 'www-data') . ' ' . escapeshellarg($plugin_dir);
                $message .= ' && chmod -R 755 ' . escapeshellarg($plugin_dir);
                
                if (!$parent_writable) {
                    $message .= '. ' . __('Parent directory is also not writable', 'glpipwa');
                }
                
                return ['success' => false, 'message' => $message];
            }
        }

        $target_path = $plugin_dir . DIRECTORY_SEPARATOR . 'icon-' . $size . '.png';

        // Carregar imagem preservando transparência
        $image = @imagecreatefrompng($file['tmp_name']);
        if (!$image) {
            return ['success' => false, 'message' => __('Could not load image. File may be corrupted or not a valid PNG', 'glpipwa')];
        }

        // Obter dimensões originais
        $width = imagesx($image);
        $height = imagesy($image);

        // Se a imagem já está no tamanho correto, copiar diretamente para preservar qualidade máxima
        if ($width == $size && $height == $size) {
            imagedestroy($image);
            // Copiar arquivo original diretamente para preservar qualidade
            if (!@copy($file['tmp_name'], $target_path)) {
                return ['success' => false, 'message' => __('Could not save icon file. Check directory permissions', 'glpipwa')];
            }
            return ['success' => true, 'message' => sprintf(__('Icon %dx%d uploaded successfully', 'glpipwa'), $size, $size)];
        }

        // Redimensionar com alta qualidade
        $resized = imagecreatetruecolor($size, $size);
        if (!$resized) {
            imagedestroy($image);
            return ['success' => false, 'message' => __('Could not create resized image', 'glpipwa')];
        }

        // Preservar transparência
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        
        // Preencher com transparência completa
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);
        
        // Habilitar alpha blending para o redimensionamento
        imagealphablending($resized, true);
        
        // Usar imagecopyresampled para melhor qualidade (usa interpolação bilinear)
        // Para ainda melhor qualidade, poderíamos usar imagecopyresampled com filtros,
        // mas isso requer GD 2.0.1+ e não está disponível em todas as versões
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $size, $size, $width, $height);
        
        // Desabilitar alpha blending novamente antes de salvar
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        
        imagedestroy($image);
        $image = $resized;

        // Salvar arquivo com máxima qualidade (0 = sem compressão, 9 = máxima compressão)
        // Para ícones, usamos compressão mínima (0) para máxima qualidade
        $result = @imagepng($image, $target_path, 0);
        imagedestroy($image);

        if (!$result) {
            return ['success' => false, 'message' => __('Could not save icon file. Check directory permissions', 'glpipwa')];
        }

        return ['success' => true, 'message' => sprintf(__('Icon %dx%d uploaded successfully', 'glpipwa'), $size, $size)];
    }

    /**
     * Remove um ícone
     */
    public static function delete($size)
    {
        $plugin_root = dirname(__DIR__);
        $plugin_root_real = realpath($plugin_root);
        if ($plugin_root_real === false) {
            $plugin_root_real = $plugin_root;
        }
        $plugin_dir = $plugin_root_real . DIRECTORY_SEPARATOR . self::ICON_DIR;
        $icon_path = $plugin_dir . DIRECTORY_SEPARATOR . 'icon-' . $size . '.png';

        if (file_exists($icon_path)) {
            return @unlink($icon_path);
        }

        return false;
    }

    /**
     * Verifica se um ícone existe
     */
    public static function exists($size)
    {
        $plugin_root = dirname(__DIR__);
        $plugin_root_real = realpath($plugin_root);
        if ($plugin_root_real === false) {
            $plugin_root_real = $plugin_root;
        }
        $plugin_dir = $plugin_root_real . DIRECTORY_SEPARATOR . self::ICON_DIR;
        $icon_path = $plugin_dir . DIRECTORY_SEPARATOR . 'icon-' . $size . '.png';

        return file_exists($icon_path);
    }
}

