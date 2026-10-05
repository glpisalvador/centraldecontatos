<?php

/**
 * Plugin Central de Contatos - servidor WhatsApp: situação, pareamento (QR Code), preparação e log
 */

Session::checkLoginUser();
$C = PluginCentraldecontatosConfig::class;
if (!$C::ehAdmin()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
global $CFG_GLPI;
$e = [$C, 'e'];

Html::header('Servidor WhatsApp', $_SERVER['PHP_SELF'] ?? '', 'helpdesk', 'PluginCentraldecontatosMenu', 'whatsapp');

echo '<div class="centraldecontatos-pagina centraldecontatos-wa-servidor-pagina" data-centraldecontatos-servidor data-ajax="' . $e($C::url('ajax.php')) . '">';
if (!$C::ligado('wa_ativo')) {
    echo '<div class="centraldecontatos-alerta centraldecontatos-alerta-aviso"><i class="ti ti-alert-triangle"></i><span>O WhatsApp da Central está desligado na configuração do plugin (aba WhatsApp): o chat e a caixa de conversas ficam ocultos.</span></div>';
}
echo '<div class="centraldecontatos-grade">';

echo '<div class="card centraldecontatos-card"><div class="card-header centraldecontatos-wa-cab"><h5><i class="ti ti-brand-whatsapp"></i> Situação</h5><span class="centraldecontatos-selo" data-situacao>Consultando...</span></div><div class="card-body">'
    . '<table class="centraldecontatos-wa-dados"><tbody>'
    . '<tr><th>Número</th><td data-numero>—</td></tr>'
    . '<tr><th>Nome no WhatsApp</th><td data-nome>—</td></tr>'
    . '<tr><th>Conectado desde</th><td data-desde>—</td></tr>'
    . '<tr><th>Processo</th><td data-pid>—</td></tr>'
    . '<tr><th>Versões</th><td data-versoes>—</td></tr>'
    . '</tbody></table>'
    . '<div class="centraldecontatos-wa-acoes">'
    . '<button type="button" class="btn btn-sm centraldecontatos-btn-principal" data-acao="iniciar"><i class="ti ti-player-play"></i><span>Ligar</span></button>'
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-acao="reiniciar"><i class="ti ti-refresh"></i><span>Reiniciar</span></button>'
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-acao="parar" data-confirmar="Parar o servidor? As mensagens deixam de chegar até ligar de novo."><i class="ti ti-player-stop"></i><span>Parar</span></button>'
    . '<button type="button" class="btn btn-sm btn-ghost-danger" data-acao="desvincular" data-confirmar="Desvincular o aparelho? Será preciso ler um novo QR Code."><i class="ti ti-unlink"></i><span>Desvincular aparelho</span></button>'
    . '</div><div class="centraldecontatos-wa-retorno" data-retorno></div></div></div>';

echo '<div class="card centraldecontatos-card" data-qr-card hidden><div class="card-header"><h5><i class="ti ti-qrcode"></i> Parear o aparelho</h5></div><div class="card-body centraldecontatos-wa-qr">'
    . '<img alt="QR Code do WhatsApp" data-qr>'
    . '<ol><li>No celular, abra o WhatsApp do número que vai atender.</li><li>Toque em <strong>Configurações &gt; Aparelhos conectados &gt; Conectar um aparelho</strong>.</li><li>Aponte a câmera para este código. Ele se renova sozinho.</li></ol>'
    . '</div></div>';

echo '</div>';

echo '<div class="card centraldecontatos-card"><div class="card-header"><h5><i class="ti ti-list-check"></i> Preparação do servidor</h5></div><div class="card-body p-0"><ul class="centraldecontatos-wa-etapas" data-etapas><li class="centraldecontatos-vazio">Consultando...</li></ul></div></div>';

echo '<div class="card centraldecontatos-card"><div class="card-header centraldecontatos-wa-cab"><h5><i class="ti ti-file-text"></i> Registro do servidor</h5><label class="centraldecontatos-wa-seguir"><input type="checkbox" class="centraldecontatos-check" data-seguir checked> acompanhar</label></div><div class="card-body p-0"><pre class="centraldecontatos-wa-log" data-log></pre></div></div>';

echo '<p class="centraldecontatos-explicacao"><i class="ti ti-info-circle"></i><span>O servidor roda neste próprio servidor (porta ' . (int) PluginCentraldecontatosWhatsapp::porta() . ', só para 127.0.0.1) e avisa o GLPI pelo endereço '
    . '<code>' . $e(PluginCentraldecontatosWhatsapp::urlWebhook()) . '</code>. Arquivos, sessão do aparelho e mídias ficam em <code>' . $e(PluginCentraldecontatosWhatsapp::base()) . '</code>.</span></p>';
echo '</div>';
echo '<script src="' . $e($CFG_GLPI['root_doc'] . '/plugins/centraldecontatos/js/whatsapp.js?v=' . PLUGIN_CENTRALDECONTATOS_VERSION . '-' . (int) @filemtime(dirname(__DIR__) . '/public/js/whatsapp.js')) . '"></script>';
Html::footer();
