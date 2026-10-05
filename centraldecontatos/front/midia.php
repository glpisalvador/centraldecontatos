<?php

/**
 * Plugin Central de Contatos - entrega a mídia de uma mensagem (imagem, áudio, vídeo, documento)
 * só para quem pode usar a central. Imagem, áudio e vídeo abrem no navegador; documentos são baixados.
 */

Session::checkLoginUser();
if (!PluginCentraldecontatosConfig::perfilPermitido()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$m = PluginCentraldecontatosMensagem::obter((int) ($_GET['id'] ?? 0));
$caminho = $m ? PluginCentraldecontatosWhatsapp::caminhoMidia((string) $m['midia_arquivo']) : null;
if (!$caminho) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

$mime = (string) $m['midia_mime'];
if ($mime === '' || !preg_match('#^[a-z]+/[a-z0-9.+-]+$#i', $mime)) {
    $mime = (string) (mime_content_type($caminho) ?: 'application/octet-stream');
}
$embutir = (bool) preg_match('#^(image|audio|video)/#', $mime) && !str_contains($mime, 'svg');
$nome = trim((string) $m['midia_nome']) !== '' ? (string) $m['midia_nome'] : basename($caminho);
$nome = (string) preg_replace('/[\r\n"\\\\]+/', '_', $nome);
$tamanho = (int) filesize($caminho);

session_write_close();
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Áudio e vídeo pedem pedaços (Range) para poder avançar na reprodução
$inicio = 0;
$fim = $tamanho - 1;
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', (string) $_SERVER['HTTP_RANGE'], $r)) {
    $inicio = $r[1] !== '' ? (int) $r[1] : max(0, $tamanho - (int) $r[2]);
    $fim = ($r[1] !== '' && $r[2] !== '') ? min((int) $r[2], $tamanho - 1) : $tamanho - 1;
    if ($inicio > $fim || $inicio >= $tamanho) {
        http_response_code(416);
        header('Content-Range: bytes */' . $tamanho);
        exit;
    }
    http_response_code(206);
    header('Content-Range: bytes ' . $inicio . '-' . $fim . '/' . $tamanho);
}
header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($fim - $inicio + 1));
header('Content-Disposition: ' . ($embutir ? 'inline' : 'attachment') . '; filename="' . $nome . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');

$h = fopen($caminho, 'rb');
fseek($h, $inicio);
$falta = $fim - $inicio + 1;
while ($falta > 0 && !feof($h)) {
    $parte = fread($h, (int) min(1048576, $falta));
    echo $parte;
    $falta -= strlen((string) $parte);
    flush();
}
fclose($h);
exit;
