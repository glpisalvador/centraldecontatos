<?php

/**
 * Plugin Central de Contatos - instalação e desinstalação
 */

function plugin_centraldecontatos_install(): bool
{
    global $DB;

    require_once __DIR__ . '/inc/config.class.php';
    $opcoes = 'ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    // ------------------------------------------------------------ configurações (chave/valor)
    if (!$DB->tableExists('glpi_plugin_centraldecontatos_configs')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_centraldecontatos_configs` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `value` text NULL,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) $opcoes");
    }
    foreach (PluginCentraldecontatosConfig::padroes() as $nome => $valor) {
        if (count($DB->request(['FROM' => 'glpi_plugin_centraldecontatos_configs', 'WHERE' => ['name' => $nome], 'LIMIT' => 1])) === 0) {
            $DB->insert('glpi_plugin_centraldecontatos_configs', ['name' => $nome, 'value' => is_array($valor) ? json_encode($valor, JSON_UNESCAPED_UNICODE) : (string) $valor]);
        }
    }

    // ------------------------------------------------------------ registro de cada contato feito
    if (!$DB->tableExists('glpi_plugin_centraldecontatos_registros')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_centraldecontatos_registros` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `itemtype` varchar(100) NOT NULL DEFAULT '',
            `items_id` int unsigned NOT NULL DEFAULT 0,
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `tipo` varchar(20) NOT NULL DEFAULT '',
            `contato` varchar(255) NOT NULL DEFAULT '',
            `destino` text NULL,
            `resultado` varchar(100) NOT NULL DEFAULT '',
            `assunto` varchar(255) NOT NULL DEFAULT '',
            `observacao` text NULL,
            `sucesso` tinyint(1) NOT NULL DEFAULT 1,
            `itilfollowups_id` int unsigned NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `users_id` (`users_id`),
            KEY `tipo` (`tipo`),
            KEY `date_creation` (`date_creation`)
        ) $opcoes");
    }

    return true;
}

function plugin_centraldecontatos_uninstall(): bool
{
    // Regra do projeto: as tabelas ficam (reinstalar recupera configuração e registros)
    return true;
}
