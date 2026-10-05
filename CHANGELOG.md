# Histórico de versões

O arquivo para download de cada versão está em [Releases](https://github.com/glpisalvador/centraldecontatos/releases).

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
