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
    const ICON_DIR = 'pics/icons';
    const SIZES = [48, 72, 96, 128, 144, 152, 192, 256, 384, 512];
    const MASKABLE_SIZE = 512;
    const MIN_BASE_SIZE = 512; // Tamanho mínimo do ícone base para upload

    /**
     * Obtém o caminho do ícone para um tamanho específico
     * Retorna a URL do proxy PHP que serve o ícone
     * @param int $size Tamanho do ícone
     * @param bool $maskable Se true, retorna versão maskable (apenas para 512)
     * @return string|null URL do ícone ou null se não existir
     */
    public static function getPath($size, $maskable = false)
    {
        // Construir caminho absoluto do arquivo de ícone
        $plugin_root = dirname(__DIR__);
        $plugin_root_real = realpath($plugin_root);
        if ($plugin_root_real === false) {
            $plugin_root_real = $plugin_root;
        }
        
        $filename = $maskable ? 'icon-' . $size . '-maskable.png' : 'icon-' . $size . '.png';
        $full_path = $plugin_root_real . DIRECTORY_SEPARATOR . self::ICON_DIR . DIRECTORY_SEPARATOR . $filename;

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
            if ($maskable) {
                $icon_path .= '&maskable=1';
            }
            
            return $icon_path;
        }

        return null;
    }

    /**
     * Faz upload de um ícone base e gera automaticamente todos os tamanhos necessários
     * @param array $file Array do $_FILES
     * @return array ['success' => bool, 'message' => string, 'generated' => array]
     */
    public static function uploadBase($file)
    {
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

        // Validar tamanho do arquivo (máximo 5MB para ícone base)
        if ($file['size'] > 5 * 1024 * 1024) {
            return ['success' => false, 'message' => __('File size exceeds 5MB limit', 'glpipwa')];
        }

        // Carregar imagem para validar dimensões
        $base_image = @imagecreatefrompng($file['tmp_name']);
        if (!$base_image) {
            return ['success' => false, 'message' => __('Could not load image. File may be corrupted or not a valid PNG', 'glpipwa')];
        }

        $base_width = imagesx($base_image);
        $base_height = imagesy($base_image);
        imagedestroy($base_image);

        // Validar que é quadrada e tem tamanho mínimo
        if ($base_width !== $base_height) {
            return ['success' => false, 'message' => __('Icon must be square (same width and height)', 'glpipwa')];
        }

        if ($base_width < self::MIN_BASE_SIZE) {
            return ['success' => false, 'message' => sprintf(__('Icon must be at least %dx%d pixels', 'glpipwa'), self::MIN_BASE_SIZE, self::MIN_BASE_SIZE)];
        }

        // Preparar diretório de ícones
        $icon_dir_result = self::ensureIconDirectory();
        if (!$icon_dir_result['success']) {
            return $icon_dir_result;
        }
        $plugin_dir = $icon_dir_result['directory'];

        // Carregar imagem base preservando transparência
        $base_image = @imagecreatefrompng($file['tmp_name']);
        if (!$base_image) {
            return ['success' => false, 'message' => __('Could not load image. File may be corrupted or not a valid PNG', 'glpipwa')];
        }

        $generated = [];
        $errors = [];

        // Gerar todos os tamanhos necessários
        foreach (self::SIZES as $size) {
            $result = self::generateIconSize($base_image, $base_width, $plugin_dir, $size);
            if ($result['success']) {
                $generated[] = $size;
            } else {
                $errors[] = sprintf(__('Failed to generate %dx%d: %s', 'glpipwa'), $size, $size, $result['message']);
            }
        }

        // Gerar versão maskable (512px com safe zone)
        $maskable_result = self::generateMaskableIcon($base_image, $base_width, $plugin_dir);
        if ($maskable_result['success']) {
            $generated[] = 'maskable';
        } else {
            $errors[] = sprintf(__('Failed to generate maskable icon: %s', 'glpipwa'), $maskable_result['message']);
        }

        imagedestroy($base_image);

        if (empty($generated)) {
            return ['success' => false, 'message' => __('Failed to generate any icons', 'glpipwa') . '. ' . implode('; ', $errors)];
        }

        $message = sprintf(__('Successfully generated %d icon sizes', 'glpipwa'), count($generated));
        if (!empty($errors)) {
            $message .= '. ' . __('Some errors occurred', 'glpipwa') . ': ' . implode('; ', $errors);
        }

        return ['success' => true, 'message' => $message, 'generated' => $generated];
    }

    /**
     * Garante que o diretório de ícones existe e é gravável
     * @return array ['success' => bool, 'message' => string, 'directory' => string]
     */
    private static function ensureIconDirectory()
    {
        $plugin_root = dirname(__DIR__);
        $plugin_root_real = realpath($plugin_root);
        if ($plugin_root_real === false) {
            $plugin_root_real = $plugin_root;
        }
        
        $plugin_dir = $plugin_root_real . DIRECTORY_SEPARATOR . self::ICON_DIR;
        
        // Criar diretório se não existir
        if (!is_dir($plugin_dir)) {
            if (!@mkdir($plugin_dir, 0755, true)) {
                $error = error_get_last();
                $error_msg = $error ? $error['message'] : __('Could not create icons directory. Check permissions', 'glpipwa');
                return ['success' => false, 'message' => $error_msg];
            }
            @chmod($plugin_dir, 0755);
        }

        if (!is_dir($plugin_dir)) {
            return ['success' => false, 'message' => __('Icons directory does not exist and could not be created', 'glpipwa')];
        }
        
        if (!is_writable($plugin_dir)) {
            @chmod($plugin_dir, 0755);
            if (!is_writable($plugin_dir)) {
                $current_perms = substr(sprintf('%o', fileperms($plugin_dir)), -4);
                return ['success' => false, 'message' => __('Icons directory is not writable', 'glpipwa') . ' (permissions: ' . $current_perms . ')'];
            }
        }

        return ['success' => true, 'directory' => $plugin_dir];
    }

    /**
     * Gera um tamanho específico de ícone a partir da imagem base
     * @param resource $base_image Imagem base (GD resource)
     * @param int $base_size Tamanho da imagem base
     * @param string $plugin_dir Diretório onde salvar
     * @param int $target_size Tamanho desejado
     * @return array ['success' => bool, 'message' => string]
     */
    private static function generateIconSize($base_image, $base_size, $plugin_dir, $target_size)
    {
        $target_path = $plugin_dir . DIRECTORY_SEPARATOR . 'icon-' . $target_size . '.png';

        // Se já está no tamanho correto, copiar diretamente
        if ($base_size == $target_size) {
            // Criar cópia da imagem
            $resized = imagecreatetruecolor($target_size, $target_size);
            if (!$resized) {
                return ['success' => false, 'message' => __('Could not create image', 'glpipwa')];
            }

            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefill($resized, 0, 0, $transparent);
            imagealphablending($resized, true);
            imagecopy($resized, $base_image, 0, 0, 0, 0, $target_size, $target_size);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);

            $result = @imagepng($resized, $target_path, 0);
            imagedestroy($resized);

            if (!$result) {
                return ['success' => false, 'message' => __('Could not save icon file', 'glpipwa')];
            }
            return ['success' => true];
        }

        // Redimensionar com alta qualidade
        $resized = imagecreatetruecolor($target_size, $target_size);
        if (!$resized) {
            return ['success' => false, 'message' => __('Could not create resized image', 'glpipwa')];
        }

        // Preservar transparência
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);
        imagealphablending($resized, true);

        // Redimensionar usando interpolação bilinear
        imagecopyresampled($resized, $base_image, 0, 0, 0, 0, $target_size, $target_size, $base_size, $base_size);

        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        $result = @imagepng($resized, $target_path, 0);
        imagedestroy($resized);

        if (!$result) {
            return ['success' => false, 'message' => __('Could not save icon file', 'glpipwa')];
        }

        return ['success' => true];
    }

    /**
     * Gera versão maskable do ícone (512px com safe zone de 80%)
     * @param resource $base_image Imagem base (GD resource)
     * @param int $base_size Tamanho da imagem base
     * @param string $plugin_dir Diretório onde salvar
     * @return array ['success' => bool, 'message' => string]
     */
    private static function generateMaskableIcon($base_image, $base_size, $plugin_dir)
    {
        $target_size = self::MASKABLE_SIZE;
        $target_path = $plugin_dir . DIRECTORY_SEPARATOR . 'icon-' . $target_size . '-maskable.png';

        // Criar canvas 512x512
        $maskable = imagecreatetruecolor($target_size, $target_size);
        if (!$maskable) {
            return ['success' => false, 'message' => __('Could not create maskable image', 'glpipwa')];
        }

        // Preencher com cor de fundo (branco ou transparente)
        imagealphablending($maskable, false);
        imagesavealpha($maskable, true);
        $transparent = imagecolorallocatealpha($maskable, 0, 0, 0, 127);
        imagefill($maskable, 0, 0, $transparent);
        imagealphablending($maskable, true);

        // Calcular safe zone (80% do tamanho, centralizado)
        $safe_zone_size = (int)($target_size * 0.8);
        $offset = (int)(($target_size - $safe_zone_size) / 2);

        // Redimensionar imagem base para o tamanho da safe zone
        $safe_image = imagecreatetruecolor($safe_zone_size, $safe_zone_size);
        if (!$safe_image) {
            imagedestroy($maskable);
            return ['success' => false, 'message' => __('Could not create safe zone image', 'glpipwa')];
        }

        imagealphablending($safe_image, false);
        imagesavealpha($safe_image, true);
        $transparent_safe = imagecolorallocatealpha($safe_image, 0, 0, 0, 127);
        imagefill($safe_image, 0, 0, $transparent_safe);
        imagealphablending($safe_image, true);

        imagecopyresampled($safe_image, $base_image, 0, 0, 0, 0, $safe_zone_size, $safe_zone_size, $base_size, $base_size);

        // Copiar safe zone para o centro do canvas maskable
        imagealphablending($maskable, true);
        imagecopy($maskable, $safe_image, $offset, $offset, 0, 0, $safe_zone_size, $safe_zone_size);

        imagealphablending($maskable, false);
        imagesavealpha($maskable, true);

        imagedestroy($safe_image);

        $result = @imagepng($maskable, $target_path, 0);
        imagedestroy($maskable);

        if (!$result) {
            return ['success' => false, 'message' => __('Could not save maskable icon', 'glpipwa')];
        }

        return ['success' => true];
    }

    /**
     * Faz upload e redimensiona um ícone (método legado para compatibilidade)
     * @param array $file Array do $_FILES
     * @param int $size Tamanho do ícone (192 ou 512)
     * @return array ['success' => bool, 'message' => string]
     * @deprecated Use uploadBase() instead
     */
    public static function upload($file, $size)
    {
        // Redirecionar para uploadBase se for tamanho grande
        if ($size >= self::MIN_BASE_SIZE) {
            return self::uploadBase($file);
        }

        // Para tamanhos menores, manter comportamento antigo (mas não recomendado)
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

        $icon_dir_result = self::ensureIconDirectory();
        if (!$icon_dir_result['success']) {
            return $icon_dir_result;
        }
        $plugin_dir = $icon_dir_result['directory'];

        $target_path = $plugin_dir . DIRECTORY_SEPARATOR . 'icon-' . $size . '.png';

        // Carregar imagem preservando transparência
        $image = @imagecreatefrompng($file['tmp_name']);
        if (!$image) {
            return ['success' => false, 'message' => __('Could not load image. File may be corrupted or not a valid PNG', 'glpipwa')];
        }

        // Obter dimensões originais
        $width = imagesx($image);
        $height = imagesy($image);

        // Se a imagem já está no tamanho correto, copiar diretamente
        if ($width == $size && $height == $size) {
            imagedestroy($image);
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
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);
        imagealphablending($resized, true);

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $size, $size, $width, $height);

        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        imagedestroy($image);

        $result = @imagepng($resized, $target_path, 0);
        imagedestroy($resized);

        if (!$result) {
            return ['success' => false, 'message' => __('Could not save icon file. Check directory permissions', 'glpipwa')];
        }

        return ['success' => true, 'message' => sprintf(__('Icon %dx%d uploaded successfully', 'glpipwa'), $size, $size)];
    }

    /**
     * Remove um ícone específico
     * @param int|null $size Se null, remove todos os ícones
     */
    public static function delete($size = null)
    {
        $plugin_root = dirname(__DIR__);
        $plugin_root_real = realpath($plugin_root);
        if ($plugin_root_real === false) {
            $plugin_root_real = $plugin_root;
        }
        $plugin_dir = $plugin_root_real . DIRECTORY_SEPARATOR . self::ICON_DIR;

        if ($size === null) {
            // Remover todos os ícones
            $deleted = 0;
            foreach (self::SIZES as $s) {
                $icon_path = $plugin_dir . DIRECTORY_SEPARATOR . 'icon-' . $s . '.png';
                if (file_exists($icon_path) && @unlink($icon_path)) {
                    $deleted++;
                }
            }
            // Remover maskable também
            $maskable_path = $plugin_dir . DIRECTORY_SEPARATOR . 'icon-' . self::MASKABLE_SIZE . '-maskable.png';
            if (file_exists($maskable_path) && @unlink($maskable_path)) {
                $deleted++;
            }
            return $deleted > 0;
        }

        $icon_path = $plugin_dir . DIRECTORY_SEPARATOR . 'icon-' . $size . '.png';
        if (file_exists($icon_path)) {
            return @unlink($icon_path);
        }

        return false;
    }

    /**
     * Verifica se um ícone existe
     * @param int $size Tamanho do ícone
     * @param bool $maskable Se true, verifica versão maskable
     */
    public static function exists($size, $maskable = false)
    {
        $plugin_root = dirname(__DIR__);
        $plugin_root_real = realpath($plugin_root);
        if ($plugin_root_real === false) {
            $plugin_root_real = $plugin_root;
        }
        $plugin_dir = $plugin_root_real . DIRECTORY_SEPARATOR . self::ICON_DIR;
        $filename = $maskable ? 'icon-' . $size . '-maskable.png' : 'icon-' . $size . '.png';
        $icon_path = $plugin_dir . DIRECTORY_SEPARATOR . $filename;

        return file_exists($icon_path);
    }

    /**
     * Obtém todos os tamanhos de ícones disponíveis
     * @return array Array com os tamanhos disponíveis
     */
    public static function getAvailableSizes()
    {
        $available = [];
        foreach (self::SIZES as $size) {
            if (self::exists($size)) {
                $available[] = $size;
            }
        }
        return $available;
    }
}

