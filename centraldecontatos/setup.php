<?php

/**
 * Plugin Central de Contatos - GLPI 11 e 12
 * Bloco no chamado, problema e mudança com os contatos do item (requerentes, observadores,
 * entidade): ligar, WhatsApp e e-mail de verdade (as respostas voltam pelo coletor nativo).
 * Servidor WhatsApp próprio (Node.js + Baileys, administrado pelo GLPI): conversas em tempo real
 * guardadas em tabelas do plugin, com mídias, status de entrega e acompanhamentos no item.
 */

define('PLUGIN_CENTRALDECONTATOS_VERSION', '3.2.0');
define('PLUGIN_CENTRALDECONTATOS_MIN_GLPI', '11.0.0');
define('PLUGIN_CENTRALDECONTATOS_MAX_GLPI', '12.99.99');

function plugin_init_centraldecontatos(): void
{
    global $PLUGIN_HOOKS;

    // Chave literal: a constante Hooks::CSRF_COMPLIANT não existe no GLPI 12
    $PLUGIN_HOOKS['csrf_compliant']['centraldecontatos'] = true;

    // Entrada do servidor WhatsApp (sem sessão; autenticada pelo token interno)
    $publico = '#^/front/webhook\.php#';
    if (class_exists('\Glpi\Http\Firewall')) {
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('centraldecontatos', $publico, \Glpi\Http\Firewall::STRATEGY_NO_CHECK);
    }
    if (class_exists('\Glpi\Http\SessionManager')) {
        \Glpi\Http\SessionManager::registerPluginStatelessPath('centraldecontatos', $publico);
    }

    $plugin = new Plugin();
    if (!$plugin->isActivated('centraldecontatos')) {
        return;
    }

    Plugin::registerClass('PluginCentraldecontatosContato', ['addtabon' => ['Ticket', 'Problem', 'Change']]);
    Plugin::registerClass('PluginCentraldecontatosMenu');
    Plugin::registerClass('PluginCentraldecontatosWhatsapp');
    $PLUGIN_HOOKS['config_page']['centraldecontatos'] = 'front/config.form.php';
    $PLUGIN_HOOKS['post_item_form']['centraldecontatos'] = ['PluginCentraldecontatosContato', 'aoExibirFormulario'];

    if (Session::getLoginUserID()) {
        $PLUGIN_HOOKS['add_css']['centraldecontatos'] = ['css/centraldecontatos.css'];
        $PLUGIN_HOOKS['add_javascript']['centraldecontatos'] = ['js/centraldecontatos.js'];
        if (PluginCentraldecontatosConfig::perfilPermitido() && PluginCentraldecontatosConfig::ligado('wa_ativo')) {
            $PLUGIN_HOOKS['menu_toadd']['centraldecontatos'] = ['helpdesk' => 'PluginCentraldecontatosMenu'];
            // Chat no item e na caixa de conversas, e o aviso de mensagem nova em qualquer página
            $PLUGIN_HOOKS['add_javascript']['centraldecontatos'][] = 'js/conversas.js';
        }
    }
}

function plugin_version_centraldecontatos(): array
{
    return [
        'name'         => 'Central de Contatos',
        'version'      => PLUGIN_CENTRALDECONTATOS_VERSION,
        'author'       => 'GLPI Salvador',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_CENTRALDECONTATOS_MIN_GLPI,
                'max' => PLUGIN_CENTRALDECONTATOS_MAX_GLPI,
            ],
            'php'  => ['min' => '8.1', 'exts' => ['curl' => ['required' => true]]],
        ],
    ];
}

function plugin_centraldecontatos_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, PLUGIN_CENTRALDECONTATOS_MIN_GLPI, '>=');
}

function plugin_centraldecontatos_check_config($verbose = false): bool
{
    return true;
}
