<?php

/**
 * Plugin Central de Contatos - configuração (marketplace). Cada aba é um formulário próprio que
 * faz POST para esta mesma página.
 */

use Glpi\DBAL\QueryExpression;

Session::checkLoginUser();

global $DB, $CFG_GLPI;
$C = PluginCentraldecontatosConfig::class;
$e = [$C, 'e'];

if (!$C::ehAdmin()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$ABAS = [
    'geral'    => ['ti ti-settings', 'Geral'],
    'mensagens' => ['ti ti-message', 'Mensagens e e-mail'],
    'situacao' => ['ti ti-chart-bar', 'Situação'],
];
$aba = (string) ($_POST['aba'] ?? $_GET['aba'] ?? 'geral');
if (!isset($ABAS[$aba])) {
    $aba = 'geral';
}
$linha = fn(string $campo, int $max) => mb_substr(trim((string) preg_replace('/\s+/', ' ', (string) ($_POST[$campo] ?? ''))), 0, $max);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_action'])) {
    switch ((string) $_POST['save_action']) {
        case 'salvar_geral':
            $C::setArrayConfig('perfis', $C::idsPost('perfis'));
            $C::setArrayConfig('itens', array_values(array_intersect(array_keys($C::ITENS), array_map('strval', (array) ($_POST['itens'] ?? [])))));
            foreach (['registrar_ligacao', 'registrar_whatsapp', 'registrar_email', 'privado', 'email_privado'] as $chave) {
                $C::setConfig($chave, empty($_POST[$chave]) ? '0' : '1');
            }
            $resultados = array_values(array_unique(array_filter(array_map(fn($l) => mb_substr(trim($l), 0, 100), preg_split('/\r\n|\r|\n/', (string) ($_POST['resultados'] ?? '')) ?: []))));
            $C::setArrayConfig('resultados', $resultados ?: $C::padroes()['resultados']);
            Session::addMessageAfterRedirect('Configuração salva.', false, INFO);
            break;

        case 'salvar_mensagens':
            $erro = false;
            foreach (['remetente_email', 'responder_para'] as $campo) {
                $v = $linha($campo, 255);
                if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                    Session::addMessageAfterRedirect('E-mail inválido: ' . $v, false, ERROR);
                    $erro = true;
                }
            }
            if ($erro) {
                break;
            }
            $C::setConfig('codigo_pais', (string) preg_replace('/\D+/', '', (string) ($_POST['codigo_pais'] ?? '')));
            $C::setConfig('modelo_whatsapp', mb_substr(trim((string) ($_POST['modelo_whatsapp'] ?? '')), 0, 1000));
            $C::setConfig('modelo_assunto', $linha('modelo_assunto', 255));
            $C::setConfig('remetente_email', $linha('remetente_email', 255));
            $C::setConfig('remetente_nome', $linha('remetente_nome', 120));
            $C::setConfig('responder_para', $linha('responder_para', 255));
            Session::addMessageAfterRedirect('Mensagens salvas.', false, INFO);
            break;
    }
}

Html::header('Central de Contatos', $_SERVER['PHP_SELF'] ?? '', 'config', 'plugin');

$form = fn(string $acao, string $abaForm) => '<form method="post" action="' . $e($C::url('config.form.php')) . '" class="centraldecontatos-form">'
    . '<input type="hidden" name="save_action" value="' . $e($acao) . '"><input type="hidden" name="aba" value="' . $e($abaForm) . '">';
$salvar = '<div class="centraldecontatos-rodape-form"><button type="submit" class="btn btn-sm centraldecontatos-btn-principal"><i class="ti ti-device-floppy"></i><span>Salvar</span></button></div>';
$card = fn(string $icone, string $titulo, string $corpo) => '<div class="card centraldecontatos-card"><div class="card-header"><h5><i class="' . $icone . '"></i> ' . $e($titulo) . '</h5></div><div class="card-body">' . $corpo . '</div></div>';
$campo = fn(string $rotulo, string $controle, string $dica = '') => '<div class="centraldecontatos-campo"><label>' . $e($rotulo) . '</label>' . $controle . ($dica !== '' ? '<small>' . $dica . '</small>' : '') . '</div>';
$switch = fn(string $nome, string $rotulo, string $descricao) => '<div class="form-check form-switch centraldecontatos-switch centraldecontatos-switch-linha"><input type="hidden" name="' . $nome . '" value="0">'
    . '<input class="form-check-input" type="checkbox" id="centraldecontatos-cfg-' . $nome . '" name="' . $nome . '" value="1"' . ($C::ligado($nome) ? ' checked' : '') . '>'
    . '<label class="form-check-label" for="centraldecontatos-cfg-' . $nome . '"><span>' . $e($rotulo) . '</span><small>' . $e($descricao) . '</small></label></div>';
$explicacao = fn(string $texto) => '<p class="centraldecontatos-explicacao"><i class="ti ti-info-circle"></i><span>' . $texto . '</span></p>';
$texto = fn(string $nome, string $valor, string $placeholder = '', string $tipo = 'text') => '<input type="' . $tipo . '" class="form-control form-control-sm" name="' . $nome . '" value="' . $e($valor) . '" placeholder="' . $e($placeholder) . '">';

echo '<div class="centraldecontatos-pagina centraldecontatos-config">';
echo '<ul class="nav nav-tabs centraldecontatos-abas">';
foreach ($ABAS as $chave => [$icone, $rotulo]) {
    echo '<li class="nav-item"><a class="nav-link' . ($aba === $chave ? ' active' : '') . '" href="#" data-aba="' . $chave . '"><i class="' . $icone . '"></i> ' . $e($rotulo) . '</a></li>';
}
echo '</ul>';

// ---------------------------------------------------------------- Geral
echo '<div data-aba-painel="geral"' . ($aba !== 'geral' ? ' hidden' : '') . '>' . $form('salvar_geral', 'geral');
$itensMarcados = $C::getArrayConfig('itens');
$itens = '';
foreach ($C::ITENS as $t => $rotulo) {
    $itens .= '<label class="centraldecontatos-opcao"><input type="checkbox" class="centraldecontatos-check" name="itens[]" value="' . $e($t) . '"' . (in_array($t, $itensMarcados, true) ? ' checked' : '') . '> ' . $e($rotulo) . '</label>';
}
echo '<div class="centraldecontatos-grade">';
echo $card('ti ti-shield-lock', 'Onde e para quem', $explicacao('O bloco "Central de contatos" aparece no formulário do item e na aba "Contatos", para quem pode adicionar acompanhamentos nele.')
    . $campo('Perfis com acesso', $C::multiselect('perfis', $C::listarPerfis(), $C::ids('perfis'), 'Todos da interface padrão'), 'Vazio: todos os perfis da interface padrão (técnicos).')
    . $campo('Mostrar em', '<div class="centraldecontatos-opcoes">' . $itens . '<input type="hidden" name="itens[]" value=""></div>'));
echo $card('ti ti-message', 'Registro como acompanhamento', $explicacao('Todo contato fica na aba "Contatos". Ligado aqui, ele também vira um acompanhamento do item (com histórico e notificações do GLPI).')
    . $switch('registrar_ligacao', 'Ligações', 'Ao ligar, abre uma janela para registrar o resultado.')
    . $switch('registrar_whatsapp', 'WhatsApp', 'Registra quando a conversa é aberta, com a mensagem usada.')
    . $switch('registrar_email', 'E-mails', 'Registra o e-mail enviado, com o texto.')
    . $switch('privado', 'Ligações e WhatsApp como acompanhamento privado', 'Visíveis só para técnicos.')
    . $switch('email_privado', 'E-mails como acompanhamento privado', 'Desligado: o requerente vê o e-mail que recebeu na linha do tempo.'));
echo $card('ti ti-list-check', 'Resultados de ligação', $campo('Opções (uma por linha)', '<textarea class="form-control form-control-sm" name="resultados" rows="7">' . $e(implode("\n", $C::getArrayConfig('resultados'))) . '</textarea>', 'Aparecem na janela que registra a ligação.'));
echo '</div>' . $salvar . Html::closeForm(false) . '</div>';

// ---------------------------------------------------------------- Mensagens e e-mail
echo '<div data-aba-painel="mensagens"' . ($aba !== 'mensagens' ? ' hidden' : '') . '>' . $form('salvar_mensagens', 'mensagens');
$vars = '';
foreach ($C::VARIAVEIS as $v => $d) {
    $vars .= '<code title="' . $e($d) . '">' . $e($v) . '</code> ';
}
$remetente = PluginCentraldecontatosEmail::remetente();
echo '<div class="centraldecontatos-grade">';
echo $card('ti ti-brand-whatsapp', 'WhatsApp', $campo('Código do país', $texto('codigo_pais', (string) $C::getConfig('codigo_pais'), '55'), 'Acrescentado aos números sem código do país (até 11 dígitos).')
    . $campo('Mensagem inicial', '<textarea class="form-control form-control-sm" name="modelo_whatsapp" rows="4">' . $e($C::getConfig('modelo_whatsapp')) . '</textarea>', 'Pode ser editada antes de abrir a conversa. Variáveis: ' . $vars));
echo $card('ti ti-mail', 'E-mail', ($remetente === null
        ? '<div class="centraldecontatos-alerta centraldecontatos-alerta-aviso"><i class="ti ti-alert-triangle"></i><span>O GLPI não tem remetente de e-mail configurado. Informe um abaixo ou em Configurar &gt; Notificações; sem isso o envio de e-mail fica desativado.</span></div>'
        : $explicacao('Remetente atual: <strong>' . $e($remetente[0]) . '</strong>' . ($remetente[1] !== '' ? ' (' . $e($remetente[1]) . ')' : '') . '.'))
    . $campo('Assunto', $texto('modelo_assunto', (string) $C::getConfig('modelo_assunto'), '[GLPI #{id}] {titulo}'), 'Variáveis: ' . $vars)
    . $campo('E-mail do remetente', $texto('remetente_email', (string) $C::getConfig('remetente_email'), 'Vazio: o das notificações do GLPI', 'email'))
    . $campo('Nome do remetente', $texto('remetente_nome', (string) $C::getConfig('remetente_nome'), 'Vazio: o das notificações do GLPI'))
    . $campo('Responder para', $texto('responder_para', (string) $C::getConfig('responder_para'), 'E-mail lido pelo coletor do GLPI', 'email'), 'Use a caixa de um coletor de e-mail ativo: a resposta do cliente volta ao item como acompanhamento.'));
echo '</div>' . $salvar . Html::closeForm(false) . '</div>';

// ---------------------------------------------------------------- Situação
echo '<div data-aba-painel="situacao"' . ($aba !== 'situacao' ? ' hidden' : '') . '>';
$porTipo = ['ligacao' => 0, 'whatsapp' => 0, 'email' => 0];
foreach ($DB->request([
    'SELECT'  => ['tipo', new QueryExpression('COUNT(*) AS n')],
    'FROM'    => PluginCentraldecontatosContato::TABELA,
    'WHERE'   => ['date_creation' => ['>=', date('Y-m-d H:i:s', strtotime('-30 days'))]],
    'GROUPBY' => 'tipo',
]) as $r) {
    $porTipo[(string) $r['tipo']] = (int) $r['n'];
}
$coletores = countElementsInTable('glpi_mailcollectors', ['is_active' => 1]);
$corpo = '<div class="centraldecontatos-numeros">'
    . '<div><strong>' . $porTipo['ligacao'] . '</strong><span>ligações (30 dias)</span></div>'
    . '<div><strong>' . $porTipo['whatsapp'] . '</strong><span>conversas de WhatsApp (30 dias)</span></div>'
    . '<div><strong>' . $porTipo['email'] . '</strong><span>e-mails (30 dias)</span></div>'
    . '<div><strong>' . (int) $coletores . '</strong><span>coletores de e-mail ativos</span></div>'
    . '</div>';
if ($coletores === 0) {
    $corpo .= '<div class="centraldecontatos-alerta centraldecontatos-alerta-aviso"><i class="ti ti-alert-triangle"></i><span>Nenhum coletor de e-mail ativo: os e-mails saem normalmente, mas as respostas não voltam para o GLPI. Configure em Configurar &gt; Coletores.</span></div>';
}
echo $card('ti ti-chart-bar', 'Situação', $corpo) . '</div>';

echo '</div>';
Html::footer();
