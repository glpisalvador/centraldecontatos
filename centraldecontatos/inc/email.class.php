<?php

use Symfony\Component\Mime\Address;

/**
 * Plugin Central de Contatos - envio de e-mail a partir do chamado, problema ou mudança.
 * Usa o GLPIMailer do GLPI 11/12 (Symfony Mailer). O e-mail sai com os cabeçalhos de referência
 * do próprio GLPI (In-Reply-To/References do item), então a resposta do destinatário é anexada ao
 * item como acompanhamento pelo coletor de e-mail nativo, sem nenhum processamento do plugin.
 */
class PluginCentraldecontatosEmail
{
    public const LIMITE_DESTINOS = 20;

    /** [email, nome] do remetente: o da configuração do plugin ou o das notificações do GLPI */
    public static function remetente(): ?array
    {
        global $CFG_GLPI;
        $C = PluginCentraldecontatosConfig::class;
        foreach ([$C::getConfig('remetente_email'), $CFG_GLPI['from_email'] ?? '', $CFG_GLPI['admin_email'] ?? ''] as $email) {
            $email = trim((string) $email);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $nome = trim((string) $C::getConfig('remetente_nome')) ?: trim((string) ($CFG_GLPI['from_email_name'] ?? '') ?: (string) ($CFG_GLPI['admin_email_name'] ?? ''));
                return [$email, $nome];
            }
        }
        return null;
    }

    /** [Message-Id deste e-mail, referência estável do item] no formato que o coletor do GLPI reconhece */
    public static function referencias(CommonITILObject $item): array
    {
        $classe = get_class($item);
        return [
            NotificationTarget::getMessageIdForEvent($classe, (int) $item->getID(), 'centraldecontatos'),
            NotificationTarget::getMessageIdForEvent($classe, (int) $item->getID(), $classe::getMessageReferenceEvent('new') ?? 'new'),
        ];
    }

    /** Separa e valida uma lista de e-mails (vírgula, ponto e vírgula, espaço ou quebra de linha) */
    public static function lista($valor): array
    {
        $saida = [];
        foreach ((array) $valor as $parte) {
            foreach (preg_split('/[\s,;]+/', (string) $parte) ?: [] as $email) {
                $email = trim($email);
                if ($email !== '') {
                    $saida[mb_strtolower($email)] = $email;
                }
            }
        }
        return array_values($saida);
    }

    /**
     * Envia o e-mail e registra (tabela e acompanhamento). Retorna ['ok', 'mensagem'].
     */
    public static function enviar(CommonITILObject $item, array $para, array $copia, string $assunto, string $corpo, string $contato = ''): array
    {
        $para = self::lista($para);
        $copia = array_values(array_diff(self::lista($copia), $para));
        $invalidos = array_values(array_filter(array_merge($para, $copia), fn($e) => !filter_var($e, FILTER_VALIDATE_EMAIL)));
        if ($invalidos) {
            return ['ok' => false, 'mensagem' => 'E-mail inválido: ' . implode(', ', $invalidos) . '.'];
        }
        if (!$para) {
            return ['ok' => false, 'mensagem' => 'Informe pelo menos um destinatário.'];
        }
        if (count($para) + count($copia) > self::LIMITE_DESTINOS) {
            return ['ok' => false, 'mensagem' => 'No máximo ' . self::LIMITE_DESTINOS . ' destinatários por envio.'];
        }
        $assunto = trim((string) preg_replace('/[\r\n]+/', ' ', $assunto));
        if ($assunto === '') {
            return ['ok' => false, 'mensagem' => 'Informe o assunto.'];
        }
        if (trim(strip_tags($corpo, '<img>')) === '') {
            return ['ok' => false, 'mensagem' => 'Escreva a mensagem.'];
        }
        $remetente = self::remetente();
        if ($remetente === null) {
            return ['ok' => false, 'mensagem' => 'Não há remetente de e-mail: informe um na configuração do plugin ou em Configurar > Notificações do GLPI.'];
        }

        $html = \Glpi\RichText\RichText::getSafeHtml($corpo);
        $texto = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>', '</tr>'], "\n", $html)), ENT_QUOTES, 'UTF-8'));
        $erro = '';
        try {
            $mail = new GLPIMailer();
            $msg = $mail->getEmail();
            $msg->from(new Address($remetente[0], $remetente[1]));
            foreach ($para as $email) {
                $msg->addTo(new Address($email));
            }
            foreach ($copia as $email) {
                $msg->addCc(new Address($email));
            }
            $responder = trim((string) PluginCentraldecontatosConfig::getConfig('responder_para'));
            if (filter_var($responder, FILTER_VALIDATE_EMAIL)) {
                $msg->replyTo(new Address($responder));
            }
            $msg->subject($assunto);
            $msg->html('<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#333;line-height:1.5;">' . $html . '</div>', 'utf-8');
            $msg->text($texto, 'utf-8');
            // Referência do item no padrão do GLPI: a resposta volta para este item pelo coletor nativo
            [$messageId, $referencia] = self::referencias($item);
            $cabecalhos = $msg->getHeaders();
            $cabecalhos->remove('Message-Id');
            $cabecalhos->addHeader('Message-Id', $messageId);
            $cabecalhos->addHeader('In-Reply-To', $referencia);
            $cabecalhos->addHeader('References', $referencia);
            $cabecalhos->addTextHeader('X-Auto-Response-Suppress', 'OOF, DR, RN, NRN, AutoReply');
            $ok = (bool) $mail->send();
            if (!$ok) {
                $erro = (string) ($mail->getError() ?: 'o servidor de e-mail recusou o envio');
            }
        } catch (\Throwable $e) {
            $ok = false;
            $erro = $e->getMessage();
        }

        $destino = implode(', ', $para) . ($copia ? ' · cópia: ' . implode(', ', $copia) : '');
        PluginCentraldecontatosContato::registrar($item, 'email', [
            'contato'    => $contato,
            'destino'    => $destino,
            'assunto'    => $assunto,
            'corpo'      => $html,
            'sucesso'    => $ok,
            'observacao' => $ok ? '' : 'Falha no envio: ' . $erro,
        ]);
        if (!$ok) {
            return ['ok' => false, 'mensagem' => 'O e-mail não foi enviado: ' . $erro];
        }
        return ['ok' => true, 'mensagem' => 'E-mail enviado para ' . $destino . '.'];
    }
}
