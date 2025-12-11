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
 * Carregador mínimo para arquivos stateless do plugin GLPI PWA
 * 
 * Este carregador inicializa apenas o necessário para acessar configurações
 * do banco de dados SEM inicializar a sessão do GLPI, evitando interferência
 * com tokens CSRF.
 * 
 * IMPORTANTE: Este loader NÃO deve ser usado para arquivos que precisam de
 * autenticação ou sessão. Use includes.php para esses casos.
 */
class PluginGlpipwaMinimalLoader
{
    private static $loaded = false;

    /**
     * Carrega o mínimo necessário do GLPI sem inicializar sessão
     * 
     * @param string|null $glpiRoot Caminho raiz do GLPI (se não fornecido, tenta detectar)
     * @return bool True se carregado com sucesso
     */
    public static function load($glpiRoot = null)
    {
        if (self::$loaded) {
            return true;
        }

        // Definir GLPI_ROOT se não estiver definido
        if (!defined('GLPI_ROOT')) {
            if ($glpiRoot === null) {
                // Tentar detectar a partir do caminho deste arquivo
                // Este arquivo está em: plugins/glpipwa/inc/MinimalLoader.php
                $thisFile = __FILE__;
                $detectedRoot = dirname(dirname(dirname(dirname($thisFile))));
                
                // Verificar se o caminho detectado parece correto
                if (file_exists($detectedRoot . '/inc/includes.php')) {
                    define('GLPI_ROOT', $detectedRoot);
                } else {
                    // Fallback: assumir que está 3 níveis acima
                    define('GLPI_ROOT', dirname(dirname(dirname(__DIR__))));
                }
            } else {
                define('GLPI_ROOT', $glpiRoot);
            }
        }

        // Verificar se o GLPI existe
        if (!file_exists(GLPI_ROOT . '/inc/includes.php')) {
            return false;
        }

        try {
            // Carregar apenas o necessário para acessar banco de dados e Config
            // Sem inicializar sessão, autenticação ou outros sistemas
            
            // Carregar autoloader básico primeiro
            if (file_exists(GLPI_ROOT . '/inc/autoload.function.php')) {
                require_once(GLPI_ROOT . '/inc/autoload.function.php');
            }

            // Carregar constantes básicas se necessário
            if (file_exists(GLPI_ROOT . '/inc/constants.php')) {
                require_once(GLPI_ROOT . '/inc/constants.php');
            }

            // Carregar DB (necessário para Config)
            if (file_exists(GLPI_ROOT . '/inc/DB.php')) {
                require_once(GLPI_ROOT . '/inc/DB.php');
            }

            // Carregar Config
            if (file_exists(GLPI_ROOT . '/inc/Config.php')) {
                require_once(GLPI_ROOT . '/inc/Config.php');
            }

            // Inicializar conexão com banco de dados se necessário
            // Mas SEM inicializar sessão
            global $DB;
            if (!isset($DB) || !is_object($DB)) {
                // Tentar conectar ao banco sem inicializar sessão
                if (class_exists('DB')) {
                    // Verificar se já existe uma conexão global
                    if (!isset($GLOBALS['DB']) || !is_object($GLOBALS['DB'])) {
                        $DB = new DB();
                        // Armazenar na variável global também
                        $GLOBALS['DB'] = $DB;
                    } else {
                        $DB = $GLOBALS['DB'];
                    }
                }
            }

            // Carregar classes do plugin
            self::loadPluginClasses();

            self::$loaded = true;
            return true;
        } catch (Exception $e) {
            // Silenciosamente ignora erros
            return false;
        } catch (Throwable $e) {
            // Silenciosamente ignora erros fatais
            return false;
        }
    }

    /**
     * Carrega apenas as classes do plugin necessárias para arquivos stateless
     */
    private static function loadPluginClasses()
    {
        $pluginDir = dirname(__DIR__);
        
        $requiredClasses = [
            'Config.php',
            'Manifest.php',
            'Icon.php',
            'StaticFileServer.php',
        ];

        foreach ($requiredClasses as $classFile) {
            $filePath = $pluginDir . '/inc/' . $classFile;
            if (file_exists($filePath)) {
                require_once($filePath);
            }
        }
    }

    /**
     * Verifica se o loader já foi inicializado
     * 
     * @return bool
     */
    public static function isLoaded()
    {
        return self::$loaded;
    }
}

