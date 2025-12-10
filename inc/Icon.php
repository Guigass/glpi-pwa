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
     */
    public static function getPath($size)
    {
        $plugin_url = Plugin::getWebDir('glpipwa', false);
        $icon_path = $plugin_url . '/' . self::ICON_DIR . '/icon-' . $size . '.png';
        $full_path = GLPI_ROOT . '/plugins/glpipwa/' . self::ICON_DIR . '/icon-' . $size . '.png';

        if (file_exists($full_path)) {
            return $icon_path;
        }

        return null;
    }

    /**
     * Faz upload e redimensiona um ícone
     */
    public static function upload($file, $size)
    {
        if (!in_array($size, self::SIZES)) {
            return false;
        }

        // Validar tipo de arquivo
        $allowed_types = ['image/png'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime_type, $allowed_types)) {
            return false;
        }

        // Validar tamanho (máximo 2MB)
        if ($file['size'] > 2 * 1024 * 1024) {
            return false;
        }

        $plugin_dir = GLPI_ROOT . '/plugins/glpipwa/' . self::ICON_DIR;
        
        // Criar diretório se não existir
        if (!is_dir($plugin_dir)) {
            mkdir($plugin_dir, 0755, true);
        }

        $target_path = $plugin_dir . '/icon-' . $size . '.png';

        // Carregar imagem
        $image = imagecreatefrompng($file['tmp_name']);
        if (!$image) {
            return false;
        }

        // Redimensionar se necessário
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width != $size || $height != $size) {
            $resized = imagecreatetruecolor($size, $size);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $size, $size, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        // Salvar
        $result = imagepng($image, $target_path, 9);
        imagedestroy($image);

        return $result;
    }

    /**
     * Remove um ícone
     */
    public static function delete($size)
    {
        $plugin_dir = GLPI_ROOT . '/plugins/glpipwa/' . self::ICON_DIR;
        $icon_path = $plugin_dir . '/icon-' . $size . '.png';

        if (file_exists($icon_path)) {
            return unlink($icon_path);
        }

        return false;
    }

    /**
     * Verifica se um ícone existe
     */
    public static function exists($size)
    {
        $plugin_dir = GLPI_ROOT . '/plugins/glpipwa/' . self::ICON_DIR;
        $icon_path = $plugin_dir . '/icon-' . $size . '.png';

        return file_exists($icon_path);
    }
}

