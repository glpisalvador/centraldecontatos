<?php

/**
 * Plugin Central de Contatos - GLPI 11 e 12
 * Bloco no chamado, problema e mudança com os contatos do item (requerentes, observadores,
 * entidade): ligar, WhatsApp e e-mail de verdade (as respostas voltam pelo coletor nativo).
 * Cada contato vira um acompanhamento nativo e fica registrado na aba "Contatos".
 */

define('PLUGIN_CENTRALDECONTATOS_VERSION', '2.0.0');
define('PLUGIN_CENTRALDECONTATOS_MIN_GLPI', '11.0.0');
define('PLUGIN_CENTRALDECONTATOS_MAX_GLPI', '12.99.99');

function plugin_init_centraldecontatos(): void
{
    global $PLUGIN_HOOKS;

    // Chave literal: a constante Hooks::CSRF_COMPLIANT não existe no GLPI 12
    $PLUGIN_HOOKS['csrf_compliant']['centraldecontatos'] = true;

    $plugin = new Plugin();
    if (!$plugin->isActivated('centraldecontatos')) {
        return;
    }

    Plugin::registerClass('PluginCentraldecontatosContato', ['addtabon' => ['Ticket', 'Problem', 'Change']]);
    $PLUGIN_HOOKS['config_page']['centraldecontatos'] = 'front/config.form.php';
    $PLUGIN_HOOKS['post_item_form']['centraldecontatos'] = ['PluginCentraldecontatosContato', 'aoExibirFormulario'];

    if (Session::getLoginUserID()) {
        $PLUGIN_HOOKS['add_css']['centraldecontatos'] = ['css/centraldecontatos.css'];
        $PLUGIN_HOOKS['add_javascript']['centraldecontatos'] = ['js/centraldecontatos.js'];
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
            'php'  => ['min' => '8.1'],
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
