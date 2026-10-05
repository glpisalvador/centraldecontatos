<?php

/**
 * Plugin Central de Contatos - menu em Assistência (Conversas e, para administradores, Servidor WhatsApp)
 */
class PluginCentraldecontatosMenu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Central de contatos';
    }

    public static function getMenuName(): string
    {
        return 'Central de contatos';
    }

    public static function getIcon(): string
    {
        return 'ti ti-address-book';
    }

    public static function canView(): bool
    {
        return PluginCentraldecontatosConfig::perfilPermitido() && PluginCentraldecontatosConfig::ligado('wa_ativo');
    }

    public static function getMenuContent(): array
    {
        if (!self::canView()) {
            return [];
        }
        $base = '/plugins/centraldecontatos/front/';
        $menu = [
            'title'   => self::getMenuName(),
            'page'    => $base . 'conversas.php',
            'icon'    => self::getIcon(),
            'options' => [
                'conversas' => ['title' => 'Conversas', 'page' => $base . 'conversas.php', 'icon' => 'ti ti-messages'],
            ],
        ];
        if (PluginCentraldecontatosConfig::ehAdmin()) {
            $menu['options']['whatsapp'] = ['title' => 'Servidor WhatsApp', 'page' => $base . 'whatsapp.php', 'icon' => 'ti ti-server'];
        }
        return $menu;
    }
}
