<?php

/**
 * Plugin Central de Contatos - entrada do servidor WhatsApp (sem sessão; autenticada pelo token interno).
 * Eventos: mensagem (recebida ou enviada pelo celular), status (entregue/lida), reacao, conexao, teste.
 * O caminho é declarado público e sem sessão no setup.php. Resposta única no final (sem exit).
 */

$http = 200;
$resposta = ['ok' => false, 'erro' => 'Evento desconhecido'];

$corpo = json_decode((string) file_get_contents('php://input'), true);
$corpo = is_array($corpo) ? $corpo : [];
$token = (string) ($_SERVER['HTTP_X_TOKEN_INTERNO'] ?? ($corpo['token'] ?? ''));
$oficial = (string) PluginCentraldecontatosConfig::getConfig('wa_token');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $http = 405;
    $resposta = ['ok' => false, 'erro' => 'Método não permitido'];
} elseif (strlen($oficial) < 32 || !hash_equals($oficial, $token)) {
    error_log('Plugin centraldecontatos: webhook recusado (token inválido) de ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    $http = 403;
    $resposta = ['ok' => false, 'erro' => 'Token inválido'];
} else {
    try {
        switch ((string) ($corpo['evento'] ?? '')) {
            case 'teste':
                $resposta = ['ok' => true, 'teste' => true];
                break;
            case 'mensagem':
                if (trim((string) ($corpo['telefone'] ?? '')) === '') {
                    $http = 400;
                    $resposta = ['ok' => false, 'erro' => 'Sem telefone'];
                    break;
                }
                $resposta = ['ok' => true, 'id' => PluginCentraldecontatosMensagem::receber($corpo)];
                break;
            case 'status':
                $resposta = ['ok' => true, 'atualizadas' => PluginCentraldecontatosMensagem::atualizarStatus(is_array($corpo['itens'] ?? null) ? $corpo['itens'] : [])];
                break;
            case 'reacao':
                $resposta = ['ok' => true, 'marcada' => PluginCentraldecontatosMensagem::registrarReacao((string) ($corpo['wa_id'] ?? ''), (string) ($corpo['emoji'] ?? ''), empty($corpo['de_mim']))];
                break;
            case 'conexao':
                if (!empty($corpo['numero'])) {
                    PluginCentraldecontatosConfig::setConfig('wa_numero', mb_substr((string) $corpo['numero'], 0, 30));
                }
                $resposta = ['ok' => true];
                break;
            default:
                $http = 400;
        }
    } catch (\Throwable $e) {
        error_log('Plugin centraldecontatos (webhook): ' . $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine());
        $http = 500;
        $resposta = ['ok' => false, 'erro' => 'Falha ao processar'];
    }
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
http_response_code($http);
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}
echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
