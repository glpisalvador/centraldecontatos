<?php

/**
 * Plugin Central de Contatos - pedido de validação (aprovação) pelo chat de WhatsApp.
 * O técnico envia, da conversa vinculada ao chamado ou à mudança, uma validação pendente para o celular do
 * aprovador; a resposta "1" (aprovar) ou "2" (recusar), com o motivo opcional na mesma mensagem, é gravada na
 * validação nativa do GLPI em nome do aprovador e confirmada ao cliente.
 */
class PluginCentraldecontatosValidacao
{
    public const TABELA = 'glpi_plugin_centraldecontatos_validacoes';

    /** Item ITIL => [classe da validação, chave do item] */
    public const TIPOS = [
        'Ticket' => ['TicketValidation', 'tickets_id'],
        'Change' => ['ChangeValidation', 'changes_id'],
    ];

    /** Aprovadores: GLPI 11/12 usa itemtype_target/items_id_target (usuário ou grupo); antigas, users_id_validate */
    public static function aprovadoresDe(array $campos): array
    {
        global $DB;
        $usuarios = [];
        $alvo = (string) ($campos['itemtype_target'] ?? '');
        $alvoId = (int) ($campos['items_id_target'] ?? 0);
        if ($alvo === 'User' && $alvoId > 0) {
            $usuarios[] = $alvoId;
        } elseif ($alvo === 'Group' && $alvoId > 0) {
            foreach ($DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_groups_users', 'WHERE' => ['groups_id' => $alvoId], 'LIMIT' => 100]) as $l) {
                $usuarios[] = (int) $l['users_id'];
            }
        } elseif ($alvo === '' && (int) ($campos['users_id_validate'] ?? 0) > 0) {
            $usuarios[] = (int) $campos['users_id_validate'];
        }
        return array_values(array_unique(array_filter($usuarios)));
    }

    /** Usuário (entre os aprovadores) cujo celular ou telefone é o número da conversa */
    public static function aprovadorDoNumero(array $aprovadores, string $chave): int
    {
        global $DB;
        if (!$aprovadores) {
            return 0;
        }
        foreach ($DB->request(['SELECT' => ['id', 'mobile', 'phone', 'phone2'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => $aprovadores, 'is_deleted' => 0]]) as $u) {
            foreach (['mobile', 'phone', 'phone2'] as $campo) {
                if (trim((string) $u[$campo]) !== '' && PluginCentraldecontatosConfig::chaveTelefone((string) $u[$campo]) === $chave) {
                    return (int) $u['id'];
                }
            }
        }
        return 0;
    }

    /** Validações pendentes do item, com quem aprova e se o número da conversa é de um aprovador */
    public static function pendentesDoItem(CommonITILObject $item, array $conversa): array
    {
        global $DB;
        $tipo = get_class($item);
        if (!isset(self::TIPOS[$tipo])) {
            return [];
        }
        [$classe, $fk] = self::TIPOS[$tipo];
        $lista = [];
        foreach ($DB->request(['FROM' => getTableForItemType($classe), 'WHERE' => [$fk => (int) $item->getID(), 'status' => CommonITILValidation::WAITING], 'ORDER' => 'id DESC']) as $v) {
            $aprovadores = self::aprovadoresDe($v);
            $nomes = array_map(fn($u) => (string) getUserName($u), $aprovadores);
            if (($v['itemtype_target'] ?? '') === 'Group') {
                $nomes = ['Grupo ' . Dropdown::getDropdownName('glpi_groups', (int) $v['items_id_target'])];
            }
            $enviado = $DB->request(['FROM' => self::TABELA, 'WHERE' => ['itemtype' => $classe, 'items_id' => (int) $v['id'], 'conversas_id' => (int) $conversa['id'], 'status' => 'aguardando'], 'LIMIT' => 1])->current();
            $lista[] = [
                'id'          => (int) $v['id'],
                'aprovadores' => implode(', ', array_filter($nomes)),
                'solicitante' => (string) getUserName((int) $v['users_id']),
                'quando'      => Html::convDateTime((string) ($v['submission_date'] ?? $v['date_creation'] ?? '')),
                'comentario'  => mb_strimwidth(trim(strip_tags(html_entity_decode((string) ($v['comment_submission'] ?? ''), ENT_QUOTES, 'UTF-8'))), 0, 300, '…'),
                'confere'     => self::aprovadorDoNumero($aprovadores, (string) $conversa['telefone']) > 0,
                'enviado'     => $enviado ? Html::convDateTime((string) $enviado['date_creation']) : '',
            ];
        }
        return $lista;
    }

    /** Envia o pedido pelo chat; devolve [ok, mensagem] */
    public static function enviar(array $conversa, CommonITILObject $item, int $validacaoId): array
    {
        global $DB;
        $tipo = get_class($item);
        if (!isset(self::TIPOS[$tipo])) {
            return [false, 'Só chamados e mudanças têm validação.'];
        }
        [$classe, $fk] = self::TIPOS[$tipo];
        $v = new $classe();
        if (!$v->getFromDB($validacaoId) || (int) $v->fields[$fk] !== (int) $item->getID()) {
            return [false, 'Validação não encontrada neste item.'];
        }
        if ((int) $v->fields['status'] !== CommonITILValidation::WAITING) {
            return [false, 'Esta validação já foi respondida.'];
        }
        $aprovador = self::aprovadorDoNumero(self::aprovadoresDe($v->fields), (string) $conversa['telefone']);
        if ($aprovador <= 0) {
            return [false, 'O número desta conversa não é o celular ou telefone de um aprovador desta validação. Cadastre o número no usuário aprovador para enviar por aqui.'];
        }
        $rotulo = $tipo === 'Change' ? 'Mudança' : 'Chamado';
        $descricao = mb_strimwidth(trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode((string) $item->fields['content'], ENT_QUOTES, 'UTF-8')))), 0, 600, '…');
        $comentario = trim(strip_tags(html_entity_decode((string) ($v->fields['comment_submission'] ?? ''), ENT_QUOTES, 'UTF-8')));
        $texto = "*Pedido de validação*\n"
            . $rotulo . ' #' . (int) $item->getID() . ' - ' . trim((string) $item->fields['name']) . "\n"
            . '*Solicitado por:* ' . getUserName((int) $v->fields['users_id']) . ' em ' . Html::convDateTime((string) ($v->fields['submission_date'] ?? date('Y-m-d H:i:s'))) . "\n"
            . ($comentario !== '' ? '*Comentário:* ' . mb_strimwidth($comentario, 0, 500, '…') . "\n" : '')
            . ($descricao !== '' ? "*Descrição:* " . $descricao . "\n" : '')
            . "\nResponda *1* para *APROVAR* ou *2* para *RECUSAR*.\nSe quiser, escreva o motivo depois do número (ex.: _2 falta o orçamento_).";
        $r = PluginCentraldecontatosMensagem::enviar($conversa, $texto, null, 0, $item);
        if (!$r['ok']) {
            return [false, $r['mensagem']];
        }
        $m = PluginCentraldecontatosMensagem::obter((int) $r['id']);
        // Um pedido anterior da mesma validação nesta conversa é substituído pelo novo
        $DB->update(self::TABELA, ['status' => 'substituido'], ['itemtype' => $classe, 'items_id' => $validacaoId, 'conversas_id' => (int) $conversa['id'], 'status' => 'aguardando']);
        $DB->insert(self::TABELA, [
            'conversas_id' => (int) $conversa['id'], 'mensagens_id' => (int) $r['id'], 'wa_id' => (string) ($m['wa_id'] ?? ''),
            'itemtype' => $classe, 'items_id' => $validacaoId, 'objeto_itemtype' => $tipo, 'objeto_id' => (int) $item->getID(),
            'users_id' => $aprovador, 'users_id_envio' => (int) Session::getLoginUserID(), 'status' => 'aguardando', 'date_creation' => date('Y-m-d H:i:s'),
        ]);
        return [true, 'Pedido de validação enviado para ' . getUserName($aprovador) . '.'];
    }

    /** 1/aprovar/sim ou 2/recusar/não no começo da mensagem; o resto é o motivo */
    public static function interpretar(string $texto): ?array
    {
        $t = trim($texto);
        if (preg_match('/^(1|aprovar|aprovo|aprovado|aprova|sim|ok)\b[\s.,:;!-]*(.*)$/isu', $t, $m)) {
            return [true, trim($m[2])];
        }
        if (preg_match('/^(2|recusar|recuso|recusado|recusa|reprovar|reprovo|n[aã]o)\b[\s.,:;!-]*(.*)$/isu', $t, $m)) {
            return [false, trim($m[2])];
        }
        return null;
    }

    /**
     * Mensagem recebida: se for a resposta a um pedido pendente desta conversa, grava a validação
     * e confirma ao cliente. Devolve true quando a mensagem era uma resposta.
     */
    public static function processarResposta(array $conversa, string $texto, string $citadaWaId): bool
    {
        global $DB;
        $resposta = self::interpretar($texto);
        if ($resposta === null) {
            return false;
        }
        $pendentes = iterator_to_array($DB->request(['FROM' => self::TABELA, 'WHERE' => ['conversas_id' => (int) $conversa['id'], 'status' => 'aguardando'], 'ORDER' => 'id DESC']), false);
        $pedido = null;
        foreach ($pendentes as $p) {
            $v = new $p['itemtype']();
            if (!$v->getFromDB((int) $p['items_id']) || (int) $v->fields['status'] !== CommonITILValidation::WAITING) {
                $DB->update(self::TABELA, ['status' => 'encerrada'], ['id' => $p['id']]);
                continue;
            }
            if ($citadaWaId !== '' && $p['wa_id'] === $citadaWaId) {
                $pedido = $p;
                break;
            }
            $pedido ??= $p;
        }
        if (!$pedido) {
            return false;
        }
        [$aprovado, $motivo] = $resposta;
        $r = self::responder($pedido, $aprovado, $motivo);
        $rotulo = ($pedido['objeto_itemtype'] === 'Change' ? 'Mudança' : 'Chamado') . ' #' . (int) $pedido['objeto_id'];
        $confirmacao = $r['ok']
            ? ($aprovado ? '✅ Validação *aprovada* para o ' . $rotulo . '. Obrigado!' : '❌ Validação *recusada* para o ' . $rotulo . '.' . ($motivo !== '' ? "\nMotivo registrado: " . $motivo : ''))
            : 'Não foi possível registrar sua resposta para o ' . $rotulo . ': ' . $r['erro'];
        $item = new $pedido['objeto_itemtype']();
        PluginCentraldecontatosMensagem::enviar($conversa, $confirmacao, null, 0, $item->getFromDB((int) $pedido['objeto_id']) ? $item : null);
        return true;
    }

    /** Grava a resposta na validação nativa em nome do aprovador */
    public static function responder(array $pedido, bool $aprovado, string $motivo): array
    {
        global $DB;
        $classe = (string) $pedido['itemtype'];
        $v = new $classe();
        if (!$v->getFromDB((int) $pedido['items_id'])) {
            return ['ok' => false, 'erro' => 'validação não encontrada'];
        }
        if ((int) $v->fields['status'] !== CommonITILValidation::WAITING) {
            $DB->update(self::TABELA, ['status' => 'encerrada'], ['id' => $pedido['id']]);
            return ['ok' => false, 'erro' => 'este pedido já foi respondido'];
        }
        $comentario = $motivo !== '' ? $motivo : ($aprovado ? 'Aprovado pelo WhatsApp.' : 'Recusado pelo WhatsApp.');
        $status = $aprovado ? CommonITILValidation::ACCEPTED : CommonITILValidation::REFUSED;
        // O webhook não tem sessão: assume o aprovador só durante a gravação e volta ao estado anterior
        $antes = $_SESSION;
        self::assumirUsuario((int) $pedido['users_id']);
        try {
            $ok = $v->update(['id' => (int) $pedido['items_id'], 'status' => $status, 'comment_validation' => $comentario, 'validation_date' => date('Y-m-d H:i:s')]);
        } finally {
            $_SESSION = $antes;
        }
        $v->getFromDB((int) $pedido['items_id']);
        if (!$ok || (int) $v->fields['status'] !== $status) {
            return ['ok' => false, 'erro' => 'o GLPI não aceitou a resposta'];
        }
        $DB->update(self::TABELA, ['status' => $aprovado ? 'aprovado' : 'recusado', 'comentario' => $comentario, 'date_resposta' => date('Y-m-d H:i:s')], ['id' => $pedido['id']]);
        return ['ok' => true, 'erro' => null];
    }

    /** Sessão mínima do aprovador (perfil e direitos) para o GLPI aceitar a resposta como dele */
    private static function assumirUsuario(int $uid): void
    {
        global $DB;
        $u = new User();
        if (!$u->getFromDB($uid)) {
            return;
        }
        $_SESSION['glpiID'] = $uid;
        $_SESSION['glpiname'] = $u->fields['name'];
        $_SESSION['glpirealname'] = $u->fields['realname'] ?? '';
        $_SESSION['glpifirstname'] = $u->fields['firstname'] ?? '';
        $_SESSION['glpi_use_mode'] = Session::NORMAL_MODE;
        $_SESSION['glpiactive_entity'] = (int) $u->fields['entities_id'];
        $entidades = [(int) $u->fields['entities_id']];
        $perfil = 0;
        foreach ($DB->request(['SELECT' => ['entities_id', 'profiles_id'], 'FROM' => 'glpi_profiles_users', 'WHERE' => ['users_id' => $uid], 'ORDER' => 'is_default_profile DESC']) as $l) {
            $entidades[] = (int) $l['entities_id'];
            $perfil = $perfil ?: (int) $l['profiles_id'];
        }
        $_SESSION['glpiactiveentities'] = array_values(array_unique($entidades));
        $_SESSION['glpiactiveentities_string'] = implode(',', $_SESSION['glpiactiveentities']);
        $p = new Profile();
        if ($perfil > 0 && $p->getFromDB($perfil)) {
            $_SESSION['glpiactiveprofile'] = $p->fields;
            $_SESSION['glpiactiveprofile']['id'] = $perfil;
            foreach ($DB->request(['SELECT' => ['name', 'rights'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['profiles_id' => $perfil]]) as $d) {
                $_SESSION['glpiactiveprofile'][$d['name']] = (int) $d['rights'];
            }
        }
    }
}
