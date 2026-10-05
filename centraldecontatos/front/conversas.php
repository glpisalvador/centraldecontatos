<?php

/**
 * Plugin Central de Contatos - caixa de conversas de WhatsApp (lista + chat em tempo real)
 */

Session::checkLoginUser();
$C = PluginCentraldecontatosConfig::class;
if (!$C::perfilPermitido() || !$C::ligado('wa_ativo')) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$e = [$C, 'e'];

Html::header('Conversas', $_SERVER['PHP_SELF'] ?? '', 'helpdesk', 'PluginCentraldecontatosMenu', 'conversas');

echo '<div class="centraldecontatos-pagina centraldecontatos-wa-pagina" data-centraldecontatos-caixa>';
echo '<div class="centraldecontatos-wa-caixa">';

// ---------------------------------------------------------------- lista
echo '<aside class="centraldecontatos-wa-lateral">';
echo '<div class="centraldecontatos-wa-lateral-topo"><h5><i class="ti ti-brand-whatsapp"></i> Conversas <span class="centraldecontatos-badge" data-total-nao-lidas hidden></span></h5>'
    . '<span class="centraldecontatos-wa-servidor" data-servidor-geral></span>'
    . ($C::ehAdmin() ? '<a class="btn btn-sm btn-ghost-secondary" href="' . $e($C::url('whatsapp.php')) . '" title="Servidor WhatsApp"><i class="ti ti-server"></i></a>' : '')
    . '</div>';
echo '<div class="centraldecontatos-wa-busca"><input type="search" class="form-control form-control-sm" placeholder="Buscar nome, número, mensagem ou nº do item" data-busca></div>';
echo '<div class="nav nav-pills centraldecontatos-wa-filtros">'
    . '<button type="button" class="nav-link active" data-filtro="todas">Todas</button>'
    . '<button type="button" class="nav-link" data-filtro="naolidas">Não lidas</button>'
    . '<button type="button" class="nav-link" data-filtro="vinculadas">Com item</button>'
    . '<button type="button" class="nav-link" data-filtro="arquivadas">Arquivadas</button></div>';
echo '<div class="centraldecontatos-wa-lista" data-conversas><div class="centraldecontatos-vazio"><i class="ti ti-loader-2"></i> Carregando...</div></div>';
echo '<form class="centraldecontatos-wa-nova" data-nova><input type="tel" class="form-control form-control-sm" placeholder="Nova conversa: (DDD) número" data-nova-numero>'
    . '<button type="submit" class="btn btn-sm centraldecontatos-btn-principal" title="Abrir conversa"><i class="ti ti-message-plus"></i></button></form>';
echo '</aside>';

// ---------------------------------------------------------------- chat
echo '<section class="centraldecontatos-wa-painel" data-painel>'
    . '<div class="centraldecontatos-wa-vazio"><i class="ti ti-messages"></i><p>Escolha uma conversa ao lado ou abra uma nova pelo número.</p>'
    . '<small>No chamado, problema ou mudança, o ícone <i class="ti ti-brand-whatsapp"></i> de cada contato abre a conversa já vinculada ao item.</small></div>'
    . '</section>';

echo '</div></div>';
Html::footer();
