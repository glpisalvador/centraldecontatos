<?php

/**
 * Plugin Central de Contatos - contatos do item, registro dos contatos feitos e a aba "Contatos".
 * O registro (tabela própria) guarda cada ligação, WhatsApp e e-mail; quando configurado, o
 * contato também vira um acompanhamento nativo (ITILFollowup->add: histórico e notificações).
 */
class PluginCentraldecontatosContato extends CommonDBTM
{
    public const TABELA = 'glpi_plugin_centraldecontatos_registros';
    public const TIPOS = [
        'ligacao'  => ['Ligação', 'ti ti-phone-call'],
        'whatsapp' => ['WhatsApp', 'ti ti-brand-whatsapp'],
        'email'    => ['E-mail', 'ti ti-mail'],
    ];

    /** Tabelas de atores: itemtype => [usuários, chave] */
    private const VINCULOS = [
        'Ticket'  => ['glpi_tickets_users', 'tickets_id'],
        'Problem' => ['glpi_problems_users', 'problems_id'],
        'Change'  => ['glpi_changes_users', 'changes_id'],
    ];

    public static function getTypeName($nb = 0): string
    {
        return 'Contatos';
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function getIcon(): string
    {
        return 'ti ti-address-book';
    }

    public static function canView(): bool
    {
        return PluginCentraldecontatosConfig::perfilPermitido();
    }

    public static function canCreate(): bool
    {
        return PluginCentraldecontatosConfig::perfilPermitido();
    }

    /** A pessoa pode usar a central neste item (perfil liberado e pode acompanhar o item) */
    public static function podeUsar(CommonDBTM $item): bool
    {
        return $item instanceof CommonITILObject
            && isset(self::VINCULOS[get_class($item)])
            && !$item->isNewItem()
            && PluginCentraldecontatosConfig::perfilPermitido()
            && PluginCentraldecontatosConfig::itemAtivo(get_class($item))
            && $item->canViewItem()
            && $item->canAddFollowups();
    }

    // =====================================================================
    // Contatos do item
    // =====================================================================

    /**
     * Requerentes e observadores (telefones e e-mails do cadastro, e-mails alternativos dos atores),
     * a entidade e, se o plugin unidadedaentidade estiver instalado, o requerente e o telefone dele.
     */
    public static function contatos(CommonITILObject $item): array
    {
        global $DB;
        [$tabela, $chave] = self::VINCULOS[get_class($item)];
        $papeis = [CommonITILActor::REQUESTER => 'Requerente', CommonITILActor::OBSERVER => 'Observador'];
        $lista = [];
        foreach ($DB->request([
            'SELECT' => ['users_id', 'type', 'alternative_email'],
            'FROM'   => $tabela,
            'WHERE'  => [$chave => (int) $item->getID(), 'type' => array_keys($papeis)],
            'ORDER'  => ['type ASC', 'id ASC'],
        ]) as $a) {
            $uid = (int) $a['users_id'];
            $alt = trim((string) $a['alternative_email']);
            $k = $uid > 0 ? 'u' . $uid : 'e' . mb_strtolower($alt);
            if ($uid <= 0 && !filter_var($alt, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (!isset($lista[$k])) {
                $lista[$k] = ['chave' => $k, 'nome' => $uid > 0 ? '' : $alt, 'papel' => $papeis[(int) $a['type']], 'telefones' => [], 'emails' => [], 'users_id' => $uid];
            } elseif ((int) $a['type'] === CommonITILActor::REQUESTER) {
                $lista[$k]['papel'] = 'Requerente';
            }
            if ($alt !== '' && filter_var($alt, FILTER_VALIDATE_EMAIL)) {
                $lista[$k]['emails'][] = $alt;
            }
        }
        $ids = array_values(array_filter(array_map(fn($c) => $c['users_id'], $lista)));
        if ($ids) {
            foreach ($DB->request(['SELECT' => ['id', 'name', 'firstname', 'realname', 'phone', 'phone2', 'mobile'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => $ids]]) as $u) {
                $k = 'u' . (int) $u['id'];
                $nome = trim(trim((string) $u['firstname']) . ' ' . trim((string) $u['realname']));
                $lista[$k]['nome'] = $nome !== '' ? $nome : (string) $u['name'];
                foreach (['mobile' => 'Celular', 'phone' => 'Telefone', 'phone2' => 'Telefone 2'] as $campo => $rotulo) {
                    if (trim((string) $u[$campo]) !== '' && PluginCentraldecontatosConfig::numeroTel((string) $u[$campo]) !== '') {
                        $lista[$k]['telefones'][] = ['rotulo' => $rotulo, 'numero' => trim((string) $u[$campo])];
                    }
                }
            }
            foreach ($DB->request(['SELECT' => ['users_id', 'email', 'is_default'], 'FROM' => 'glpi_useremails', 'WHERE' => ['users_id' => $ids], 'ORDER' => ['is_default DESC', 'id ASC']]) as $m) {
                $email = trim((string) $m['email']);
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $lista['u' . (int) $m['users_id']]['emails'][] = $email;
                }
            }
        }

        // Plugin unidadedaentidade: requerente e telefone informados na abertura do chamado
        if ($item instanceof Ticket && $DB->tableExists('glpi_plugin_unidadedaentidade_unidades')) {
            foreach ($DB->request(['FROM' => 'glpi_plugin_unidadedaentidade_unidades', 'WHERE' => ['tickets_id' => (int) $item->getID()], 'LIMIT' => 1]) as $r) {
                $tel = trim((string) ($r['telefone'] ?? ''));
                if ($tel !== '' && PluginCentraldecontatosConfig::numeroTel($tel) !== '') {
                    $lista['unidade'] = [
                        'chave'     => 'unidade',
                        'nome'      => trim((string) ($r['requerente'] ?? '')) ?: 'Contato da unidade',
                        'papel'     => 'Informado na abertura' . (trim((string) ($r['setor'] ?? '')) !== '' ? ' · ' . trim((string) $r['setor']) : ''),
                        'telefones' => [['rotulo' => 'Telefone', 'numero' => $tel]],
                        'emails'    => [],
                        'users_id'  => 0,
                    ];
                }
            }
        }

        // Entidade do item
        $ent = new Entity();
        if ($ent->getFromDB((int) $item->fields['entities_id'])) {
            $tel = trim((string) ($ent->fields['phonenumber'] ?? ''));
            $email = trim((string) ($ent->fields['email'] ?? ''));
            if (($tel !== '' && PluginCentraldecontatosConfig::numeroTel($tel) !== '') || filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $lista['entidade'] = [
                    'chave'     => 'entidade',
                    'nome'      => (string) $ent->fields['name'],
                    'papel'     => 'Entidade',
                    'telefones' => $tel !== '' ? [['rotulo' => 'Telefone', 'numero' => $tel]] : [],
                    'emails'    => filter_var($email, FILTER_VALIDATE_EMAIL) ? [$email] : [],
                    'users_id'  => 0,
                ];
            }
        }

        foreach ($lista as &$c) {
            $c['emails'] = array_values(array_unique($c['emails']));
            $vistos = [];
            $c['telefones'] = array_values(array_filter($c['telefones'], function ($t) use (&$vistos) {
                $d = PluginCentraldecontatosConfig::numeroTel($t['numero']);
                if (isset($vistos[$d])) {
                    return false;
                }
                $vistos[$d] = true;
                return true;
            }));
            foreach ($c['telefones'] as &$t) {
                $t['exibicao'] = PluginCentraldecontatosConfig::numeroExibicao($t['numero']);
                $t['tel'] = PluginCentraldecontatosConfig::numeroTel($t['numero']);
                $t['whatsapp'] = PluginCentraldecontatosConfig::numeroWhatsapp($t['numero']);
            }
            unset($t);
        }
        unset($c);
        return array_values($lista);
    }

    /** Valores das variáveis dos modelos ({nome} fica para o JS, por contato) */
    public static function variaveis(CommonITILObject $item): array
    {
        return [
            '{id}'       => (string) $item->getID(),
            '{titulo}'   => (string) $item->fields['name'],
            '{tipo}'     => PluginCentraldecontatosConfig::ITENS_SINGULAR[get_class($item)] ?? 'chamado',
            '{tecnico}'  => (string) getUserName((int) Session::getLoginUserID()),
            '{entidade}' => (string) Dropdown::getDropdownName('glpi_entities', (int) $item->fields['entities_id']),
        ];
    }

    // =====================================================================
    // Tela
    // =====================================================================

    /** Bloco da central (compacto no formulário, completo na aba) */
    public static function bloco(CommonITILObject $item, bool $naAba): string
    {
        $C = PluginCentraldecontatosConfig::class;
        $e = [$C, 'e'];
        $contatos = self::contatos($item);
        $vars = self::variaveis($item);
        $dados = [
            'ajax'       => $C::url('ajax.php'),
            'itemtype'   => get_class($item),
            'items_id'   => (int) $item->getID(),
            'variaveis'  => $vars,
            'whatsapp'   => (string) $C::getConfig('modelo_whatsapp'),
            'assunto'    => strtr((string) $C::getConfig('modelo_assunto'), $vars + ['{nome}' => '']),
            'resultados' => array_values(array_filter(array_map('strval', $C::getArrayConfig('resultados')))),
            'registrar'  => ['ligacao' => $C::ligado('registrar_ligacao'), 'whatsapp' => $C::ligado('registrar_whatsapp'), 'email' => $C::ligado('registrar_email')],
            'remetente'  => PluginCentraldecontatosEmail::remetente() !== null,
            'codigo_pais' => (string) $C::getConfig('codigo_pais'),
            'contatos'   => $contatos,
            'token'      => $C::tokenCsrf(),
        ];
        $h = '<div class="centraldecontatos' . ($naAba ? ' centraldecontatos-aba' : '') . '" data-centraldecontatos>';
        if (!$naAba) {
            $h .= '<div class="centraldecontatos-titulo"><i class="ti ti-address-book"></i><span>Central de contatos</span><span class="centraldecontatos-n">' . count($contatos) . '</span></div>';
        }
        if (!$contatos) {
            $h .= '<div class="centraldecontatos-vazio">Nenhum telefone ou e-mail nos requerentes, observadores ou na entidade.</div>';
        } else {
            $h .= '<ul class="centraldecontatos-lista">';
            foreach ($contatos as $i => $c) {
                $h .= '<li class="centraldecontatos-contato"><div class="centraldecontatos-contato-nome"><span>' . $e($c['nome']) . '</span><small>' . $e($c['papel']) . '</small></div>';
                foreach ($c['telefones'] as $j => $t) {
                    $h .= '<div class="centraldecontatos-canal"><i class="ti ti-phone"></i><span class="centraldecontatos-valor" title="' . $e($t['rotulo']) . '">' . $e($t['exibicao']) . '</span><small>' . $e($t['rotulo']) . '</small>'
                        . '<span class="centraldecontatos-acoes">'
                        . '<a class="centraldecontatos-acao" href="tel:' . $e($t['tel']) . '" data-centraldecontatos-ligar="' . $i . ':' . $j . '" title="Ligar"><i class="ti ti-phone-call"></i></a>'
                        . '<a class="centraldecontatos-acao" href="#" data-centraldecontatos-whatsapp="' . $i . ':' . $j . '" title="WhatsApp"><i class="ti ti-brand-whatsapp"></i></a>'
                        . '<a class="centraldecontatos-acao" href="#" data-centraldecontatos-copiar="' . $e($t['exibicao']) . '" title="Copiar"><i class="ti ti-copy"></i></a>'
                        . '</span></div>';
                }
                foreach ($c['emails'] as $email) {
                    $h .= '<div class="centraldecontatos-canal"><i class="ti ti-mail"></i><span class="centraldecontatos-valor">' . $e($email) . '</span>'
                        . '<span class="centraldecontatos-acoes">'
                        . '<a class="centraldecontatos-acao" href="#" data-centraldecontatos-email="' . $e($email) . '" data-nome="' . $e($c['nome']) . '" title="Enviar e-mail"><i class="ti ti-send"></i></a>'
                        . '<a class="centraldecontatos-acao" href="#" data-centraldecontatos-copiar="' . $e($email) . '" title="Copiar"><i class="ti ti-copy"></i></a>'
                        . '</span></div>';
                }
                $h .= '</li>';
            }
            $h .= '</ul>';
        }
        $h .= '<div class="centraldecontatos-outro"><input type="text" class="form-control form-control-sm" placeholder="Outro número ou e-mail" data-centraldecontatos-outro>'
            . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-centraldecontatos-outro-acao="ligar" title="Ligar"><i class="ti ti-phone-call"></i></button>'
            . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-centraldecontatos-outro-acao="whatsapp" title="WhatsApp"><i class="ti ti-brand-whatsapp"></i></button>'
            . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-centraldecontatos-outro-acao="email" title="E-mail"><i class="ti ti-mail"></i></button></div>';
        $h .= '<script type="application/json" data-centraldecontatos-dados>' . json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
        return $h . '</div>';
    }

    /** post_item_form: bloco no formulário do chamado, problema ou mudança (o JS o move para uma seção própria) */
    public static function aoExibirFormulario(array $params): void
    {
        try {
            $item = $params['item'] ?? null;
            if ($item instanceof CommonITILObject && self::podeUsar($item) && !isset($params['options']['parent'])) {
                echo self::bloco($item, false);
            }
        } catch (\Throwable $e) {
            error_log('Plugin centraldecontatos: ' . $e->getMessage());
        }
    }

    // =====================================================================
    // Aba "Contatos"
    // =====================================================================

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof CommonITILObject || $item->isNewItem() || !PluginCentraldecontatosConfig::perfilPermitido() || !PluginCentraldecontatosConfig::itemAtivo(get_class($item))) {
            return '';
        }
        $n = !empty($_SESSION['glpishow_count_on_tabs']) ? countElementsInTable(self::TABELA, ['itemtype' => get_class($item), 'items_id' => (int) $item->getID()]) : 0;
        return self::createTabEntry('Contatos', $n, null, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof CommonITILObject) {
            return true;
        }
        $C = PluginCentraldecontatosConfig::class;
        $e = [$C, 'e'];
        echo '<div class="centraldecontatos-pagina">';
        if (self::podeUsar($item)) {
            echo '<div class="card centraldecontatos-card"><div class="card-header"><h5><i class="ti ti-address-book"></i> Contatos do ' . $e($C::ITENS_SINGULAR[get_class($item)] ?? 'item') . '</h5></div><div class="card-body">' . self::bloco($item, true) . '</div></div>';
        }
        $linhas = self::registros(get_class($item), (int) $item->getID());
        echo '<div class="card centraldecontatos-card"><div class="card-header"><h5><i class="ti ti-history"></i> Contatos registrados</h5></div><div class="card-body p-0">';
        if (!$linhas) {
            echo '<div class="centraldecontatos-vazio">Nenhum contato registrado ainda.</div>';
        } else {
            echo '<div class="table-responsive"><table class="table table-sm table-hover centraldecontatos-tabela"><thead><tr><th>Quando</th><th>Tipo</th><th>Contato</th><th>Destino</th><th>Resultado</th><th>Por</th><th></th></tr></thead><tbody>';
            foreach ($linhas as $r) {
                [$rotulo, $icone] = self::TIPOS[$r['tipo']] ?? [$r['tipo'], 'ti ti-point'];
                $resultado = $r['tipo'] === 'email' ? ($r['sucesso'] ? 'Enviado' : 'Falhou') : (string) $r['resultado'];
                echo '<tr><td>' . $e(Html::convDateTime((string) $r['date_creation'])) . '</td>'
                    . '<td><span class="centraldecontatos-tipo centraldecontatos-tipo-' . $e($r['tipo']) . '"><i class="' . $e($icone) . '"></i> ' . $e($rotulo) . '</span></td>'
                    . '<td>' . $e($r['contato']) . '</td>'
                    . '<td class="centraldecontatos-destino">' . $e($r['destino']) . ($r['assunto'] !== '' ? '<small>' . $e($r['assunto']) . '</small>' : '') . '</td>'
                    . '<td>' . ($resultado !== '' ? '<span class="centraldecontatos-selo' . (!$r['sucesso'] ? ' centraldecontatos-selo-erro' : '') . '">' . $e($resultado) . '</span>' : '') . ($r['observacao'] !== '' && $r['observacao'] !== null ? '<small class="centraldecontatos-obs">' . $e($r['observacao']) . '</small>' : '') . '</td>'
                    . '<td>' . $e(getUserName((int) $r['users_id'])) . '</td>'
                    . '<td class="text-end">' . ((int) $r['itilfollowups_id'] > 0 ? '<span class="centraldecontatos-pequeno" title="Registrado como acompanhamento"><i class="ti ti-message"></i></span>' : '') . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></div></div>';
        return true;
    }

    public static function registros(string $itemtype, int $id): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::TABELA, 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $id], 'ORDER' => 'id DESC', 'LIMIT' => 300]), false);
    }

    // =====================================================================
    // Registro (tabela própria + acompanhamento nativo)
    // =====================================================================

    /**
     * Registra um contato. $dados: contato, destino, resultado, observacao, assunto, mensagem, sucesso, corpo.
     * Retorna o id do acompanhamento criado (0 se não foi configurado para registrar).
     */
    public static function registrar(CommonITILObject $item, string $tipo, array $dados): int
    {
        global $DB;
        $C = PluginCentraldecontatosConfig::class;
        $e = [$C, 'e'];
        $followup = 0;
        $sucesso = !array_key_exists('sucesso', $dados) || !empty($dados['sucesso']);
        if ($sucesso && $C::ligado('registrar_' . $tipo)) {
            $quem = trim((string) ($dados['contato'] ?? ''));
            $destino = trim((string) ($dados['destino'] ?? ''));
            $para = ($quem !== '' && $quem !== $destino ? $e($quem) . ' · ' : '') . $e($destino);
            switch ($tipo) {
                case 'ligacao':
                    $html = '<p><strong>Ligação</strong> para ' . $para . '</p>'
                        . (trim((string) ($dados['resultado'] ?? '')) !== '' ? '<p>Resultado: <strong>' . $e($dados['resultado']) . '</strong></p>' : '');
                    break;
                case 'whatsapp':
                    $html = '<p><strong>Mensagem por WhatsApp</strong> para ' . $para . '</p>'
                        . (trim((string) ($dados['mensagem'] ?? '')) !== '' ? '<blockquote>' . nl2br($e(trim((string) $dados['mensagem']))) . '</blockquote>' : '');
                    break;
                default:
                    $html = '<p><strong>E-mail enviado</strong> para ' . $e($destino) . '</p>'
                        . '<p>Assunto: <strong>' . $e($dados['assunto'] ?? '') . '</strong></p><hr>'
                        . \Glpi\RichText\RichText::getSafeHtml((string) ($dados['corpo'] ?? ''));
            }
            if (trim((string) ($dados['observacao'] ?? '')) !== '') {
                $html .= '<p>' . nl2br($e(trim((string) $dados['observacao']))) . '</p>';
            }
            $f = new ITILFollowup();
            $followup = (int) $f->add([
                'itemtype'   => get_class($item),
                'items_id'   => (int) $item->getID(),
                'content'    => $html,
                'is_private' => $C::ligado($tipo === 'email' ? 'email_privado' : 'privado') ? 1 : 0,
                '_assinaturausuario_ignorar' => 1,
            ]);
        }
        $DB->insert(self::TABELA, [
            'itemtype'         => get_class($item),
            'items_id'         => (int) $item->getID(),
            'entities_id'      => (int) $item->fields['entities_id'],
            'users_id'         => (int) Session::getLoginUserID(),
            'tipo'             => $tipo,
            'contato'          => mb_substr(trim((string) ($dados['contato'] ?? '')), 0, 255),
            'destino'          => mb_substr(trim((string) ($dados['destino'] ?? '')), 0, 2000),
            'resultado'        => mb_substr(trim((string) ($dados['resultado'] ?? '')), 0, 100),
            'assunto'          => mb_substr(trim((string) ($dados['assunto'] ?? '')), 0, 255),
            'observacao'       => mb_substr(trim((string) ($dados['observacao'] ?? '')), 0, 5000),
            'sucesso'          => $sucesso ? 1 : 0,
            'itilfollowups_id' => $followup,
            'date_creation'    => date('Y-m-d H:i:s'),
        ]);
        return $followup;
    }
}
