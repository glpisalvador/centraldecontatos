<?php

/**
 * Plugin Central de Contatos - configurações e utilitários comuns
 */
class PluginCentraldecontatosConfig extends CommonDBTM
{
    // $rightname não é redeclarada: é tipada (string) no GLPI 12 e sem tipo no 11.

    public const TABELA = 'glpi_plugin_centraldecontatos_configs';
    public const ITENS = ['Ticket' => 'Chamados', 'Problem' => 'Problemas', 'Change' => 'Mudanças'];
    public const ITENS_SINGULAR = ['Ticket' => 'chamado', 'Problem' => 'problema', 'Change' => 'mudança'];
    public const VARIAVEIS = [
        '{id}'       => 'Número do item',
        '{titulo}'   => 'Título do item',
        '{tipo}'     => 'chamado, problema ou mudança',
        '{nome}'     => 'Nome do contato',
        '{tecnico}'  => 'Seu nome',
        '{entidade}' => 'Entidade do item',
    ];

    public static function getTypeName($nb = 0): string
    {
        return 'Central de Contatos';
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function canView(): bool
    {
        return self::ehAdmin();
    }

    public static function canCreate(): bool
    {
        return self::ehAdmin();
    }

    public static function canUpdate(): bool
    {
        return self::ehAdmin();
    }

    public static function canDelete(): bool
    {
        return self::ehAdmin();
    }

    public static function canPurge(): bool
    {
        return self::ehAdmin();
    }

    public static function ehAdmin(): bool
    {
        return Session::getLoginUserID() && Session::haveRight('config', UPDATE);
    }

    // =====================================================================
    // Chave/valor
    // =====================================================================

    public static function padroes(): array
    {
        return [
            'perfis'             => [],
            'itens'              => array_keys(self::ITENS),
            'registrar_ligacao'  => '1',
            'registrar_whatsapp' => '1',
            'registrar_email'    => '1',
            'privado'            => '1',
            'email_privado'      => '0',
            'codigo_pais'        => '55',
            'modelo_whatsapp'    => 'Olá, {nome}! Aqui é {tecnico}, sobre o {tipo} #{id} - {titulo}.',
            'modelo_assunto'     => '[GLPI #{id}] {titulo}',
            'resultados'         => ['Atendeu', 'Não atendeu', 'Caixa postal', 'Ocupado', 'Número errado'],
            'remetente_email'    => '',
            'remetente_nome'     => '',
            'responder_para'     => '',
        ];
    }

    private static ?array $centraldecontatosConfigs = null;

    public static function getConfig(string $name, $default = null)
    {
        global $DB;
        if (self::$centraldecontatosConfigs === null) {
            self::$centraldecontatosConfigs = [];
            if ($DB->tableExists(self::TABELA)) {
                foreach ($DB->request(['SELECT' => ['name', 'value'], 'FROM' => self::TABELA]) as $row) {
                    self::$centraldecontatosConfigs[$row['name']] = $row['value'];
                }
            }
        }
        if (array_key_exists($name, self::$centraldecontatosConfigs)) {
            return self::$centraldecontatosConfigs[$name];
        }
        if ($default === null) {
            $padrao = self::padroes()[$name] ?? null;
            return is_array($padrao) ? json_encode($padrao, JSON_UNESCAPED_UNICODE) : $padrao;
        }
        return $default;
    }

    public static function setConfig(string $name, $value): bool
    {
        global $DB;
        if (count($DB->request(['FROM' => self::TABELA, 'WHERE' => ['name' => $name], 'LIMIT' => 1])) > 0) {
            $ok = (bool) $DB->update(self::TABELA, ['value' => $value], ['name' => $name]);
        } else {
            $ok = (bool) $DB->insert(self::TABELA, ['name' => $name, 'value' => $value]);
        }
        self::$centraldecontatosConfigs = null;
        return $ok;
    }

    public static function getAllConfigs(): array
    {
        global $DB;
        $todas = [];
        foreach ($DB->request(['FROM' => self::TABELA]) as $row) {
            $todas[$row['name']] = $row['value'];
        }
        return $todas;
    }

    public static function getArrayConfig(string $name): array
    {
        $lista = json_decode((string) self::getConfig($name), true);
        return is_array($lista) ? $lista : [];
    }

    public static function setArrayConfig(string $name, array $value): bool
    {
        return self::setConfig($name, json_encode(array_values($value), JSON_UNESCAPED_UNICODE));
    }

    public static function ids(string $name): array
    {
        return array_values(array_unique(array_filter(array_map('intval', self::getArrayConfig($name)), fn($v) => $v > 0)));
    }

    public static function ligado(string $name): bool
    {
        return self::getConfig($name) === '1';
    }

    /** Perfil ativo pode usar a central (lista vazia = todos os perfis da interface padrão) */
    public static function perfilPermitido(): bool
    {
        if (!Session::getLoginUserID()) {
            return false;
        }
        $perfis = self::ids('perfis');
        if (!$perfis) {
            return ($_SESSION['glpiactiveprofile']['interface'] ?? '') !== 'helpdesk';
        }
        return in_array((int) ($_SESSION['glpiactiveprofile']['id'] ?? 0), $perfis, true);
    }

    public static function itemAtivo(string $itemtype): bool
    {
        return in_array($itemtype, array_intersect(array_keys(self::ITENS), self::getArrayConfig('itens')), true);
    }

    // =====================================================================
    // Listas e utilitários
    // =====================================================================

    /** glpi_profiles não tem is_deleted */
    public static function listarPerfis(): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'interface'], 'FROM' => 'glpi_profiles', 'ORDER' => 'name ASC']) as $r) {
            $lista[(int) $r['id']] = ['rotulo' => (string) $r['name'], 'detalhe' => $r['interface'] === 'helpdesk' ? 'simplificada' : 'padrão'];
        }
        return $lista;
    }

    public static function e($texto): string
    {
        return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
    }

    public static function url(string $arquivo, array $params = []): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/centraldecontatos/front/' . $arquivo . ($params ? '?' . http_build_query($params) : '');
    }

    /** GLPI 11 exige token CSRF nos POST; no 12 a proteção é por cabeçalho e o token foi removido */
    public static function tokenCsrf(): string
    {
        return version_compare(GLPI_VERSION, '12.0.0-dev', '<') ? Session::getNewCSRFToken() : '';
    }

    /** Só dígitos (com + inicial preservado) para o link tel: */
    public static function numeroTel(string $numero): string
    {
        $numero = trim($numero);
        $mais = str_starts_with($numero, '+');
        $digitos = (string) preg_replace('/\D+/', '', $numero);
        return $digitos === '' ? '' : ($mais ? '+' : '') . $digitos;
    }

    /** Número internacional para o WhatsApp: acrescenta o código do país quando falta */
    public static function numeroWhatsapp(string $numero): string
    {
        $mais = str_starts_with(trim($numero), '+');
        $d = (string) preg_replace('/\D+/', '', $numero);
        $d = ltrim($d, '0');
        $pais = (string) preg_replace('/\D+/', '', (string) self::getConfig('codigo_pais'));
        if ($d === '') {
            return '';
        }
        // Até 11 dígitos é número nacional (DDD + número): recebe o código do país.
        // Com "+" ou mais de 11 dígitos, o código do país já está no número.
        if (!$mais && $pais !== '' && strlen($d) <= 11) {
            $d = $pais . $d;
        }
        return $d;
    }

    /** (71) 99999-0000 para números brasileiros de 10/11 dígitos; outros ficam como estão */
    public static function numeroExibicao(string $numero): string
    {
        $d = (string) preg_replace('/\D+/', '', $numero);
        if (strlen($d) === 11) {
            return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 5) . '-' . substr($d, 7);
        }
        if (strlen($d) === 10) {
            return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 4) . '-' . substr($d, 6);
        }
        return trim($numero);
    }

    /**
     * Multiselect com pesquisa, marcar todos e selecionados primeiro.
     * $opcoes: [valor => rótulo] ou [valor => ['rotulo' => ..., 'detalhe' => ...]]
     */
    public static function multiselect(string $name, array $opcoes, array $selecionados, string $placeholder = 'Selecione...'): string
    {
        $selecionados = array_map('strval', $selecionados);
        $itens = [];
        foreach ($opcoes as $valor => $o) {
            $o = is_array($o) ? $o : ['rotulo' => $o];
            $itens[] = ['valor' => (string) $valor, 'marcado' => in_array((string) $valor, $selecionados, true)] + $o + ['detalhe' => ''];
        }
        usort($itens, fn($a, $b) => [$b['marcado'], mb_strtolower($a['rotulo'])] <=> [$a['marcado'], mb_strtolower($b['rotulo'])]);
        $h = '<div class="centraldecontatos-ms" data-centraldecontatos-ms data-placeholder="' . self::e($placeholder) . '">';
        $h .= '<input type="hidden" name="' . self::e($name) . '[]" value="-1">';
        $h .= '<button type="button" class="centraldecontatos-ms-cabecalho form-select form-select-sm" data-centraldecontatos-ms-abrir><span class="centraldecontatos-ms-texto"></span></button>';
        $h .= '<div class="centraldecontatos-ms-dropdown" hidden>';
        $h .= '<div class="centraldecontatos-ms-topo"><input type="text" class="form-control form-control-sm centraldecontatos-ms-busca" placeholder="Pesquisar..." autocomplete="off"></div>';
        $h .= '<label class="centraldecontatos-ms-todos"><input type="checkbox" class="centraldecontatos-check" data-centraldecontatos-ms-todos> Marcar/desmarcar todos</label>';
        $h .= '<div class="centraldecontatos-ms-opcoes">';
        foreach ($itens as $i) {
            $h .= '<label class="centraldecontatos-ms-opcao' . ($i['marcado'] ? ' selected' : '') . '" data-label="' . self::e(mb_strtolower($i['rotulo'] . ' ' . $i['detalhe'])) . '">'
                . '<input type="checkbox" class="centraldecontatos-check" name="' . self::e($name) . '[]" value="' . self::e($i['valor']) . '"' . ($i['marcado'] ? ' checked' : '') . '>'
                . '<span class="centraldecontatos-ms-rotulo">' . self::e($i['rotulo']) . ($i['detalhe'] !== '' ? ' <small>' . self::e($i['detalhe']) . '</small>' : '') . '</span>'
                . '</label>';
        }
        $h .= '</div></div><div class="centraldecontatos-ms-contador"></div></div>';
        return $h;
    }

    public static function idsPost(string $campo): array
    {
        $ids = array_map('intval', (array) ($_POST[$campo] ?? []));
        return array_values(array_unique(array_filter($ids, fn($v) => $v > 0)));
    }
}
