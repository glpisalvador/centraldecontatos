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

        // Resposta "1"/"2" a um pedido de validação enviado por esta conversa
        if (!$deMim && $tipo === 'texto') {
            try {
                PluginCentraldecontatosValidacao::processarResposta($conversa, $texto, $citada ? (string) ($citada['wa_id'] ?? '') : '');
            } catch (\Throwable $e) {
                error_log('Plugin centraldecontatos (validação): ' . $e->getMessage());
            }
        }

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
            $citar = ['wa_id' => (string) $citada['wa_id'], 'de_mim' => $citada['direcao'] === 'saida', 'texto' => mb_substr(self::previa((string) $citada['tipo'], (string) $citada['conteudo'], (string) $citada['midia_nome']), 0, 300)];
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
    // Transcrição da conversa (acompanhamento "Salvar" e "Limpar")
    // =====================================================================

    /**
     * Transcrição no visual do WhatsApp (balões, dia, horário, autor, citação, reações, status e mídias),
     * com cores suaves. Só estilo inline: o GLPI 11/12 mantém style no acompanhamento e remove <style>.
     * As mídias viram documentos do item (fora da linha do tempo) e aparecem dentro dos balões.
     */
    public static function transcricaoHtml(array $conversa, CommonITILObject $item, array $mensagens, string $titulo, string $rodape): string
    {
        global $CFG_GLPI;
        $contato = PluginCentraldecontatosConversa::rotulo($conversa);
        $telefone = PluginCentraldecontatosConfig::telefoneExibicao((string) $conversa['telefone']);
        $caixa = 'background:rgba(239,234,226,0.55);border:1px solid rgba(11,20,26,0.06);border-radius:8px;padding:10px 12px;max-width:760px;';
        $topo = 'background:rgba(0,128,105,0.10);border-radius:6px;padding:7px 10px;margin-bottom:8px;font-size:12px;color:#0b3d34;';
        $chip = 'display:inline-block;background:rgba(255,255,255,0.75);color:rgba(84,101,111,0.95);font-size:11px;padding:3px 10px;border-radius:7px;box-shadow:0 1px 0.5px rgba(11,20,26,0.08);';
        $balao = 'display:inline-block;text-align:left;max-width:78%;padding:6px 9px 4px;border-radius:7.5px;font-size:13px;line-height:1.4;color:#111b21;box-shadow:0 1px 0.5px rgba(11,20,26,0.10);word-wrap:break-word;overflow-wrap:anywhere;';
        $recebido = 'background:rgba(255,255,255,0.78);';
        $enviado = 'background:rgba(217,253,211,0.78);';
        $hora = 'display:block;text-align:right;font-size:10.5px;color:rgba(17,27,33,0.45);margin-top:1px;';

        $primeira = $mensagens ? (string) $mensagens[0]['date_envio'] : '';
        $ultima = $mensagens ? (string) end($mensagens)['date_envio'] : '';
        $html = '<div style="' . $caixa . '">';
        $html .= '<div style="' . $topo . '"><strong>' . htmlescape($titulo) . '</strong> &middot; ' . htmlescape($contato)
            . ($contato !== $telefone ? ' (' . htmlescape($telefone) . ')' : '')
            . ' &middot; ' . count($mensagens) . ' mensage' . (count($mensagens) === 1 ? 'm' : 'ns')
            . ($primeira !== '' ? '<br><span style="font-size:11px;color:rgba(11,61,52,0.75);">De ' . htmlescape(Html::convDateTime($primeira)) . ' a ' . htmlescape(Html::convDateTime($ultima)) . ' &middot; registrado por ' . htmlescape((string) getUserName((int) Session::getLoginUserID())) . '</span>' : '')
            . '</div>';

        $nomes = [];
        $porWaId = [];
        foreach ($mensagens as $m) {
            if ((string) $m['wa_id'] !== '') {
                $porWaId[(string) $m['wa_id']] = $m;
            }
        }
        $diaAnterior = '';
        $autorAnterior = '';
        foreach ($mensagens as $m) {
            $data = (string) $m['date_envio'];
            $dia = substr($data, 0, 10);
            if ($dia !== $diaAnterior) {
                $html .= '<div style="text-align:center;margin:8px 0 6px;"><span style="' . $chip . '">' . htmlescape(self::rotuloDia($dia)) . '</span></div>';
                $diaAnterior = $dia;
                $autorAnterior = '';
            }
            $saida = $m['direcao'] === 'saida';
            $uid = (int) $m['users_id'];
            if (!$saida) {
                $autor = $contato;
            } elseif ($m['origem'] === 'celular') {
                $autor = 'Enviada pelo celular';
            } else {
                $autor = $uid > 0 ? ($nomes[$uid] ??= (string) getUserName($uid)) : 'Atendimento';
            }
            $novo = $autor !== $autorAnterior;
            $autorAnterior = $autor;
            $canto = $novo ? ($saida ? 'border-top-right-radius:0;' : 'border-top-left-radius:0;') : '';
            $html .= '<div style="text-align:' . ($saida ? 'right' : 'left') . ';margin:' . ($novo ? '6px' : '2px') . ' 0 0;">';
            $html .= '<div style="' . $balao . ($saida ? $enviado : $recebido) . $canto . '">';
            if ($novo) {
                $html .= '<span style="display:block;font-size:11.5px;font-weight:600;color:' . ($saida ? 'rgba(0,128,105,0.95)' : 'rgba(2,126,181,0.95)') . ';margin-bottom:1px;">' . htmlescape($autor) . '</span>';
            }
            if (trim((string) $m['citada_texto']) !== '') {
                $orig = $porWaId[(string) $m['citada_wa_id']] ?? null;
                $autorCitada = $orig ? ($orig['direcao'] === 'saida' ? 'Você' : $contato) : '';
                $html .= '<span style="display:block;margin:2px 0 4px;padding:4px 8px;border-left:3px solid ' . ($autorCitada === 'Você' ? 'rgba(0,128,105,0.8)' : 'rgba(2,126,181,0.8)') . ';background:rgba(11,20,26,0.05);border-radius:4px;font-size:12px;color:rgba(17,27,33,0.7);">'
                    . ($autorCitada !== '' ? '<strong style="display:block;font-size:11px;">' . htmlescape($autorCitada) . '</strong>' : '')
                    . htmlescape(mb_substr((string) $m['citada_texto'], 0, 200)) . '</span>';
            }
            if ((string) $m['midia_arquivo'] !== '') {
                $html .= self::midiaNaTranscricao($m, $item, $contato);
            }
            $html .= self::formatarTexto((string) $m['conteudo']);
            $html .= '<span style="' . $hora . '">' . htmlescape(substr($data, 11, 5));
            if ($saida) {
                $html .= match ((string) $m['status']) {
                    'erro'     => ' <span style="color:rgba(220,53,69,0.85);" title="Não enviada">&#9888; não enviada</span>',
                    'pendente' => ' <span style="color:rgba(17,27,33,0.4);">&#128339;</span>',
                    'lida'     => ' <span style="color:rgba(83,189,235,0.95);letter-spacing:-3px;" title="Lida">&#10003;&#10003;</span>',
                    'entregue' => ' <span style="color:rgba(17,27,33,0.45);letter-spacing:-3px;" title="Entregue">&#10003;&#10003;</span>',
                    default    => ' <span style="color:rgba(17,27,33,0.45);" title="Enviada">&#10003;</span>',
                };
            }
            $html .= '</span>';
            $reacoes = array_filter([(string) $m['reacao_cliente'], (string) $m['reacao_nossa']]);
            if ($reacoes) {
                $html .= '<span style="display:inline-block;margin-top:2px;padding:0 6px;border-radius:10px;background:rgba(255,255,255,0.9);box-shadow:0 1px 1px rgba(11,20,26,0.15);font-size:13px;">' . htmlescape(implode(' ', $reacoes)) . '</span>';
            }
            $html .= '</div></div>';
        }
        $html .= '<div style="text-align:center;margin:10px 0 2px;"><span style="' . $chip . '">' . htmlescape($rodape) . '</span></div>';
        return $html . '</div>';
    }

    /** Mídia dentro do balão, apontando para um documento do item */
    private static function midiaNaTranscricao(array $m, CommonITILObject $item, string $contato): string
    {
        global $CFG_GLPI;
        $tipo = (string) $m['tipo'];
        $caminho = PluginCentraldecontatosWhatsapp::caminhoMidia((string) $m['midia_arquivo']);
        $rotulos = ['imagem' => 'imagem', 'audio' => 'audio', 'video' => 'video', 'documento' => 'documento', 'figurinha' => 'figurinha'];
        $ext = strtolower(pathinfo((string) $m['midia_arquivo'], PATHINFO_EXTENSION));
        $nome = trim((string) $m['midia_nome']) !== ''
            ? (string) $m['midia_nome']
            : 'WhatsApp ' . ($rotulos[$tipo] ?? 'arquivo') . ' ' . str_replace([' ', ':'], ['_', '-'], substr((string) $m['date_envio'], 0, 16)) . ' - ' . preg_replace('/[^\w .-]+/u', '', $contato) . '.' . $ext;
        $docid = $caminho ? self::criarDocumento($caminho, $nome, $item) : 0;
        if ($docid <= 0) {
            return '<span style="display:block;font-size:12px;color:rgba(17,27,33,0.55);font-style:italic;">' . htmlescape(self::rotuloMidia($tipo) ?: 'Arquivo') . ($tipo === 'documento' && $m['midia_nome'] ? ': ' . htmlescape((string) $m['midia_nome']) : '') . ' (arquivo não anexado: tipo não aceito pelo GLPI ou indisponível)</span>';
        }
        $url = htmlescape($CFG_GLPI['root_doc'] . '/front/document.send.php?docid=' . $docid . '&itemtype=' . get_class($item) . '&items_id=' . (int) $item->getID());
        switch ($tipo) {
            case 'imagem':
            case 'figurinha':
                return '<a href="' . $url . '" target="_blank" title="Abrir imagem em tamanho real"><img src="' . $url . '" alt="Imagem do WhatsApp" style="display:block;max-width:' . ($tipo === 'figurinha' ? '140' : '280') . 'px;width:100%;height:auto;border-radius:6px;margin:2px 0 3px;"></a>';
            case 'audio':
                return '<audio controls preload="metadata" src="' . $url . '" style="display:block;width:260px;max-width:100%;height:40px;margin:2px 0;"></audio>'
                    . '<a href="' . $url . '" target="_blank" style="font-size:11px;color:rgba(0,128,105,0.9);">&#127908; baixar áudio</a>';
            case 'video':
                return '<video controls preload="metadata" src="' . $url . '" style="display:block;max-width:280px;width:100%;border-radius:6px;margin:2px 0;"></video>'
                    . '<a href="' . $url . '" target="_blank" style="font-size:11px;color:rgba(0,128,105,0.9);">&#127916; baixar vídeo</a>';
            default:
                return '<a href="' . $url . '" target="_blank" style="display:block;margin:2px 0 3px;padding:6px 8px;border-radius:6px;background:rgba(11,20,26,0.05);color:#111b21;text-decoration:none;font-size:12px;">&#128196; <strong>' . htmlescape($nome) . '</strong>'
                    . ((int) $m['midia_tamanho'] > 0 ? ' <span style="color:rgba(17,27,33,0.5);">(' . number_format((int) $m['midia_tamanho'] / 1024, 0, ',', '.') . ' KB)</span>' : '') . '</a>';
        }
    }

    /** Documento do GLPI ligado ao item, fora da linha do tempo (aparece só dentro do acompanhamento) */
    private static function criarDocumento(string $caminho, string $nome, CommonITILObject $item): int
    {
        $ext = strtolower(pathinfo($caminho, PATHINFO_EXTENSION));
        if (countElementsInTable('glpi_documenttypes', ['ext' => $ext, 'is_uploadable' => 1]) === 0) {
            return 0;
        }
        $prefixo = uniqid('cdc', true) . '_';
        $arquivo = $prefixo . basename($caminho);
        if (!@copy($caminho, GLPI_TMP_DIR . '/' . $arquivo)) {
            return 0;
        }
        $doc = new Document();
        $id = (int) $doc->add([
            'name' => mb_substr($nome, 0, 255), 'entities_id' => (int) $item->fields['entities_id'], 'is_recursive' => 0,
            '_filename' => [$arquivo], '_prefix_filename' => [$prefixo], '_only_if_upload_succeed' => 1,
        ]);
        @unlink(GLPI_TMP_DIR . '/' . $arquivo);
        if ($id <= 0) {
            return 0;
        }
        (new Document_Item())->add([
            'documents_id' => $id, 'itemtype' => get_class($item), 'items_id' => (int) $item->getID(),
            'entities_id' => (int) $item->fields['entities_id'], 'timeline_position' => CommonITILObject::NO_TIMELINE,
        ]);
        return $id;
    }

    /** O acompanhamento fica para sempre: data com dia da semana (nunca "hoje"/"ontem") */
    private static function rotuloDia(string $dia): string
    {
        $t = $dia !== '' ? strtotime($dia) : false;
        if ($t === false) {
            return $dia;
        }
        $semana = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
        return $semana[(int) date('w', $t)] . ', ' . date('d/m/Y', $t);
    }

    /** Escapa e aplica *negrito*, _itálico_, ~riscado~ e quebras de linha */
    private static function formatarTexto(string $texto): string
    {
        $s = htmlescape(trim(strip_tags($texto)));
        $s = (string) preg_replace('/(?<![\w*])\*(?!\s)([^*\n]+?)(?<!\s)\*(?![\w*])/u', '<strong>$1</strong>', $s);
        $s = (string) preg_replace('/(?<![\w_])_(?!\s)([^_\n]+?)(?<!\s)_(?![\w_])/u', '<em>$1</em>', $s);
        $s = (string) preg_replace('/(?<![\w~])~(?!\s)([^~\n]+?)(?<!\s)~(?![\w~])/u', '<s>$1</s>', $s);
        return nl2br($s, false);
    }

    // =====================================================================
    // Leitura para a tela
    // =====================================================================

    /** Mensagem citada para a tela: autor, texto e miniatura da mídia (quando ela está na conversa) */
    public static function citadaParaTela(array $m, ?array $original, string $contato): ?array
    {
        if ((string) $m['citada_texto'] === '' && !$original) {
            return null;
        }
        $c = ['texto' => (string) $m['citada_texto'], 'autor' => '', 'tipo' => 'texto', 'url' => '', 'id' => 0];
        if ($original) {
            $c['id'] = (int) $original['id'];
            $c['autor'] = $original['direcao'] === 'saida' ? 'Você' : $contato;
            $c['tipo'] = (string) $original['tipo'];
            if (trim((string) $original['conteudo']) !== '') {
                $c['texto'] = mb_strimwidth((string) $original['conteudo'], 0, 300, '…');
            } elseif ($c['tipo'] !== 'texto') {
                $c['texto'] = self::previa($c['tipo'], '', (string) $original['midia_nome']);
            }
            if ((string) $original['midia_arquivo'] !== '' && PluginCentraldecontatosWhatsapp::caminhoMidia((string) $original['midia_arquivo'])) {
                $c['url'] = PluginCentraldecontatosConfig::url('midia.php', ['id' => (int) $original['id']]);
            }
        }
        return $c;
    }

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
            'citada'    => $m['_citada'] ?? ((string) $m['citada_texto'] !== '' ? ['texto' => (string) $m['citada_texto'], 'autor' => '', 'tipo' => 'texto', 'url' => '', 'id' => 0] : null),
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
        // Mensagens citadas: busca as originais da conversa pelo id do WhatsApp
        $ids = array_values(array_unique(array_filter(array_map(fn($l) => (string) $l['citada_wa_id'], $linhas))));
        if ($ids) {
            $originais = [];
            foreach ($DB->request(['FROM' => self::TABELA, 'WHERE' => ['conversas_id' => $conversas_id, 'wa_id' => $ids]]) as $o) {
                $originais[(string) $o['wa_id']] = $o;
            }
            $conversa = PluginCentraldecontatosConversa::obter($conversas_id);
            $contato = $conversa ? PluginCentraldecontatosConversa::rotulo($conversa) : 'Contato';
            foreach ($linhas as &$l) {
                if ((string) $l['citada_wa_id'] !== '') {
                    $l['_citada'] = self::citadaParaTela($l, $originais[(string) $l['citada_wa_id']] ?? null, $contato);
                }
            }
            unset($l);
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
