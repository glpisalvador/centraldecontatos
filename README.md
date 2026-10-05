# Central de Contatos para GLPI

Plugin para **GLPI 11 e 12** que reúne, dentro do chamado, do problema e da mudança, todas as formas de falar com as pessoas envolvidas: **ligação**, **e-mail** e **WhatsApp**. O WhatsApp tem um **servidor próprio** que o GLPI instala e administra. As conversas acontecem em tempo real dentro do GLPI, ficam guardadas e viram acompanhamentos do item.

> Autor: **GLPI Salvador** · Licença: **GPLv2+** · Compatível com GLPI **11.0.0 a 12.x** · PHP **8.1+**

---

## Download e instalação

1. Baixe o arquivo `centraldecontatos-X.Y.Z.zip` da **[última versão](../../releases/latest)**. Use o arquivo anexado à release, não o "Source code".
2. Descompacte dentro da pasta `plugins/` do GLPI. O resultado deve ser `plugins/centraldecontatos/setup.php`.
3. Ajuste o dono dos arquivos para o usuário do servidor web, por exemplo:
   ```bash
   chown -R www-data:www-data /var/www/glpi/plugins/centraldecontatos
   ```
4. No GLPI, vá em **Configurar → Plugins**, clique em **Instalar** e depois em **Ativar** na *Central de Contatos*.
   Se preferir a linha de comando:
   ```bash
   php bin/console plugin:install centraldecontatos -u glpi
   php bin/console plugin:activate centraldecontatos
   ```
5. Abra a configuração pelo ícone de engrenagem do plugin e escolha **quais perfis** podem usar a central.

A instalação cria as tabelas, as configurações padrão e a ação automática. Funciona num GLPI sem nada configurado antes.

### Atualização

Substitua a pasta `plugins/centraldecontatos` pela versão nova e rode **Instalar** de novo, ou `php bin/console plugin:install centraldecontatos -f`. As colunas e tabelas novas são criadas sem perder dados. Depois, ative o plugin.

### Desinstalação

A desinstalação **não apaga nada**: tabelas, conversas, mídias e o pareamento do aparelho continuam guardados, e reinstalar recupera tudo. Ela apenas desliga o servidor WhatsApp e o vigia.

---

## O que o plugin faz

### 1. Bloco de contatos no chamado, problema e mudança

No formulário do item aparece o bloco **Contatos**. Ele traz os requerentes, os observadores e a entidade do item, com os telefones e e-mails cadastrados. Cada contato tem três ações:

| Ação | O que acontece |
|---|---|
| 📞 **Ligar** | Abre o discador do computador ou do celular (`tel:`). Em seguida, uma janela pede o **resultado da ligação** (Atendeu, Não atendeu, Caixa postal…, com a lista configurável) e uma observação. |
| 💬 **WhatsApp** | Abre o **chat do GLPI** com esse número, já vinculado ao item. Se o servidor próprio estiver desligado, abre o `wa.me` numa nova aba. |
| ✉️ **E-mail** | Envia um **e-mail de verdade** pelo GLPI: para, cópia, assunto com o número do item e texto formatado. As respostas do destinatário voltam para o próprio item pelo **coletor de e-mails nativo** do GLPI. |

- Cada contato feito fica registrado na aba **Contatos** do item: quem fez, quando, com quem, o número ou endereço, o resultado e a observação.
- Pode também virar um **acompanhamento** do item, com histórico e notificações do GLPI. Isso é configurável por tipo de contato, e o acompanhamento pode ser público ou privado.
- O texto inicial do WhatsApp e o assunto do e-mail são **modelos configuráveis**, com as variáveis `{nome}`, `{tecnico}`, `{tipo}`, `{id}` e `{titulo}`.

### 2. Servidor WhatsApp próprio

O plugin traz um servidor WhatsApp (Node.js + [Baileys](https://github.com/WhiskeySockets/Baileys)) que é **instalado, ligado e vigiado pelo próprio GLPI**. Não é preciso nenhum serviço externo nem API paga: você pareia um número de WhatsApp lendo um QR Code, como no WhatsApp Web.

- **Instalação pela tela:** o GLPI baixa o Node.js LTS oficial de `nodejs.org` (com a soma SHA-256 conferida) e instala as dependências. Tudo fica em `files/_plugins/centraldecontatos/`.
- **Pareamento:** em *Configurar → Plugins → Central de Contatos → aba WhatsApp → Conectar aparelho*, ligue o servidor e leia o QR Code no celular, em **Aparelhos conectados**.
- **Segurança:**
  - o servidor atende só em `127.0.0.1`, na porta configurável 3470;
  - as chamadas levam um token interno gerado na instalação;
  - o GLPI recebe os eventos por um endereço próprio, autenticado por esse mesmo token.
- **Vigia:** uma linha no `crontab` do usuário do servidor web religa o processo em até 1 minuto se ele cair. A ação automática do GLPI também confere a cada 5 minutos.
- **Tela do servidor:** situação, número conectado, versões, etapas da instalação, log e botões para iniciar, parar, reiniciar, desvincular o aparelho e testar o webhook.

### 3. Conversas em tempo real dentro do GLPI

- **Caixa de conversas** em *Assistência → Central de contatos*:
  - lista de conversas com busca e filtros (Todas, Não lidas, Com item, Arquivadas);
  - prévia da última mensagem, contador de não lidas e início de conversa nova pelo número.
- **Chat no item:** o ícone de WhatsApp do contato abre o chat numa janela e **vincula a conversa** ao chamado, problema ou mudança.
- **Mensagens:**
  - texto com a formatação do WhatsApp (*negrito*, _itálico_, ~riscado~, links);
  - status de envio ✓, entregue ✓✓ e lida ✓✓ azul;
  - reações com emoji;
  - mensagens enviadas pelo próprio celular também aparecem.
- **Responder citando:** a mensagem citada aparece no balão com o autor, o texto e a **miniatura** da foto ou do vídeo, ou o ícone de áudio ou documento. Clicar nela leva à mensagem original. Funciona nos dois sentidos, e no celular do contato a citação mostra a mídia original.
- **Mídias:**
  - imagens, vídeos, áudios, figurinhas e documentos, nos dois sentidos;
  - dá para **escolher vários arquivos, colar ou arrastar** para o chat;
  - cada arquivo é enviado assim que termina de subir, um de cada vez, com barra de progresso;
  - o chat fica travado até terminar;
  - o envio é em partes, então funciona mesmo com limite baixo de upload no PHP.
- **Áudio:** grava e envia pelo navegador, com tempo, descartar e enviar. O navegador só libera o microfone com o GLPI em **HTTPS**. Em HTTP, o botão abre a escolha de um arquivo de áudio.
- **Aviso de mensagem nova** em qualquer tela do GLPI: botão flutuante com o número de não lidas e aviso na tela.
- **Confirmação de leitura:** opcional. Ao abrir a conversa, o contato vê os ✓✓ azuis.

### 4. Integração com o chamado, problema e mudança

- **Mensagens recebidas viram acompanhamento:** quando a conversa está vinculada a um item aberto, cada mensagem recebida entra na linha do tempo, com as mídias anexadas como documentos. É configurável e pode ser privado.
- **Mensagens enviadas** pelo chat do item podem ser registradas como acompanhamento.
- **💾 Salvar no acompanhamento:** grava a **conversa inteira**, com todos os anexos, num acompanhamento formatado como o WhatsApp. Ele traz:
  - o cabeçalho com o contato, o telefone, o período e quem registrou;
  - balões por dia, com autor, hora, status de envio, citações, reações e mídias.

  Se você estiver na página do próprio item, ela recarrega e o acompanhamento novo aparece na linha do tempo.
- **🧽 Limpar conversa:** pede confirmação no próprio botão. Antes de apagar, salva a conversa inteira no acompanhamento do item vinculado. Depois apaga as mensagens e as mídias do chat; a conversa e o vínculo continuam.
- **Vincular** a conversa a outro chamado, problema ou mudança, ou remover o vínculo, direto pelo chat.

### 5. Pedido de validação pelo WhatsApp

Em conversas vinculadas a um **chamado** ou **mudança**, o botão de validação lista as validações **aguardando resposta**, com aprovador, quem pediu e comentário, e envia o pedido pelo chat.

- Só envia se o número da conversa for o **celular ou telefone cadastrado de um aprovador** da validação (usuário ou membro do grupo). Assim, ninguém aprova em nome de outra pessoa.
- O aprovador responde **1** para aprovar ou **2** para recusar, e pode escrever o motivo depois do número (ex.: `2 falta o orçamento`).
- A resposta é gravada na **validação nativa do GLPI** em nome do aprovador, com o comentário, e o contato recebe a confirmação.
- Mensagens comuns ("bom dia") não são tratadas como resposta, e uma validação já respondida não muda mais.

---

## Configuração

Fica em *Configurar → Plugins → Central de Contatos* (ícone de engrenagem), dividida em abas:

| Aba | Opções |
|---|---|
| **Geral** | Perfis com acesso (vazio: todos os técnicos); em quais itens o bloco aparece (chamado, problema, mudança); quais contatos viram acompanhamento (ligação, WhatsApp, e-mail) e se ficam privados; resultados possíveis de uma ligação. |
| **Mensagens** | Código do país; mensagem inicial do WhatsApp; assunto do e-mail; e-mail e nome do remetente (vazio: o das notificações do GLPI); endereço "responder para", que deve ser uma caixa lida por um coletor, para a resposta voltar ao item. |
| **WhatsApp** | Conexão do aparelho; usar o servidor próprio; avisar mensagens novas em qualquer tela; confirmar leitura; mensagens recebidas como acompanhamento (e se privadas); tamanho máximo das mídias; porta local; endereço do webhook; aceitar certificado HTTPS autoassinado. |
| **Situação** | Ligações, conversas de WhatsApp e e-mails dos últimos 30 dias, e aviso se não houver coletor de e-mail ativo. |

Nada é fixo no código: endereços, porta, remetentes, textos e perfis são configurados pela tela.

---

## Requisitos

- **GLPI** 11.0.0 ou superior, testado no 12.
- **PHP** 8.1+ com a extensão `curl`.
- **Para o WhatsApp próprio**, num servidor Linux x86_64 ou arm64:
  - o PHP precisa poder executar comandos (`shell_exec`/`proc_open`), para ligar o Node.js;
  - acesso à internet na instalação, para baixar o Node.js e as dependências do `npm`;
  - opcional: `crontab`, para o vigia.
- O **coletor de e-mails** do GLPI configurado, para as respostas de e-mail voltarem ao item.

## Onde ficam os dados

| O quê | Onde |
|---|---|
| Configurações | `glpi_plugin_centraldecontatos_configs` |
| Contatos registrados (aba Contatos) | `glpi_plugin_centraldecontatos_registros` |
| Conversas e mensagens | `glpi_plugin_centraldecontatos_conversas`, `glpi_plugin_centraldecontatos_mensagens` |
| Pedidos de validação pelo chat | `glpi_plugin_centraldecontatos_validacoes` |
| Node.js, servidor, sessão do aparelho e mídias | `files/_plugins/centraldecontatos/` (`node/`, `app/`, `auth/`, `run/`, `midia/`) |

## Estrutura do plugin

```
centraldecontatos/
├── setup.php                 inicialização, hooks e requisitos
├── hook.php                  instalação, atualização e desinstalação
├── front/                    páginas, configuração, AJAX, webhook do servidor, mídias
├── inc/                      classes (configuração, contatos, e-mail, WhatsApp, conversas, mensagens, validação)
├── public/css/ e public/js/  visual e telas (bloco de contatos, chat, servidor)
└── servidor/                 servidor WhatsApp (Node.js + Baileys)
```

## Versões

O histórico completo, com o que mudou em cada versão e o arquivo para download, está em **[Releases](../../releases)**. Cada versão entrou por um **[pull request](../../pulls?q=is%3Apr)**.

## Aviso

O servidor WhatsApp usa a biblioteca não oficial Baileys, que se conecta como um aparelho do WhatsApp Web. Use um número dedicado ao atendimento e siga os termos de uso do WhatsApp; envio em massa ou spam pode levar ao bloqueio do número.

## Licença

Distribuído sob a **GNU General Public License v2.0 ou posterior**. Veja o arquivo [LICENSE](LICENSE).
