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
 * Classe para tarefas automáticas (Cron) do plugin GLPI PWA
 */
class PluginGlpipwaCron extends CommonDBTM
{
    /**
     * Retorna o nome do tipo
     */
    public static function getTypeName($nb = 0)
    {
        return __('GLPI PWA Token Cleanup', 'glpipwa');
    }

    /**
     * Retorna informações sobre a tarefa cron
     * 
     * @param string $name Nome da tarefa
     * @return array Informações da tarefa
     */
    public static function cronInfo($name)
    {
        switch ($name) {
            case 'CleanTokens':
                return [
                    'description' => __('Clean expired FCM tokens', 'glpipwa'),
                ];
        }
        return [];
    }

    /**
     * Executa a limpeza de tokens expirados
     * 
     * @param CronTask $task Objeto da tarefa cron
     * @return int 1 se sucesso, 0 se falha
     */
    public static function cronCleanTokens($task)
    {
        try {
            // Limpar tokens não atualizados há mais de 90 dias
            $deleted = PluginGlpipwaToken::cleanExpired(90);
            
            // Registrar volume de trabalho realizado
            $task->addVolume($deleted);
            
            if ($deleted > 0) {
                $task->log(sprintf('Cleaned %d expired FCM tokens', $deleted));
            }
            
            return 1;
        } catch (Exception $e) {
            Toolbox::logInFile('glpipwa', 'GLPI PWA Cron Error: ' . $e->getMessage(), LOG_ERR);
            return 0;
        }
    }

    /**
     * Registra a tarefa cron durante a instalação do plugin
     * 
     * @return bool
     */
    public static function install()
    {
        $cron = new CronTask();
        
        // Verificar se a tarefa já existe
        if (!$cron->getFromDBByCrit(['itemtype' => 'PluginGlpipwaCron', 'name' => 'CleanTokens'])) {
            CronTask::register(
                'PluginGlpipwaCron',
                'CleanTokens',
                DAY_TIMESTAMP,      // Frequência: diária
                [
                    'comment'   => __('Clean expired FCM tokens', 'glpipwa'),
                    'mode'      => CronTask::MODE_INTERNAL,
                    'state'     => CronTask::STATE_WAITING,
                    'hourmin'   => 2,   // Executar às 2h da manhã
                    'hourmax'   => 6,   // Até às 6h da manhã
                ]
            );
        }
        
        return true;
    }

    /**
     * Remove a tarefa cron durante a desinstalação do plugin
     * 
     * @return bool
     */
    public static function uninstall()
    {
        $cron = new CronTask();
        
        if ($cron->getFromDBByCrit(['itemtype' => 'PluginGlpipwaCron', 'name' => 'CleanTokens'])) {
            $cron->delete(['id' => $cron->getID()]);
        }
        
        return true;
    }
}

