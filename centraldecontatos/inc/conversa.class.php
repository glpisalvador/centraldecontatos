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
        $c = self::obter($id);
        $novoId = $itemtype === '' ? 0 : $items_id;
        $campos = ['itemtype' => $itemtype, 'items_id' => $novoId];
        // Outro item: ele recebe a conversa inteira quando for salva
        if ($c && ((string) $c['itemtype'] !== $itemtype || (int) $c['items_id'] !== $novoId)) {
            $campos['salva_ate_id'] = 0;
        }
        return (bool) $DB->update(self::TABELA, $campos, ['id' => $id]);
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

    /** Item vinculado (aberto ou não), se existir e a pessoa puder acompanhar */
    public static function itemVinculado(array $c): ?CommonITILObject
    {
        $tipo = (string) ($c['itemtype'] ?? '');
        if ($tipo === '' || !isset(PluginCentraldecontatosConfig::ITENS[$tipo]) || (int) $c['items_id'] <= 0) {
            return null;
        }
        $item = new $tipo();
        return $item->getFromDB((int) $c['items_id']) && empty($item->fields['is_deleted']) ? $item : null;
    }

    /** Todas as mensagens da conversa, em ordem */
    public static function todasMensagens(array $c): array
    {
        global $DB;
        return iterator_to_array($DB->request([
            'FROM'  => PluginCentraldecontatosMensagem::TABELA,
            'WHERE' => ['conversas_id' => (int) $c['id']],
            'ORDER' => 'id ASC',
            'LIMIT' => 3000,
        ]), false);
    }

    /**
     * Grava a conversa (o que ainda não foi gravado) como acompanhamento no item vinculado.
     * @return array{ok: bool, mensagem: string, followup: int, quantidade: int}
     */
    public static function salvarNoItem(int $id, string $motivo = 'salvar'): array
    {
        global $DB;
        $c = self::obter($id);
        if (!$c) {
            return ['ok' => false, 'mensagem' => 'Conversa não encontrada.', 'followup' => 0, 'quantidade' => 0];
        }
        $item = self::itemVinculado($c);
        if (!$item) {
            return ['ok' => false, 'mensagem' => 'A conversa não está vinculada a um chamado, problema ou mudança. Vincule antes de salvar.', 'followup' => 0, 'quantidade' => 0];
        }
        if (!$item->canAddFollowups()) {
            return ['ok' => false, 'mensagem' => 'Você não pode adicionar acompanhamentos em ' . self::rotuloItem(get_class($item), (int) $item->getID()) . '.', 'followup' => 0, 'quantidade' => 0];
        }
        // Sempre a conversa inteira, com todos os anexos
        $msgs = self::todasMensagens($c);
        if (!$msgs) {
            return ['ok' => true, 'mensagem' => 'A conversa não tem mensagens para salvar.', 'followup' => 0, 'quantidade' => 0];
        }
        $titulo = 'Conversa de WhatsApp';
        $rodape = $motivo === 'limpar'
            ? 'Conversa limpa no GLPI em ' . Html::convDateTime(date('Y-m-d H:i:s'))
            : 'Conversa salva em ' . Html::convDateTime(date('Y-m-d H:i:s'));
        $html = PluginCentraldecontatosMensagem::transcricaoHtml($c, $item, $msgs, $titulo, $rodape);
        $f = new ITILFollowup();
        $fid = (int) $f->add([
            'itemtype'   => get_class($item),
            'items_id'   => (int) $item->getID(),
            'content'    => $html,
            'is_private' => PluginCentraldecontatosConfig::ligado('privado') ? 1 : 0,
            '_assinaturausuario_ignorar' => 1,
        ]);
        if ($fid <= 0) {
            return ['ok' => false, 'mensagem' => 'O GLPI não aceitou o acompanhamento.', 'followup' => 0, 'quantidade' => 0];
        }
        $DB->update(self::TABELA, ['salva_ate_id' => (int) end($msgs)['id']], ['id' => $id]);
        $DB->insert(PluginCentraldecontatosContato::TABELA, [
            'itemtype' => get_class($item), 'items_id' => (int) $item->getID(), 'entities_id' => (int) $item->fields['entities_id'],
            'users_id' => (int) Session::getLoginUserID(), 'tipo' => 'whatsapp', 'contato' => mb_substr(self::rotulo($c), 0, 255),
            'destino' => PluginCentraldecontatosConfig::telefoneExibicao((string) $c['telefone']),
            'resultado' => $motivo === 'limpar' ? 'Conversa limpa' : 'Conversa salva', 'observacao' => count($msgs) . ' mensagem(ns)',
            'sucesso' => 1, 'itilfollowups_id' => $fid, 'date_creation' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'mensagem' => 'Conversa inteira (' . count($msgs) . ' mensagem(ns)) salva em ' . self::rotuloItem(get_class($item), (int) $item->getID()) . '.', 'followup' => $fid, 'quantidade' => count($msgs)];
    }

    /**
     * Limpa a conversa: grava antes no item vinculado o que ainda não foi gravado e apaga as mensagens
     * (e as mídias) do chat. A conversa continua existindo, com o mesmo vínculo.
     */
    public static function limpar(int $id): array
    {
        global $DB;
        $c = self::obter($id);
        if (!$c) {
            return ['ok' => false, 'mensagem' => 'Conversa não encontrada.'];
        }
        $salvo = null;
        if (self::itemVinculado($c)) {
            $salvo = self::salvarNoItem($id, 'limpar');
            if (!$salvo['ok']) {
                return ['ok' => false, 'mensagem' => 'A conversa não foi limpa: ' . $salvo['mensagem']];
            }
        }
        $n = 0;
        foreach ($DB->request(['SELECT' => ['id', 'midia_arquivo'], 'FROM' => PluginCentraldecontatosMensagem::TABELA, 'WHERE' => ['conversas_id' => $id]]) as $m) {
            if ($caminho = PluginCentraldecontatosWhatsapp::caminhoMidia((string) $m['midia_arquivo'])) {
                @unlink($caminho);
            }
            $n++;
        }
        $DB->delete(PluginCentraldecontatosMensagem::TABELA, ['conversas_id' => $id]);
        $DB->update(self::TABELA, ['nao_lidas' => 0, 'salva_ate_id' => 0, 'ultima_mensagem' => '', 'ultima_direcao' => ''], ['id' => $id]);
        $onde = $salvo && $salvo['followup'] > 0 ? ' Antes, a conversa inteira foi salva no acompanhamento de ' . self::rotuloItem((string) $c['itemtype'], (int) $c['items_id']) . '.' : ($salvo ? '' : ' Sem item vinculado: nada foi salvo.');
        return ['ok' => true, 'mensagem' => 'Conversa limpa (' . $n . ' mensagem(ns)).' . $onde];
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
            'pode_validar' => in_array((string) $c['itemtype'], ['Ticket', 'Change'], true) && (int) $c['items_id'] > 0,
        ];
    }
}
