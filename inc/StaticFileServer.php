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
 * Classe auxiliar para servir arquivos estáticos de forma padronizada
 */
class PluginGlpipwaStaticFileServer
{
    /**
     * Serve um arquivo estático com headers padronizados
     *
     * @param string $filePath Caminho completo do arquivo a ser servido
     * @param string $contentType Content-Type do arquivo (ex: 'application/javascript')
     * @param string $cacheControl Cache-Control header (padrão: 'public, max-age=3600')
     * @return void
     */
    public static function serve($filePath, $contentType, $cacheControl = 'public, max-age=3600')
    {
        // Verificar se o arquivo existe
        if (!file_exists($filePath) || !is_readable($filePath)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo '// File not found';
            exit;
        }

        // Definir headers padronizados
        header('Content-Type: ' . $contentType . '; charset=utf-8');
        header('Cache-Control: ' . $cacheControl);
        header('X-Content-Type-Options: nosniff');

        // Servir o arquivo
        readfile($filePath);
        exit;
    }
}

