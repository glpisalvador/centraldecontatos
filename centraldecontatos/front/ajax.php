<?php

/**
 * Plugin Central de Contatos - endpoint AJAX (sempre JSON, sempre POST).
 * Contatos do item: registrar, enviar_email. WhatsApp: resumo, conversas, abrir, mensagens, antigas,
 * enviar, upload (em partes), reagir, vincular, arquivar. Servidor (administradores): wa_status, wa_acao.
 */

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

register_shutdown_function(function () {
    $erro = error_get_last();
    if ($erro !== null && in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'mensagem' => 'Erro interno: ' . $erro['message']]);
    }
});

$C = PluginCentraldecontatosConfig::class;
$K = PluginCentraldecontatosContato::class;
$W = PluginCentraldecontatosWhatsapp::class;
$V = PluginCentraldecontatosConversa::class;
$M = PluginCentraldecontatosMensagem::class;
$responder = function (array $dados) use ($C): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $dados['new_token'] = session_status() === PHP_SESSION_ACTIVE ? $C::tokenCsrf() : '';
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
$falhar = fn(string $mensagem, array $extra = []) => $responder(['success' => false, 'mensagem' => $mensagem] + $extra);

if (!Session::getLoginUserID()) {
    $falhar('Sessão expirada. Recarregue a página.', ['sem_sessao' => true]);
}
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $falhar('Requisição inválida.');
}
$acao = (string) ($_POST['action'] ?? '');
$texto = fn(string $campo, int $max) => mb_substr(trim((string) ($_POST[$campo] ?? '')), 0, $max);

/** Item do contexto (chamado, problema ou mudança em que a pessoa está) */
$itemDoPost = function (bool $obrigatorio) use ($C, $K, $falhar): ?CommonITILObject {
    $itemtype = (string) ($_POST['itemtype'] ?? '');
    if ($itemtype === '' && !$obrigatorio) {
        return null;
    }
    if (!isset($C::ITENS[$itemtype])) {
        $falhar('Item inválido.');
    }
    $item = new $itemtype();
    if (!$item->getFromDB((int) ($_POST['items_id'] ?? 0)) || !$K::podeUsar($item)) {
        $falhar('Você não pode registrar contatos neste item.');
    }
    return $item;
};

$conversaDoPost = function () use ($V, $falhar): array {
    $c = $V::obter((int) ($_POST['conversas_id'] ?? 0));
    if (!$c) {
        $falhar('Conversa não encontrada.');
    }
    return $c;
};

/** Situação do servidor guardada por alguns segundos (várias telas consultam ao mesmo tempo) */
$situacaoServidor = function () use ($W): array {
    $arq = $W::pastaRun() . '/situacao.json';
    $cache = is_file($arq) ? json_decode((string) @file_get_contents($arq), true) : null;
    if (is_array($cache) && (int) ($cache['quando'] ?? 0) > time() - 5) {
        return $cache;
    }
    $s = $W::status();
    $r = ['quando' => time(), 'ligado' => $s['ligado'], 'conectado' => $s['conectado'], 'numero' => (string) ($s['servico']['numero'] ?? '')];
    @file_put_contents($arq, json_encode($r));
    return $r;
};

try {
    // ------------------------------------------------------------ contatos do item (bloco)
    if ($acao === 'registrar' || $acao === 'enviar_email') {
        $item = $itemDoPost(true);
        if ($acao === 'registrar') {
            $tipo = (string) ($_POST['tipo'] ?? '');
            if (!in_array($tipo, ['ligacao', 'whatsapp'], true)) {
                $falhar('Tipo de contato inválido.');
            }
            $destino = $texto('destino', 100);
            if ($C::numeroTel($destino) === '') {
                $falhar('Número inválido.');
            }
            $resultado = $texto('resultado', 100);
            if ($tipo === 'ligacao' && $resultado === '') {
                $falhar('Escolha o resultado da ligação.');
            }
            $followup = $K::registrar($item, $tipo, [
                'contato' => $texto('contato', 255), 'destino' => $destino, 'resultado' => $resultado,
                'observacao' => $texto('observacao', 5000), 'mensagem' => $texto('mensagem', 2000),
            ]);
            $responder(['success' => true, 'followup' => $followup, 'mensagem' => $followup > 0 ? 'Contato registrado como acompanhamento.' : 'Contato registrado.']);
        }
        $r = PluginCentraldecontatosEmail::enviar($item, (array) ($_POST['para'] ?? []), (array) ($_POST['copia'] ?? []), $texto('assunto', 255), (string) ($_POST['corpo'] ?? ''), $texto('contato', 255));
        $responder(['success' => $r['ok'], 'mensagem' => $r['mensagem']]);
    }

    // ------------------------------------------------------------ servidor (administradores)
    if ($acao === 'wa_status' || $acao === 'wa_acao') {
        if (!$C::ehAdmin()) {
            $falhar('Sem permissão.');
        }
        $mensagem = '';
        $ok = true;
        if ($acao === 'wa_acao') {
            @set_time_limit(120);
            $erro = null;
            switch ((string) ($_POST['acao'] ?? '')) {
                case 'iniciar':
                    $erro = $W::iniciar();
                    $mensagem = 'Servidor iniciado. O QR Code aparece em alguns segundos, se o aparelho ainda não estiver pareado.';
                    break;
                case 'parar':
                    $W::parar();
                    $mensagem = 'Servidor parado.';
                    break;
                case 'reiniciar':
                    $W::parar();
                    $erro = $W::iniciar();
                    $mensagem = 'Servidor reiniciado.';
                    break;
                case 'desvincular':
                    $r = $W::requisitar('POST', '/servico/desvincular', [], 20);
                    $ok = $r['ok'];
                    $mensagem = $ok ? 'Aparelho desvinculado. Leia o novo QR Code para parear outro.' : 'O servidor não respondeu. Ligue-o antes de desvincular.';
                    $C::setConfig('wa_numero', '');
                    break;
                case 'node_instalar':
                case 'node_atualizar':
                    $erro = $W::instalarNode();
                    $mensagem = 'Download do Node.js iniciado.';
                    break;
                case 'dependencias_instalar':
                    $erro = $W::instalarDependencias();
                    $mensagem = 'Instalação das dependências iniciada.';
                    break;
                case 'vigia_ativar':
                    $erro = $W::ajustarCron(true);
                    $mensagem = 'Vigia ativado.';
                    break;
                case 'vigia_desativar':
                    $erro = $W::ajustarCron(false);
                    $mensagem = 'Vigia desativado.';
                    break;
                case 'webhook_testar':
                    $t = $W::testarWebhook();
                    $ok = $t['ok'];
                    $mensagem = $ok ? 'O webhook respondeu em ' . $t['url'] . '.' : $t['erro'];
                    break;
                default:
                    $falhar('Ação desconhecida.');
            }
            if ($erro !== null) {
                $ok = false;
                $mensagem = $erro;
            }
            @unlink($W::pastaRun() . '/situacao.json');
        }
        $s = $W::status();
        $servico = $s['servico'];
        $responder([
            'success'   => $ok,
            'mensagem'  => $mensagem,
            'ligado'    => $s['ligado'],
            'conectado' => $s['conectado'],
            'deve_ligado' => $W::deveEstarLigado(),
            'pareado'   => is_file($W::pastaAuth() . '/creds.json'),
            'qr'        => ($s['ligado'] && !$s['conectado']) ? $W::qrcode() : null,
            'numero'    => isset($servico['numero']) ? $C::telefoneExibicao((string) $servico['numero']) : '',
            'nome'      => (string) ($servico['nome'] ?? ''),
            'desde'     => !empty($servico['tempo_ms']) ? Html::convDateTime(date('Y-m-d H:i:s', time() - (int) ((int) $servico['tempo_ms'] / 1000))) : '',
            'versoes'   => ['whatsapp' => (string) ($servico['versao_wa'] ?? ''), 'baileys' => (string) ($servico['versao_baileys'] ?? ''), 'node' => (string) ($servico['versao_node'] ?? $W::versaoNode())],
            'pid'       => $s['ligado'] ? $W::pid() : 0,
            'etapas'    => $W::etapas(($_POST['acao'] ?? '') === 'webhook_testar'),
            'tarefas'   => ['node' => $W::situacaoTarefa('node'), 'dependencias' => $W::situacaoTarefa('dependencias')],
            'log'       => $W::logServidor(150),
        ]);
    }

    // ------------------------------------------------------------ WhatsApp (quem usa a central)
    if (!$C::perfilPermitido() || !$C::ligado('wa_ativo')) {
        $falhar('Sem permissão.');
    }

    switch ($acao) {
        case 'resumo':
            session_write_close();
            if (!$C::ligado('wa_avisar_novas')) {
                $falhar('Aviso desligado.', ['desligado' => true]);
            }
            global $DB;
            $ultima = $DB->request([
                'SELECT' => [$M::TABELA . '.id', $M::TABELA . '.conversas_id', $M::TABELA . '.tipo', $M::TABELA . '.conteudo', $M::TABELA . '.midia_nome'],
                'FROM'   => $M::TABELA,
                'WHERE'  => ['direcao' => 'entrada', 'lida' => 0],
                'ORDER'  => $M::TABELA . '.id DESC',
                'LIMIT'  => 1,
            ])->current();
            $dados = ['success' => true, 'nao_lidas' => $V::naoLidasTotal(), 'ultima' => null];
            if ($ultima) {
                $c = $V::obter((int) $ultima['conversas_id']);
                $dados['ultima'] = ['id' => (int) $ultima['id'], 'conversas_id' => (int) $ultima['conversas_id'], 'nome' => $c ? $V::rotulo($c) : '', 'previa' => mb_strimwidth($M::previa((string) $ultima['tipo'], (string) $ultima['conteudo'], (string) $ultima['midia_nome']), 0, 120, '…')];
            }
            $responder($dados);

        case 'conversas':
            session_write_close();
            $responder(['success' => true, 'conversas' => $V::listar($texto('busca', 100), (string) ($_POST['filtro'] ?? 'todas')), 'nao_lidas' => $V::naoLidasTotal(), 'servidor' => $situacaoServidor()]);

        case 'abrir':
            $item = $itemDoPost(false);
            if ((int) ($_POST['conversas_id'] ?? 0) > 0) {
                $c = $conversaDoPost();
            } else {
                $tel = $texto('telefone', 40);
                if (strlen($C::chaveTelefone($tel)) < 10) {
                    $falhar('Informe um número com DDD.');
                }
                $c = $V::garantir($tel, '', '', $texto('nome', 255));
            }
            if ($item) {
                $V::vincular((int) $c['id'], get_class($item), (int) $item->getID());
                $c = $V::obter((int) $c['id']);
            }
            $responder(['success' => true, 'conversa' => $V::paraTela($c), 'mensagens' => $M::listar((int) $c['id']), 'servidor' => $situacaoServidor(), 'limite_mb' => max(1, (int) $C::getConfig('wa_midia_max_mb'))]);

        case 'mensagens':
            $c = $conversaDoPost();
            session_write_close();
            if (!empty($_POST['visivel'])) {
                $paraConfirmar = $V::marcarLida((int) $c['id']);
                if ($paraConfirmar && $C::ligado('wa_confirmar_leitura')) {
                    $W::marcarLidas($paraConfirmar);
                }
            }
            $responder([
                'success'   => true,
                'mensagens' => $M::listar((int) $c['id'], max(0, (int) ($_POST['depois_de'] ?? 0)), 0, 100),
                'situacao'  => $M::situacaoRecente((int) $c['id']),
                'conversa'  => $V::paraTela($V::obter((int) $c['id'])),
                'servidor'  => $situacaoServidor(),
            ]);

        case 'antigas':
            $c = $conversaDoPost();
            session_write_close();
            $responder(['success' => true, 'mensagens' => $M::listar((int) $c['id'], 0, max(1, (int) ($_POST['antes_de'] ?? 0)), 60)]);

        case 'upload':
            // Arquivo em partes (o PHP deste servidor aceita no máximo 2 MB por envio)
            $id = (string) ($_POST['upload_id'] ?? '');
            $parte = (int) ($_POST['parte'] ?? -1);
            $total = (int) ($_POST['total'] ?? 0);
            if (!preg_match('/^[a-f0-9]{16,40}$/', $id) || $parte < 0 || $total < 1 || $parte >= $total || empty($_FILES['arquivo']['tmp_name']) || !is_uploaded_file($_FILES['arquivo']['tmp_name'])) {
                $falhar('Envio do arquivo inválido.');
            }
            $W::prepararPastas();
            $parcial = $W::pastaEnvios() . '/' . $id . '.part';
            if ($parte === 0) {
                @unlink($parcial);
            }
            $limite = max(1, (int) $C::getConfig('wa_midia_max_mb')) * 1048576;
            if ((is_file($parcial) ? filesize($parcial) : 0) + filesize($_FILES['arquivo']['tmp_name']) > $limite) {
                @unlink($parcial);
                $falhar('Arquivo acima do limite de ' . (int) $C::getConfig('wa_midia_max_mb') . ' MB.');
            }
            file_put_contents($parcial, (string) file_get_contents($_FILES['arquivo']['tmp_name']), FILE_APPEND);
            if ($parte < $total - 1) {
                $responder(['success' => true, 'parcial' => true]);
            }
            $nome = mb_substr((string) preg_replace('/[\\\\\/:*?"<>|\r\n]+/', '_', $texto('nome', 200)), 0, 200) ?: 'arquivo';
            $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
            $permitidas = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'ogg', 'opus', 'mp3', 'm4a', 'aac', 'wav', 'webm', 'mp4', '3gp', 'mov', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'txt', 'csv', 'zip', 'rar', '7z', 'xml', 'json'];
            if (!in_array($ext, $permitidas, true)) {
                @unlink($parcial);
                $falhar('Tipo de arquivo não permitido (.' . $ext . ').');
            }
            $mime = (string) (mime_content_type($parcial) ?: 'application/octet-stream');
            $gravado = (bool) preg_match('/^gravacao-[0-9]+\.webm$/', $nome);
            $tipo = match (true) {
                $gravado || in_array($ext, ['ogg', 'opus', 'mp3', 'm4a', 'aac', 'wav'], true) => 'audio',
                in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && str_starts_with($mime, 'image/') => 'imagem',
                in_array($ext, ['mp4', '3gp', 'mov'], true) && str_starts_with($mime, 'video/') => 'video',
                default => 'documento',
            };
            if ($gravado) {
                $mime = 'audio/webm';
            }
            $mes = date('Ym');
            @mkdir($W::pastaMidia() . '/' . $mes, 0770, true);
            $relativo = $mes . '/glpi-' . bin2hex(random_bytes(10)) . '.' . $ext;
            if (!@rename($parcial, $W::pastaMidia() . '/' . $relativo)) {
                $falhar('Não foi possível guardar o arquivo.');
            }
            $_SESSION['centraldecontatos_uploads'][$id] = ['tipo' => $tipo, 'arquivo' => $relativo, 'mime' => $mime, 'nome' => $nome, 'tamanho' => (int) filesize($W::pastaMidia() . '/' . $relativo)];
            $responder(['success' => true, 'upload_id' => $id, 'tipo' => $tipo, 'nome' => $nome]);

        case 'enviar':
            $c = $conversaDoPost();
            $item = $itemDoPost(false);
            $midia = null;
            $up = (string) ($_POST['upload_id'] ?? '');
            if ($up !== '') {
                $midia = $_SESSION['centraldecontatos_uploads'][$up] ?? null;
                unset($_SESSION['centraldecontatos_uploads'][$up]);
                if (!$midia || !$W::caminhoMidia((string) $midia['arquivo'])) {
                    $falhar('O arquivo enviado não foi encontrado. Anexe de novo.');
                }
            }
            session_write_close();
            @set_time_limit(120);
            $r = $M::enviar($c, (string) ($_POST['texto'] ?? ''), $midia, (int) ($_POST['citar'] ?? 0), $item);
            $responder(['success' => $r['ok'], 'mensagem' => $r['mensagem'], 'id' => $r['id'] ?? 0]);

        case 'reagir':
            $m = $M::obter((int) ($_POST['mensagens_id'] ?? 0));
            if (!$m) {
                $falhar('Mensagem não encontrada.');
            }
            $emoji = mb_substr((string) ($_POST['emoji'] ?? ''), 0, 8);
            $r = $M::reagir($m, $emoji);
            $responder(['success' => $r['ok'], 'mensagem' => $r['mensagem']]);

        case 'vincular':
            $c = $conversaDoPost();
            $tipo = (string) ($_POST['itemtype'] ?? '');
            $id = (int) ($_POST['items_id'] ?? 0);
            if ($tipo !== '') {
                if (!isset($C::ITENS[$tipo])) {
                    $falhar('Tipo de item inválido.');
                }
                $obj = new $tipo();
                if (!$obj->getFromDB($id) || !$obj->canViewItem()) {
                    $falhar(ucfirst($C::ITENS_SINGULAR[$tipo]) . ' #' . $id . ' não encontrado ou sem permissão.');
                }
            }
            $V::vincular((int) $c['id'], $tipo, $id);
            $responder(['success' => true, 'mensagem' => $tipo === '' ? 'Vínculo removido.' : 'Conversa vinculada a ' . $V::rotuloItem($tipo, $id) . '.', 'conversa' => $V::paraTela($V::obter((int) $c['id']))]);

        case 'salvar_acompanhamento':
            $c = $conversaDoPost();
            @set_time_limit(180);
            $r = $V::salvarNoItem((int) $c['id']);
            $responder(['success' => $r['ok'], 'mensagem' => $r['mensagem'], 'followup' => $r['followup'], 'conversa' => $V::paraTela($V::obter((int) $c['id']))]);

        case 'limpar':
            $c = $conversaDoPost();
            @set_time_limit(180);
            $r = $V::limpar((int) $c['id']);
            $responder(['success' => $r['ok'], 'mensagem' => $r['mensagem'], 'conversa' => $V::paraTela($V::obter((int) $c['id']))]);

        case 'validacoes':
        case 'pedir_validacao':
            $c = $conversaDoPost();
            $tipo = (string) $c['itemtype'];
            $obj = isset(PluginCentraldecontatosValidacao::TIPOS[$tipo]) ? new $tipo() : null;
            if (!$obj || !$obj->getFromDB((int) $c['items_id']) || !$obj->canViewItem()) {
                $falhar('Vincule a conversa a um chamado ou mudança para pedir validação.');
            }
            if ($acao === 'validacoes') {
                $responder(['success' => true, 'item' => $V::rotuloItem($tipo, (int) $obj->getID()), 'validacoes' => PluginCentraldecontatosValidacao::pendentesDoItem($obj, $c)]);
            }
            session_write_close();
            @set_time_limit(60);
            [$ok, $mensagem] = PluginCentraldecontatosValidacao::enviar($c, $obj, (int) ($_POST['validacao_id'] ?? 0));
            $responder(['success' => $ok, 'mensagem' => $mensagem]);

        case 'arquivar':
            $c = $conversaDoPost();
            $V::arquivar((int) $c['id'], !empty($_POST['valor']));
            $responder(['success' => true, 'mensagem' => !empty($_POST['valor']) ? 'Conversa arquivada.' : 'Conversa reaberta.']);
    }
    $falhar('Ação desconhecida.');
} catch (\Throwable $e) {
    error_log('Plugin centraldecontatos: ' . $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine());
    $falhar('Não foi possível concluir a operação.');
}
