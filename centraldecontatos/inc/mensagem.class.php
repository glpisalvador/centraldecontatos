<?php

/**
 * Plugin Central de Contatos - mensagens de WhatsApp: recebidas, enviadas pelo GLPI e pelo próprio celular,
 * status de entrega (enviada / entregue / lida), reações, citações, mídias e o acompanhamento no item.
 */
class PluginCentraldecontatosMensagem extends CommonDBTM
{
    public const TABELA = 'glpi_plugin_centraldecontatos_mensagens';

    public const TIPOS = ['texto', 'imagem', 'audio', 'video', 'documento', 'figurinha'];

    /** Ordem dos status de envio (só avança) */
    public const ORDEM_STATUS = ['pendente' => 0, 'erro' => 0, 'enviada' => 1, 'entregue' => 2, 'lida' => 3];

    public static function getTypeName($nb = 0): string
    {
        return 'Mensagens';
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function canView(): bool
    {
        return PluginCentraldecontatosConfig::perfilPermitido();
    }

    public static function obter(int $id): ?array
    {
        global $DB;
        $r = $DB->request(['FROM' => self::TABELA, 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current();
        return $r ?: null;
    }

    public static function porWaId(string $waId, int $conversas_id = 0): ?array
    {
        global $DB;
        if ($waId === '') {
            return null;
        }
        $where = ['wa_id' => $waId];
        if ($conversas_id > 0) {
            $where['conversas_id'] = $conversas_id;
        }
        $r = $DB->request(['FROM' => self::TABELA, 'WHERE' => $where, 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
        return $r ?: null;
    }

    public static function rotuloMidia(string $tipo): string
    {
        return ['imagem' => '📷 Imagem', 'audio' => '🎤 Áudio', 'video' => '🎬 Vídeo', 'documento' => '📄 Documento', 'figurinha' => 'Figurinha'][$tipo] ?? '';
    }

    public static function previa(string $tipo, string $texto, string $nomeArquivo = ''): string
    {
        $texto = trim((string) preg_replace('/\s+/', ' ', $texto));
        if ($tipo === 'texto' || $tipo === '') {
            return $texto;
        }
        $r = self::rotuloMidia($tipo) . ($tipo === 'documento' && $nomeArquivo !== '' ? ': ' . $nomeArquivo : '');
        return $texto !== '' ? $r . ' · ' . $texto : $r;
    }

    // =====================================================================
    // Recebimento (webhook)
    // =====================================================================

    /**
     * Mensagem que chegou pelo servidor: do cliente (entrada) ou enviada pelo próprio celular (saída).
     * Devolve o id gravado (0 se já existia).
     */
    public static function receber(array $d): int
    {
        global $DB;
        $deMim = !empty($d['de_mim']);
        $conversa = PluginCentraldecontatosConversa::garantir((string) ($d['telefone'] ?? ''), (string) ($d['jid'] ?? ''), $deMim ? '' : (string) ($d['nome'] ?? ''));
        $waId = mb_substr((string) ($d['wa_id'] ?? ''), 0, 80);
        if ($waId !== '' && self::porWaId($waId, (int) $conversa['id'])) {
            return 0;
        }
        $midia = is_array($d['midia'] ?? null) ? $d['midia'] : null;
        $tipo = 'texto';
        if ($midia && in_array((string) ($midia['tipo'] ?? ''), self::TIPOS, true) && PluginCentraldecontatosWhatsapp::caminhoMidia((string) ($midia['arquivo'] ?? '')) !== null) {
            $tipo = (string) $midia['tipo'];
        } else {
            $midia = null;
        }
        $texto = mb_substr(trim((string) ($d['texto'] ?? '')), 0, 65000);
        $citada = is_array($d['citada'] ?? null) ? $d['citada'] : null;
        $quando = (int) ($d['data'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int) $d['data']) : date('Y-m-d H:i:s');
        $DB->insert(self::TABELA, [
            'conversas_id'  => (int) $conversa['id'],
            'wa_id'         => $waId,
            'direcao'       => $deMim ? 'saida' : 'entrada',
            'origem'        => $deMim ? 'celular' : 'cliente',
            'tipo'          => $tipo,
            'conteudo'      => $texto,
            'midia_arquivo' => $midia ? (string) $midia['arquivo'] : '',
            'midia_mime'    => $midia ? mb_substr((string) ($midia['mime'] ?? ''), 0, 100) : '',
            'midia_nome'    => $midia ? mb_substr((string) ($midia['nome'] ?? ''), 0, 255) : '',
            'midia_tamanho' => $midia ? (int) ($midia['tamanho'] ?? 0) : 0,
            'citada_wa_id'  => $citada ? mb_substr((string) ($citada['wa_id'] ?? ''), 0, 80) : '',
            'citada_texto'  => $citada ? mb_substr((string) ($citada['texto'] ?? ''), 0, 500) : '',
            'status'        => $deMim ? 'enviada' : 'recebida',
            'lida'          => $deMim ? 1 : 0,
            'itemtype'      => (string) $conversa['itemtype'],
            'items_id'      => (int) $conversa['items_id'],
            'date_envio'    => $quando,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $DB->insertId();
        PluginCentraldecontatosConversa::tocar((int) $conversa['id'], ($deMim ? 'Você: ' : '') . self::previa($tipo, $texto, (string) ($midia['nome'] ?? '')), $deMim ? 'saida' : 'entrada', !$deMim);

        // Recebida de contato ligado a um item aberto: vira acompanhamento no item
        if (!$deMim && PluginCentraldecontatosConfig::ligado('wa_registrar_recebidas') && ($item = PluginCentraldecontatosConversa::itemAberto($conversa))) {
            $html = '<p><strong>WhatsApp recebido</strong> de ' . htmlescape(PluginCentraldecontatosConversa::rotulo($conversa)) . ' · ' . htmlescape(PluginCentraldecontatosConfig::telefoneExibicao((string) $conversa['telefone'])) . '</p>'
                . ($citada && trim((string) ($citada['texto'] ?? '')) !== '' ? '<p><em>Em resposta a: ' . htmlescape((string) $citada['texto']) . '</em></p>' : '')
                . ($texto !== '' ? '<blockquote>' . nl2br(htmlescape($texto)) . '</blockquote>' : '<p>' . htmlescape(self::rotuloMidia($tipo)) . '</p>');
            $f = self::acompanhamento($item, $html, PluginCentraldecontatosConfig::ligado('wa_recebidas_privado'), $midia, self::usuarioDoTelefone($item, (string) $conversa['telefone']));
            if ($f > 0) {
                $DB->update(self::TABELA, ['itilfollowups_id' => $f], ['id' => $id]);
            }
        }
        return $id;
    }

    /** Status vindos do servidor: 2 enviada, 3 entregue, 4/5 lida */
    public static function atualizarStatus(array $itens): int
    {
        global $DB;
        $mapa = [1 => 'pendente', 2 => 'enviada', 3 => 'entregue', 4 => 'lida', 5 => 'lida'];
        $n = 0;
        foreach ($itens as $i) {
            $novo = $mapa[(int) ($i['status'] ?? 0)] ?? null;
            $m = $novo ? self::porWaId(mb_substr((string) ($i['wa_id'] ?? ''), 0, 80)) : null;
            if (!$m || $m['direcao'] !== 'saida') {
                continue;
            }
            if ((self::ORDEM_STATUS[$novo] ?? 0) > (self::ORDEM_STATUS[(string) $m['status']] ?? 0)) {
                $DB->update(self::TABELA, ['status' => $novo, 'erro' => ''], ['id' => $m['id']]);
                $n++;
            }
        }
        return $n;
    }

    public static function registrarReacao(string $waId, string $emoji, bool $doCliente): bool
    {
        global $DB;
        $m = self::porWaId(mb_substr($waId, 0, 80));
        if (!$m) {
            return false;
        }
        return (bool) $DB->update(self::TABELA, [$doCliente ? 'reacao_cliente' : 'reacao_nossa' => mb_substr($emoji, 0, 16)], ['id' => $m['id']]);
    }

    // =====================================================================
    // Envio pelo GLPI
    // =====================================================================

    /**
     * Envia texto e/ou mídia. $midia: ['tipo', 'arquivo' (relativo em midia/), 'mime', 'nome', 'tamanho'].
     * $item: item de onde a mensagem saiu (vincula a conversa e registra o acompanhamento, se configurado).
     */
    public static function enviar(array $conversa, string $texto, ?array $midia = null, int $citarId = 0, ?CommonITILObject $item = null): array
    {
        global $DB;
        $texto = mb_substr(trim($texto), 0, 4096);
        if ($texto === '' && !$midia) {
            return ['ok' => false, 'mensagem' => 'Digite a mensagem.'];
        }
        $tipo = $midia ? (string) $midia['tipo'] : 'texto';
        $citar = null;
        $citada = $citarId > 0 ? self::obter($citarId) : null;
        if ($citada && (int) $citada['conversas_id'] === (int) $conversa['id'] && (string) $citada['wa_id'] !== '') {
            $citar = ['wa_id' => (string) $citada['wa_id'], 'de_mim' => $citada['direcao'] === 'saida', 'texto' => mb_substr(self::previa((string) $citada['tipo'], (string) $citada['conteudo']), 0, 300)];
        }
        if ($item) {
            PluginCentraldecontatosConversa::vincular((int) $conversa['id'], get_class($item), (int) $item->getID());
        }
        $DB->insert(self::TABELA, [
            'conversas_id'  => (int) $conversa['id'],
            'direcao'       => 'saida',
            'origem'        => 'glpi',
            'tipo'          => $tipo,
            'conteudo'      => $texto,
            'midia_arquivo' => $midia ? (string) $midia['arquivo'] : '',
            'midia_mime'    => $midia ? (string) $midia['mime'] : '',
            'midia_nome'    => $midia ? mb_substr((string) ($midia['nome'] ?? ''), 0, 255) : '',
            'midia_tamanho' => $midia ? (int) ($midia['tamanho'] ?? 0) : 0,
            'citada_wa_id'  => $citar ? $citar['wa_id'] : '',
            'citada_texto'  => $citar ? $citar['texto'] : '',
            'status'        => 'pendente',
            'lida'          => 1,
            'users_id'      => (int) Session::getLoginUserID(),
            'itemtype'      => $item ? get_class($item) : (string) $conversa['itemtype'],
            'items_id'      => $item ? (int) $item->getID() : (int) $conversa['items_id'],
            'date_envio'    => date('Y-m-d H:i:s'),
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $DB->insertId();
        $r = PluginCentraldecontatosWhatsapp::enviar((string) $conversa['telefone'], $texto, $midia, $citar);
        $campos = $r['ok'] ? ['status' => 'enviada', 'wa_id' => mb_substr((string) $r['wa_id'], 0, 80), 'erro' => ''] : ['status' => 'erro', 'erro' => mb_substr((string) $r['erro'], 0, 255)];
        if ($r['ok'] && !empty($r['convertido']['arquivo']) && PluginCentraldecontatosWhatsapp::caminhoMidia((string) $r['convertido']['arquivo'])) {
            $campos['midia_arquivo'] = (string) $r['convertido']['arquivo'];
            $campos['midia_mime'] = (string) ($r['convertido']['mime'] ?? 'audio/ogg');
        }
        $DB->update(self::TABELA, $campos, ['id' => $id]);
        if ($r['ok'] && $campos['wa_id'] !== '') {
            // Aviso do servidor que chegou antes da resposta: era esta mesma mensagem
            $DB->delete(self::TABELA, ['wa_id' => $campos['wa_id'], 'origem' => 'celular', 'conversas_id' => (int) $conversa['id']]);
        }
        PluginCentraldecontatosConversa::tocar((int) $conversa['id'], 'Você: ' . self::previa($tipo, $texto, (string) ($midia['nome'] ?? '')), 'saida', false);

        if ($r['ok'] && $item && PluginCentraldecontatosConfig::ligado('registrar_whatsapp')) {
            $html = '<p><strong>WhatsApp enviado</strong> para ' . htmlescape(PluginCentraldecontatosConversa::rotulo($conversa)) . ' · ' . htmlescape(PluginCentraldecontatosConfig::telefoneExibicao((string) $conversa['telefone'])) . '</p>'
                . ($texto !== '' ? '<blockquote>' . nl2br(htmlescape($texto)) . '</blockquote>' : '<p>' . htmlescape(self::rotuloMidia($tipo)) . '</p>');
            $f = self::acompanhamento($item, $html, PluginCentraldecontatosConfig::ligado('privado'), $midia, (int) Session::getLoginUserID());
            if ($f > 0) {
                $DB->update(self::TABELA, ['itilfollowups_id' => $f], ['id' => $id]);
            }
            $DB->insert(PluginCentraldecontatosContato::TABELA, [
                'itemtype' => get_class($item), 'items_id' => (int) $item->getID(), 'entities_id' => (int) $item->fields['entities_id'],
                'users_id' => (int) Session::getLoginUserID(), 'tipo' => 'whatsapp', 'contato' => mb_substr(PluginCentraldecontatosConversa::rotulo($conversa), 0, 255),
                'destino' => PluginCentraldecontatosConfig::telefoneExibicao((string) $conversa['telefone']), 'resultado' => 'Enviada pelo GLPI',
                'observacao' => mb_substr(self::previa($tipo, $texto, (string) ($midia['nome'] ?? '')), 0, 5000), 'sucesso' => 1, 'itilfollowups_id' => $f,
                'date_creation' => date('Y-m-d H:i:s'),
            ]);
        }
        return ['ok' => $r['ok'], 'id' => $id, 'mensagem' => $r['ok'] ? 'Mensagem enviada.' : 'Não foi enviada: ' . $r['erro']];
    }

    /** Reação nossa numa mensagem da conversa */
    public static function reagir(array $m, string $emoji): array
    {
        $c = PluginCentraldecontatosConversa::obter((int) $m['conversas_id']);
        if (!$c || (string) $m['wa_id'] === '') {
            return ['ok' => false, 'mensagem' => 'Esta mensagem não pode receber reação.'];
        }
        $r = PluginCentraldecontatosWhatsapp::reagir((string) $c['telefone'], (string) $m['wa_id'], $m['direcao'] === 'saida', $emoji);
        if ($r['ok']) {
            self::registrarReacao((string) $m['wa_id'], $emoji, false);
        }
        return ['ok' => $r['ok'], 'mensagem' => $r['ok'] ? 'Reação enviada.' : (string) $r['erro']];
    }

    // =====================================================================
    // Acompanhamento no item
    // =====================================================================

    /** Requerente/observador do item com o mesmo telefone (para assinar o acompanhamento recebido) */
    private static function usuarioDoTelefone(CommonITILObject $item, string $chave): int
    {
        foreach (PluginCentraldecontatosContato::contatos($item) as $c) {
            foreach ($c['telefones'] as $t) {
                if ((int) $c['users_id'] > 0 && PluginCentraldecontatosConfig::chaveTelefone((string) $t['numero']) === $chave) {
                    return (int) $c['users_id'];
                }
            }
        }
        return 0;
    }

    /** Cria o acompanhamento (com a mídia anexada como documento do GLPI, quando o tipo de arquivo é aceito) */
    private static function acompanhamento(CommonITILObject $item, string $html, bool $privado, ?array $midia, int $users_id): int
    {
        $input = [
            'itemtype'   => get_class($item),
            'items_id'   => (int) $item->getID(),
            'content'    => $html,
            'is_private' => $privado ? 1 : 0,
            '_assinaturausuario_ignorar' => 1,
        ];
        if ($users_id > 0) {
            $input['users_id'] = $users_id;
        }
        $origem = $midia ? PluginCentraldecontatosWhatsapp::caminhoMidia((string) $midia['arquivo']) : null;
        if ($origem) {
            $ext = strtolower(pathinfo($origem, PATHINFO_EXTENSION));
            if (countElementsInTable('glpi_documenttypes', ['ext' => $ext, 'is_uploadable' => 1]) > 0) {
                $nome = trim((string) ($midia['nome'] ?? '')) !== '' ? (string) preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string) $midia['nome']) : basename($origem);
                if (strtolower(pathinfo($nome, PATHINFO_EXTENSION)) !== $ext) {
                    $nome .= '.' . $ext;
                }
                $prefixo = uniqid('cdc', true) . '_';
                if (@copy($origem, GLPI_TMP_DIR . '/' . $prefixo . $nome)) {
                    $input['_filename'] = [$prefixo . $nome];
                    $input['_prefix_filename'] = [$prefixo];
                }
            }
        }
        $f = new ITILFollowup();
        return (int) $f->add($input);
    }

    // =====================================================================
    // Leitura para a tela
    // =====================================================================

    public static function paraTela(array $m, array $nomes = []): array
    {
        $midia = null;
        if ((string) $m['midia_arquivo'] !== '') {
            $midia = [
                'url'     => PluginCentraldecontatosConfig::url('midia.php', ['id' => (int) $m['id']]),
                'mime'    => (string) $m['midia_mime'],
                'nome'    => (string) $m['midia_nome'],
                'tamanho' => (int) $m['midia_tamanho'],
                'existe'  => PluginCentraldecontatosWhatsapp::caminhoMidia((string) $m['midia_arquivo']) !== null,
            ];
        }
        $uid = (int) $m['users_id'];
        return [
            'id'        => (int) $m['id'],
            'direcao'   => (string) $m['direcao'],
            'origem'    => (string) $m['origem'],
            'tipo'      => (string) $m['tipo'],
            'texto'     => (string) $m['conteudo'],
            'midia'     => $midia,
            'citada'    => (string) $m['citada_texto'] !== '' ? (string) $m['citada_texto'] : null,
            'reacao_cliente' => (string) $m['reacao_cliente'],
            'reacao_nossa'   => (string) $m['reacao_nossa'],
            'status'    => (string) $m['status'],
            'erro'      => (string) $m['erro'],
            'autor'     => $uid > 0 ? ($nomes[$uid] ?? (string) getUserName($uid)) : ((string) $m['origem'] === 'celular' ? 'Celular' : ''),
            'item'      => (int) $m['items_id'] > 0 ? PluginCentraldecontatosConversa::rotuloItem((string) $m['itemtype'], (int) $m['items_id']) : '',
            'acompanhamento' => (int) $m['itilfollowups_id'] > 0,
            'reage'     => (string) $m['wa_id'] !== '',
            'hora'      => substr((string) $m['date_envio'], 11, 5),
            'dia'       => substr((string) $m['date_envio'], 0, 10),
            'quando'    => Html::convDateTime((string) $m['date_envio']),
        ];
    }

    /** Mensagens da conversa: as mais novas (ou depois/antes de um id), em ordem cronológica */
    public static function listar(int $conversas_id, int $depoisDe = 0, int $antesDe = 0, int $limite = 60): array
    {
        global $DB;
        $where = ['conversas_id' => $conversas_id];
        if ($depoisDe > 0) {
            $where['id'] = ['>', $depoisDe];
        } elseif ($antesDe > 0) {
            $where['id'] = ['<', $antesDe];
        }
        $linhas = iterator_to_array($DB->request(['FROM' => self::TABELA, 'WHERE' => $where, 'ORDER' => $depoisDe > 0 ? 'id ASC' : 'id DESC', 'LIMIT' => max(1, min(200, $limite))]), false);
        if ($depoisDe <= 0) {
            $linhas = array_reverse($linhas);
        }
        $nomes = [];
        foreach (array_unique(array_filter(array_map(fn($l) => (int) $l['users_id'], $linhas))) as $uid) {
            $nomes[$uid] = (string) getUserName($uid);
        }
        return array_map(fn($l) => self::paraTela($l, $nomes), $linhas);
    }

    /** Status e reações das últimas mensagens (para atualizar os traços sem recarregar) */
    public static function situacaoRecente(int $conversas_id, int $limite = 80): array
    {
        global $DB;
        $r = [];
        foreach ($DB->request(['SELECT' => ['id', 'status', 'erro', 'reacao_cliente', 'reacao_nossa'], 'FROM' => self::TABELA, 'WHERE' => ['conversas_id' => $conversas_id], 'ORDER' => 'id DESC', 'LIMIT' => $limite]) as $l) {
            $r[(int) $l['id']] = [(string) $l['status'], (string) $l['erro'], (string) $l['reacao_cliente'], (string) $l['reacao_nossa']];
        }
        return $r;
    }

    // =====================================================================
    // Importação única do histórico do plugin WhatsApp Empresa
    // =====================================================================

    public static function importarWhatsappempresa(): string
    {
        global $DB;
        $origem = 'glpi_plugin_whatsappempresa_mensagens';
        if (PluginCentraldecontatosConfig::getConfig('wa_importado') === '1' || !$DB->tableExists($origem) || !$DB->tableExists('glpi_plugin_whatsappempresa_conversas')) {
            return '';
        }
        $pastaOrigem = GLPI_PLUGIN_DOC_DIR . '/whatsappempresa/midia';
        $jids = [];
        foreach ($DB->request(['SELECT' => ['telefone', 'jid', 'nome_contato'], 'FROM' => 'glpi_plugin_whatsappempresa_conversas']) as $c) {
            $jids[PluginCentraldecontatosConfig::chaveTelefone((string) $c['telefone'])] = [(string) $c['jid'], (string) $c['nome_contato']];
        }
        $n = 0;
        $conversas = [];
        foreach ($DB->request(['FROM' => $origem, 'ORDER' => 'id ASC']) as $m) {
            $tel = (string) ($m['telefone'] ?: $m['numero_cliente']);
            $chave = PluginCentraldecontatosConfig::chaveTelefone($tel);
            if ($chave === '') {
                continue;
            }
            [$jid, $nome] = $jids[$chave] ?? ['', ''];
            $c = PluginCentraldecontatosConversa::garantir($chave, $jid, $nome);
            $conversas[(int) $c['id']] = $c;
            $conteudo = (string) $m['conteudo'];
            $json = json_decode($conteudo, true);
            if (is_array($json) && isset($json['texto'])) {
                $conteudo = (string) $json['texto'];
            }
            $tipo = in_array((string) $m['tipo_midia'], ['imagem', 'audio'], true) ? (string) $m['tipo_midia'] : 'texto';
            $arquivo = '';
            if ($tipo !== 'texto' && preg_match('#^[0-9]{6}/[A-Za-z0-9_.-]+$#', (string) $m['midia_arquivo']) && is_file($pastaOrigem . '/' . $m['midia_arquivo'])) {
                $destino = PluginCentraldecontatosWhatsapp::pastaMidia() . '/' . $m['midia_arquivo'];
                @mkdir(dirname($destino), 0770, true);
                if (is_file($destino) || @copy($pastaOrigem . '/' . $m['midia_arquivo'], $destino)) {
                    $arquivo = (string) $m['midia_arquivo'];
                }
            }
            $entrada = (string) $m['direcao'] === 'entrada';
            $DB->insert(self::TABELA, [
                'conversas_id'  => (int) $c['id'],
                'wa_id'         => mb_substr((string) $m['wa_id'], 0, 80),
                'direcao'       => $entrada ? 'entrada' : 'saida',
                'origem'        => $entrada ? 'cliente' : 'glpi',
                'tipo'          => $tipo,
                'conteudo'      => $conteudo,
                'midia_arquivo' => $arquivo,
                'midia_mime'    => (string) $m['midia_mime'],
                'citada_texto'  => mb_substr((string) $m['citada_texto'], 0, 500),
                'reacao_cliente' => mb_substr((string) $m['reacao_cliente'], 0, 16),
                'reacao_nossa'  => mb_substr((string) $m['reacao_atendente'], 0, 16),
                'status'        => $entrada ? 'recebida' : ((string) $m['status_envio'] === 'erro' ? 'erro' : 'enviada'),
                'erro'          => mb_substr((string) $m['erro'], 0, 255),
                'lida'          => 1,
                'users_id'      => (int) $m['users_id'],
                'itemtype'      => (int) $m['tickets_id'] > 0 ? 'Ticket' : '',
                'items_id'      => (int) $m['tickets_id'],
                'date_envio'    => $m['date_creation'],
                'date_creation' => $m['date_creation'],
            ]);
            $n++;
            // Só vincula a chamado que ainda existe
            $ticket = (int) $m['tickets_id'] > 0 && countElementsInTable('glpi_tickets', ['id' => (int) $m['tickets_id']]) > 0 ? (int) $m['tickets_id'] : ($conversas[(int) $c['id']]['ultima'][3] ?? 0);
            $conversas[(int) $c['id']]['ultima'] = [self::previa($tipo, $conteudo), $entrada ? 'entrada' : 'saida', $m['date_creation'], $ticket];
        }
        foreach ($conversas as $id => $c) {
            if (isset($c['ultima'])) {
                [$previa, $dir, $quando, $ticket] = $c['ultima'];
                $DB->update(PluginCentraldecontatosConversa::TABELA, array_filter([
                    'ultima_mensagem' => mb_substr(($dir === 'saida' ? 'Você: ' : '') . $previa, 0, 255),
                    'ultima_direcao'  => $dir,
                    'date_ultima'     => $quando,
                ]) + ((int) $c['items_id'] === 0 && $ticket > 0 ? ['itemtype' => 'Ticket', 'items_id' => $ticket] : []), ['id' => $id]);
            }
        }
        PluginCentraldecontatosConfig::setConfig('wa_importado', '1');
        return $n > 0 ? $n . ' mensagem(ns) em ' . count($conversas) . ' conversa(s)' : '';
    }
}
