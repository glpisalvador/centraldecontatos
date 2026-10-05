<?php

/**
 * Plugin Central de Contatos - endpoint AJAX (sempre JSON).
 * registrar (POST): ligação ou WhatsApp feitos pelo bloco; enviar_email (POST): envia e registra.
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
$responder = function (array $dados) use ($C): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $dados['new_token'] = $C::tokenCsrf();
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
$falhar = fn(string $mensagem) => $responder(['success' => false, 'mensagem' => $mensagem]);

if (!Session::getLoginUserID()) {
    $falhar('Sessão expirada. Recarregue a página.');
}
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $falhar('Requisição inválida.');
}

$itemtype = (string) ($_POST['itemtype'] ?? '');
if (!isset($C::ITENS[$itemtype])) {
    $falhar('Item inválido.');
}
$item = new $itemtype();
if (!$item->getFromDB((int) ($_POST['items_id'] ?? 0)) || !$K::podeUsar($item)) {
    $falhar('Você não pode registrar contatos neste item.');
}
$texto = fn(string $campo, int $max) => mb_substr(trim((string) ($_POST[$campo] ?? '')), 0, $max);

try {
    switch ((string) ($_POST['action'] ?? '')) {
        case 'registrar':
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
                'contato'    => $texto('contato', 255),
                'destino'    => $destino,
                'resultado'  => $resultado,
                'observacao' => $texto('observacao', 5000),
                'mensagem'   => $texto('mensagem', 2000),
            ]);
            $responder(['success' => true, 'followup' => $followup, 'mensagem' => $followup > 0 ? 'Contato registrado como acompanhamento.' : 'Contato registrado.']);

        case 'enviar_email':
            $r = PluginCentraldecontatosEmail::enviar(
                $item,
                (array) ($_POST['para'] ?? []),
                (array) ($_POST['copia'] ?? []),
                $texto('assunto', 255),
                (string) ($_POST['corpo'] ?? ''),
                $texto('contato', 255)
            );
            $responder(['success' => $r['ok'], 'mensagem' => $r['mensagem']]);
    }
    $falhar('Ação desconhecida.');
} catch (\Throwable $e) {
    error_log('Plugin centraldecontatos: ' . $e->getMessage());
    $falhar('Não foi possível concluir a operação.');
}
