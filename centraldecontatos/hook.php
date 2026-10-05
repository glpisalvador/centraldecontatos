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

    // ------------------------------------------------------------ WhatsApp: conversas (uma por número)
    if (!$DB->tableExists('glpi_plugin_centraldecontatos_conversas')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_centraldecontatos_conversas` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `telefone` varchar(30) NOT NULL,
            `jid` varchar(80) NOT NULL DEFAULT '',
            `nome` varchar(255) NOT NULL DEFAULT '',
            `nome_whatsapp` varchar(255) NOT NULL DEFAULT '',
            `itemtype` varchar(100) NOT NULL DEFAULT '',
            `items_id` int unsigned NOT NULL DEFAULT 0,
            `nao_lidas` int unsigned NOT NULL DEFAULT 0,
            `ultima_mensagem` varchar(255) NOT NULL DEFAULT '',
            `ultima_direcao` varchar(10) NOT NULL DEFAULT '',
            `date_ultima` timestamp NULL DEFAULT NULL,
            `is_arquivada` tinyint(1) NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `telefone` (`telefone`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `date_ultima` (`date_ultima`),
            KEY `nao_lidas` (`nao_lidas`)
        ) $opcoes");
    }

    // Última mensagem já gravada como acompanhamento no item (versões 3.0.x anteriores não tinham)
    if (count(iterator_to_array($DB->doQuery("SHOW COLUMNS FROM `glpi_plugin_centraldecontatos_conversas` LIKE 'salva_ate_id'"))) === 0) {
        $DB->doQuery("ALTER TABLE `glpi_plugin_centraldecontatos_conversas` ADD COLUMN `salva_ate_id` int unsigned NOT NULL DEFAULT 0 AFTER `nao_lidas`");
    }

    // ------------------------------------------------------------ WhatsApp: mensagens
    if (!$DB->tableExists('glpi_plugin_centraldecontatos_mensagens')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_centraldecontatos_mensagens` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `conversas_id` int unsigned NOT NULL DEFAULT 0,
            `wa_id` varchar(80) NOT NULL DEFAULT '',
            `direcao` varchar(10) NOT NULL DEFAULT 'entrada',
            `origem` varchar(20) NOT NULL DEFAULT 'cliente',
            `tipo` varchar(20) NOT NULL DEFAULT 'texto',
            `conteudo` longtext NULL,
            `midia_arquivo` varchar(255) NOT NULL DEFAULT '',
            `midia_mime` varchar(100) NOT NULL DEFAULT '',
            `midia_nome` varchar(255) NOT NULL DEFAULT '',
            `midia_tamanho` int unsigned NOT NULL DEFAULT 0,
            `citada_wa_id` varchar(80) NOT NULL DEFAULT '',
            `citada_texto` varchar(500) NOT NULL DEFAULT '',
            `reacao_cliente` varchar(16) NOT NULL DEFAULT '',
            `reacao_nossa` varchar(16) NOT NULL DEFAULT '',
            `status` varchar(20) NOT NULL DEFAULT 'recebida',
            `erro` varchar(255) NOT NULL DEFAULT '',
            `lida` tinyint(1) NOT NULL DEFAULT 0,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `itemtype` varchar(100) NOT NULL DEFAULT '',
            `items_id` int unsigned NOT NULL DEFAULT 0,
            `itilfollowups_id` int unsigned NOT NULL DEFAULT 0,
            `date_envio` timestamp NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `conversa` (`conversas_id`, `id`),
            KEY `wa_id` (`wa_id`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `nao_lida` (`conversas_id`, `direcao`, `lida`)
        ) $opcoes");
    }

    // ------------------------------------------------------------ WhatsApp: pedidos de validação enviados pelo chat
    if (!$DB->tableExists('glpi_plugin_centraldecontatos_validacoes')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_centraldecontatos_validacoes` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `conversas_id` int unsigned NOT NULL DEFAULT 0,
            `mensagens_id` int unsigned NOT NULL DEFAULT 0,
            `wa_id` varchar(80) NOT NULL DEFAULT '',
            `itemtype` varchar(50) NOT NULL DEFAULT '',
            `items_id` int unsigned NOT NULL DEFAULT 0,
            `objeto_itemtype` varchar(50) NOT NULL DEFAULT '',
            `objeto_id` int unsigned NOT NULL DEFAULT 0,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `users_id_envio` int unsigned NOT NULL DEFAULT 0,
            `status` varchar(20) NOT NULL DEFAULT 'aguardando',
            `comentario` text NULL,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_resposta` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `conversa_status` (`conversas_id`, `status`),
            KEY `validacao` (`itemtype`, `items_id`),
            KEY `wa_id` (`wa_id`)
        ) $opcoes");
    }

    // ------------------------------------------------------------ servidor WhatsApp
    require_once __DIR__ . '/inc/whatsapp.class.php';
    require_once __DIR__ . '/inc/conversa.class.php';
    require_once __DIR__ . '/inc/mensagem.class.php';
    require_once __DIR__ . '/inc/validacao.class.php';
    PluginCentraldecontatosWhatsapp::token();
    if (PluginCentraldecontatosWhatsapp::prepararPastas() === null) {
        PluginCentraldecontatosWhatsapp::sincronizarApp();
        // Cópia única do que o plugin WhatsApp Empresa já tinha baixado e do histórico dele
        $copiado = PluginCentraldecontatosWhatsapp::importarNodeExistente();
        if ($copiado !== '') {
            Session::addMessageAfterRedirect('Central de Contatos: aproveitado do WhatsApp Empresa: ' . $copiado . '.', false, INFO);
        }
    }
    $historico = PluginCentraldecontatosMensagem::importarWhatsappempresa();
    if ($historico !== '') {
        Session::addMessageAfterRedirect('Central de Contatos: histórico importado do WhatsApp Empresa (' . $historico . ').', false, INFO);
    }

    CronTask::register('PluginCentraldecontatosWhatsapp', 'CentraldecontatosWhatsapp', 5 * MINUTE_TIMESTAMP, [
        'mode'    => CronTask::MODE_EXTERNAL,
        'state'   => CronTask::STATE_WAITING,
        'comment' => 'Central de Contatos: mantém o servidor WhatsApp ligado',
    ]);
    return true;
}

/** As tabelas, a sessão do aparelho e as mídias ficam (reinstalar recupera tudo); o servidor é desligado */
function plugin_centraldecontatos_uninstall(): bool
{
    require_once __DIR__ . '/inc/whatsapp.class.php';
    PluginCentraldecontatosWhatsapp::desligarTudo();
    return true;
}
