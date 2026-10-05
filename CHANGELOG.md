# Histórico de versões

O arquivo para download de cada versão está em [Releases](https://github.com/glpisalvador/centraldecontatos/releases).

## 3.2.0 — 2026-10-04

Validação pelo WhatsApp, citação com mídia, envio múltiplo e áudio.

- **Salvar** e **Limpar** passam a gravar sempre a **conversa inteira**, com todos os anexos. Na página do item, ela recarrega para mostrar o acompanhamento novo.
- **Pedido de validação pelo chat**, em chamados e mudanças:
  - lista as validações aguardando resposta e envia o pedido ao aprovador;
  - só envia se o número for o celular ou telefone cadastrado de um aprovador;
  - a resposta `1` (aprovar) ou `2 motivo` (recusar) é gravada na validação nativa em nome do aprovador, e o contato recebe a confirmação.
- **Responder citando** mostra o autor, o texto e a miniatura da foto ou vídeo, ou o ícone de áudio ou documento, e leva à mensagem original. No celular, a citação aparece com a mídia original.
- **Mídias:** vários arquivos de uma vez, colar ou arrastar. Cada um é enviado ao terminar de subir, com barra de progresso e o chat travado até concluir.
- **Gravar e enviar áudio** com tempo, descartar e enviar. Em HTTP, o botão abre a escolha de um arquivo de áudio, porque o navegador só libera o microfone em HTTPS.
- Saíram do chat o botão do `wa.me` e o de abrir a caixa de conversas.

## 3.1.0 — 2026-10-04

Salvar e limpar conversa.

- Botão **Salvar no acompanhamento**: grava a conversa no item vinculado como um acompanhamento formatado como o WhatsApp. Ele traz:
  - o cabeçalho com o contato, o telefone e o período;
  - balões por dia, citações, reações e status;
  - as mídias como documentos do item.
- Botão **Limpar conversa**, com confirmação no próprio botão. Antes de apagar, salva no item vinculado o que ainda não estava salvo; depois apaga as mensagens e mídias do chat e mantém a conversa e o vínculo.

## 3.0.0 — 2026-10-04

Servidor WhatsApp próprio e conversas em tempo real dentro do GLPI.

- Servidor WhatsApp (Node.js + Baileys) **instalado, ligado e vigiado pelo GLPI**:
  - o Node.js oficial é baixado com a soma SHA-256 conferida;
  - o aparelho é pareado por QR Code;
  - o vigia no crontab e a ação automática religam o servidor se ele cair.
- **Caixa de conversas** em Assistência → Central de contatos, com busca, filtros, não lidas e conversa nova pelo número.
- **Chat no item**, aberto pelo ícone de WhatsApp do contato e já vinculado ao chamado, problema ou mudança.
- **Mensagens:**
  - texto com formatação;
  - imagens, áudios, vídeos e documentos nos dois sentidos, com upload em partes;
  - reações;
  - status de envio, entrega e leitura;
  - mensagens enviadas pelo celular também aparecem.
- Mensagens recebidas viram **acompanhamento** no item aberto, com as mídias anexadas.
- **Aviso de mensagem nova** em qualquer tela.
- Tudo é guardado em tabelas do próprio plugin.

## 2.0.0 — 2026-10-03

Primeira versão publicada: contatos do item com ligação, WhatsApp e e-mail.

- Bloco **Contatos** no chamado, problema e mudança, com os requerentes, os observadores e a entidade do item, e seus telefones e e-mails.
- **Ligar** pelo discador (`tel:`), com uma janela para registrar o resultado e uma observação.
- **WhatsApp** pelo `wa.me`, com uma mensagem inicial configurável.
- **E-mail de verdade** pelo GLPI; as respostas voltam para o item pelo coletor nativo.
- Aba **Contatos** no item com o registro de cada contato feito. O contato pode virar acompanhamento, público ou privado.
- Configuração de perfis, itens, modelos de mensagem e de assunto, remetente e resultados de ligação.
