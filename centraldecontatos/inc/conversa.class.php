<?php

/**
 * Plugin Central de Contatos - conversas de WhatsApp (uma por número), com o item vinculado
 * (chamado, problema ou mudança) e a contagem de mensagens não lidas no GLPI.
 */
class PluginCentraldecontatosConversa extends CommonDBTM
{
    public const TABELA = 'glpi_plugin_centraldecontatos_conversas';

    public static function getTypeName($nb = 0): string
    {
        return 'Conversas';
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function getIcon(): string
    {
        return 'ti ti-brand-whatsapp';
    }

    public static function canView(): bool
    {
        return PluginCentraldecontatosConfig::perfilPermitido();
    }

    public static function canCreate(): bool
    {
        return PluginCentraldecontatosConfig::perfilPermitido();
    }

    public static function obter(int $id): ?array
    {
        global $DB;
        $r = $DB->request(['FROM' => self::TABELA, 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current();
        return $r ?: null;
    }

    public static function porTelefone(string $chave): ?array
    {
        global $DB;
        if ($chave === '') {
            return null;
        }
        $r = $DB->request(['FROM' => self::TABELA, 'WHERE' => ['telefone' => $chave], 'LIMIT' => 1])->current();
        return $r ?: null;
    }

    /** Conversa do número (cria se não existir); atualiza jid e nomes quando vierem */
    public static function garantir(string $telefone, string $jid = '', string $nomeWhatsapp = '', string $nomeGlpi = ''): array
    {
        global $DB;
        $chave = PluginCentraldecontatosConfig::chaveTelefone($telefone);
        if ($chave === '') {
            throw new RuntimeException('Telefone inválido.');
        }
        $c = self::porTelefone($chave);
        if (!$c) {
            $DB->insert(self::TABELA, [
                'telefone'      => $chave,
                'jid'           => mb_substr($jid, 0, 80),
                'nome_whatsapp' => mb_substr(trim($nomeWhatsapp), 0, 255),
                'nome'          => mb_substr(trim($nomeGlpi), 0, 255),
                'date_creation' => date('Y-m-d H:i:s'),
            ]);
            return self::obter((int) $DB->insertId());
        }
        $muda = [];
        if ($jid !== '' && $jid !== (string) $c['jid'] && !str_ends_with($jid, '@lid')) {
            $muda['jid'] = mb_substr($jid, 0, 80);
        }
        if (trim($nomeWhatsapp) !== '' && trim($nomeWhatsapp) !== (string) $c['nome_whatsapp']) {
            $muda['nome_whatsapp'] = mb_substr(trim($nomeWhatsapp), 0, 255);
        }
        if (trim($nomeGlpi) !== '' && trim((string) $c['nome']) === '') {
            $muda['nome'] = mb_substr(trim($nomeGlpi), 0, 255);
        }
        if ($muda) {
            $DB->update(self::TABELA, $muda, ['id' => $c['id']]);
            $c = array_merge($c, $muda);
        }
        return $c;
    }

    public static function rotulo(array $c): string
    {
        foreach (['nome', 'nome_whatsapp'] as $k) {
            if (trim((string) ($c[$k] ?? '')) !== '') {
                return trim((string) $c[$k]);
            }
        }
        return PluginCentraldecontatosConfig::telefoneExibicao((string) $c['telefone']);
    }

    public static function vincular(int $id, string $itemtype, int $items_id): bool
    {
        global $DB;
        if ($itemtype !== '' && !isset(PluginCentraldecontatosConfig::ITENS[$itemtype])) {
            return false;
        }
        return (bool) $DB->update(self::TABELA, ['itemtype' => $itemtype, 'items_id' => $itemtype === '' ? 0 : $items_id], ['id' => $id]);
    }

    /** Item vinculado, se ainda estiver aberto (não solucionado nem fechado) */
    public static function itemAberto(array $c): ?CommonITILObject
    {
        $tipo = (string) ($c['itemtype'] ?? '');
        if ($tipo === '' || !isset(PluginCentraldecontatosConfig::ITENS[$tipo]) || (int) $c['items_id'] <= 0) {
            return null;
        }
        $item = new $tipo();
        if (!$item->getFromDB((int) $c['items_id']) || !empty($item->fields['is_deleted']) || !$item->isNotSolved()) {
            return null;
        }
        return $item;
    }

    public static function rotuloItem(string $itemtype, int $id): string
    {
        if ($itemtype === '' || $id <= 0 || !isset(PluginCentraldecontatosConfig::ITENS_SINGULAR[$itemtype]) || countElementsInTable(getTableForItemType($itemtype), ['id' => $id]) === 0) {
            return '';
        }
        $nome = Dropdown::getDropdownName(getTableForItemType($itemtype), $id);
        return ucfirst(PluginCentraldecontatosConfig::ITENS_SINGULAR[$itemtype]) . ' #' . $id . ($nome !== '' && $nome !== '&nbsp;' ? ' - ' . $nome : '');
    }

    public static function urlItem(string $itemtype, int $id): string
    {
        return self::rotuloItem($itemtype, $id) !== '' ? $itemtype::getFormURLWithID($id) : '';
    }

    /** Depois de cada mensagem: prévia, data e não lidas */
    public static function tocar(int $id, string $previa, string $direcao, bool $contarNaoLida): void
    {
        global $DB;
        $campos = [
            'ultima_mensagem' => mb_substr($previa, 0, 255),
            'ultima_direcao'  => $direcao,
            'date_ultima'     => date('Y-m-d H:i:s'),
            'is_arquivada'    => 0,
        ];
        if ($contarNaoLida) {
            $campos['nao_lidas'] = new \Glpi\DBAL\QueryExpression($DB->quoteName('nao_lidas') . ' + 1');
        }
        $DB->update(self::TABELA, $campos, ['id' => $id]);
    }

    /** Zera as não lidas e devolve as recebidas ainda sem confirmação de leitura ao cliente */
    public static function marcarLida(int $id): array
    {
        global $DB;
        $c = self::obter($id);
        if (!$c) {
            return [];
        }
        $itens = [];
        $ids = [];
        foreach ($DB->request(['SELECT' => ['id', 'wa_id'], 'FROM' => PluginCentraldecontatosMensagem::TABELA, 'WHERE' => ['conversas_id' => $id, 'direcao' => 'entrada', 'lida' => 0], 'ORDER' => 'id DESC', 'LIMIT' => 100]) as $m) {
            $ids[] = (int) $m['id'];
            if ((string) $m['wa_id'] !== '' && (string) $c['jid'] !== '') {
                $itens[] = ['jid' => (string) $c['jid'], 'wa_id' => (string) $m['wa_id']];
            }
        }
        if ($ids) {
            $DB->update(PluginCentraldecontatosMensagem::TABELA, ['lida' => 1], ['id' => $ids]);
        }
        if ((int) $c['nao_lidas'] !== 0) {
            $DB->update(self::TABELA, ['nao_lidas' => 0], ['id' => $id]);
        }
        return $itens;
    }

    public static function arquivar(int $id, bool $sim): bool
    {
        global $DB;
        return (bool) $DB->update(self::TABELA, ['is_arquivada' => $sim ? 1 : 0], ['id' => $id]);
    }

    public static function naoLidasTotal(): int
    {
        global $DB;
        $r = $DB->request(['SELECT' => [new \Glpi\DBAL\QueryExpression('COALESCE(SUM(' . $DB->quoteName('nao_lidas') . '), 0) AS total')], 'FROM' => self::TABELA, 'WHERE' => ['is_arquivada' => 0]])->current();
        return (int) ($r['total'] ?? 0);
    }

    /** Lista para a caixa de conversas; $filtro: todas | naolidas | vinculadas | arquivadas */
    public static function listar(string $busca = '', string $filtro = 'todas', int $limite = 80): array
    {
        global $DB;
        $where = ['is_arquivada' => $filtro === 'arquivadas' ? 1 : 0];
        if ($filtro === 'naolidas') {
            $where['nao_lidas'] = ['>', 0];
        } elseif ($filtro === 'vinculadas') {
            $where['items_id'] = ['>', 0];
        }
        $busca = trim($busca);
        if ($busca !== '') {
            $digitos = (string) preg_replace('/\D+/', '', $busca);
            $ou = [['nome' => ['LIKE', '%' . $busca . '%']], ['nome_whatsapp' => ['LIKE', '%' . $busca . '%']], ['ultima_mensagem' => ['LIKE', '%' . $busca . '%']]];
            if (strlen($digitos) >= 3) {
                $ou[] = ['telefone' => ['LIKE', '%' . $digitos . '%']];
            }
            if (ctype_digit($busca)) {
                $ou[] = ['items_id' => (int) $busca];
            }
            $where[] = ['OR' => $ou];
        }
        $lista = [];
        foreach ($DB->request(['FROM' => self::TABELA, 'WHERE' => $where, 'ORDER' => ['date_ultima DESC', 'id DESC'], 'LIMIT' => max(1, min(300, $limite))]) as $c) {
            $lista[] = self::paraTela($c);
        }
        return $lista;
    }

    public static function paraTela(array $c): array
    {
        return [
            'id'         => (int) $c['id'],
            'telefone'   => (string) $c['telefone'],
            'exibicao'   => PluginCentraldecontatosConfig::telefoneExibicao((string) $c['telefone']),
            'nome'       => self::rotulo($c),
            'nome_whatsapp' => (string) $c['nome_whatsapp'],
            'nao_lidas'  => (int) $c['nao_lidas'],
            'previa'     => (string) $c['ultima_mensagem'],
            'direcao'    => (string) $c['ultima_direcao'],
            'quando'     => $c['date_ultima'] ? Html::convDateTime((string) $c['date_ultima']) : '',
            'quando_iso' => (string) $c['date_ultima'],
            'itemtype'   => (string) $c['itemtype'],
            'items_id'   => (int) $c['items_id'],
            'item'       => self::rotuloItem((string) $c['itemtype'], (int) $c['items_id']),
            'item_url'   => self::urlItem((string) $c['itemtype'], (int) $c['items_id']),
            'arquivada'  => (int) $c['is_arquivada'] === 1,
        ];
    }
}
