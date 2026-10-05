/* Plugin Central de Contatos - chat de WhatsApp no GLPI: janela no item, caixa de conversas, atualização em
 * tempo real (consulta periódica), anexos em partes, gravação de áudio (quando o navegador permite),
 * respostas citando, reações, status de entrega e aviso de mensagem nova em qualquer página. */
(function () {
    'use strict';

    if (window.CentraldecontatosChat) {
        return;
    }

    var raizGlpi = (window.CFG_GLPI && window.CFG_GLPI.root_doc) ? window.CFG_GLPI.root_doc : '';
    var AJAX = raizGlpi + '/plugins/centraldecontatos/front/ajax.php';
    var PAGINA_CONVERSAS = raizGlpi + '/plugins/centraldecontatos/front/conversas.php';
    var EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];
    var PARTE = 1536 * 1024;
    var P = 'centraldecontatos-wa-';

    // ------------------------------------------------------------------ utilitários

    var esc = function (t) {
        return String(t === null || t === undefined ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    };

    /** Texto do WhatsApp: quebras de linha, links, *negrito*, _itálico_, ~riscado~ */
    var formatar = function (t) {
        var h = esc(t);
        h = h.replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
        h = h.replace(/(^|[\s(])\*([^*\n]+)\*(?=[\s).,!?:;]|$)/g, '$1<strong>$2</strong>');
        h = h.replace(/(^|[\s(])_([^_\n]+)_(?=[\s).,!?:;]|$)/g, '$1<em>$2</em>');
        h = h.replace(/(^|[\s(])~([^~\n]+)~(?=[\s).,!?:;]|$)/g, '$1<s>$2</s>');
        return h.replace(/\n/g, '<br>');
    };

    var lerJson = function (texto) {
        try {
            return JSON.parse(texto);
        } catch (e) {
            var m = String(texto).match(/\{[\s\S]*\}\s*$/);
            if (m) {
                try { return JSON.parse(m[0]); } catch (e2) { /* segue */ }
            }
        }
        return { success: false, mensagem: 'Resposta inválida do servidor.' };
    };

    var aviso = function (mensagem, erro) {
        var fn = erro ? window.glpi_toast_error : window.glpi_toast_info;
        if (typeof fn === 'function') {
            fn(mensagem);
        }
    };

    var postar = function (acao, campos, arquivo) {
        var fd = new FormData();
        fd.append('action', acao);
        Object.keys(campos || {}).forEach(function (k) {
            if (campos[k] !== undefined && campos[k] !== null) {
                fd.append(k, campos[k]);
            }
        });
        if (arquivo) {
            fd.append('arquivo', arquivo, 'parte');
        }
        var cab = { 'X-Requested-With': 'XMLHttpRequest' };
        var meta = document.querySelector('meta[property="glpi:csrf_token"]');
        var t = meta ? meta.getAttribute('content') : '';
        if (t) {
            fd.append('_glpi_csrf_token', t);
            cab['X-Glpi-Csrf-Token'] = t;
        }
        return fetch(AJAX, { method: 'POST', body: fd, credentials: 'same-origin', headers: cab })
            .then(function (r) { return r.text(); })
            .then(function (texto) {
                var r = lerJson(texto);
                if (r.new_token && meta) {
                    meta.setAttribute('content', r.new_token);
                }
                return r;
            });
    };

    var tamanhoLegivel = function (b) {
        b = Number(b || 0);
        if (b >= 1048576) {
            return (b / 1048576).toFixed(1).replace('.', ',') + ' MB';
        }
        return Math.max(1, Math.round(b / 1024)) + ' KB';
    };

    var diaLegivel = function (iso) {
        if (!iso) {
            return '';
        }
        var p = iso.split('-');
        var hoje = new Date();
        var d = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
        var ontem = new Date(hoje.getFullYear(), hoje.getMonth(), hoje.getDate() - 1);
        if (d.toDateString() === hoje.toDateString()) {
            return 'Hoje';
        }
        if (d.toDateString() === ontem.toDateString()) {
            return 'Ontem';
        }
        return p[2] + '/' + p[1] + '/' + p[0];
    };

    /** Iniciais do nome (HTML); contato só com número ganha o ícone de pessoa */
    var iniciais = function (nome) {
        var partes = String(nome || '').replace(/[^\p{L} ]/gu, ' ').trim().split(/\s+/).filter(Boolean);
        if (!partes.length) {
            return '<i class="ti ti-user"></i>';
        }
        return esc((partes[0].charAt(0) + (partes.length > 1 ? partes[partes.length - 1].charAt(0) : '')).toUpperCase());
    };

    var ICONE_STATUS = {
        pendente: ['ti ti-clock', 'Enviando'],
        enviada: ['ti ti-check', 'Enviada'],
        entregue: ['ti ti-checks', 'Entregue'],
        lida: ['ti ti-checks ' + P + 'lida', 'Lida'],
        erro: ['ti ti-alert-circle ' + P + 'erro', 'Não enviada']
    };

    // ------------------------------------------------------------------ chat

    /**
     * Monta o chat dentro de "caixa". opcoes: conversas_id ou telefone (+ nome), itemtype, items_id,
     * rascunho (texto inicial), modoCaixa (mostra vincular e arquivar), aoMudar, aoAbrir.
     */
    var montar = function (caixa, opcoes) {
        var estado = { conversa: null, ultimoId: 0, primeiroId: 0, citar: 0, timer: null, ativo: true, ocupado: false, gravador: null, relogio: null, limiteMb: 16, ultimoDia: '' };
        caixa.innerHTML = '<div class="' + P + 'chat"><div class="' + P + 'carregando"><i class="ti ti-loader-2"></i> Abrindo conversa...</div></div>';
        var chat = caixa.querySelector('.' + P + 'chat');

        var contexto = function () {
            return opcoes.itemtype ? { itemtype: opcoes.itemtype, items_id: opcoes.items_id } : {};
        };

        var podeGravar = !!(window.isSecureContext && navigator.mediaDevices && window.MediaRecorder);

        var desenharEsqueleto = function () {
            chat.innerHTML =
                '<div class="' + P + 'topo">' +
                '<span class="' + P + 'avatar" data-avatar></span>' +
                '<div class="' + P + 'quem"><strong data-nome></strong><small data-numero></small></div>' +
                '<span class="' + P + 'servidor" data-servidor></span>' +
                '<div class="' + P + 'topo-acoes">' +
                '<button type="button" class="btn btn-sm btn-ghost-secondary" data-validar title="Enviar pedido de validação" hidden><i class="ti ti-checkup-list"></i></button>' +
                '<button type="button" class="btn btn-sm btn-ghost-secondary" data-salvar title="Salvar a conversa no acompanhamento do item"><i class="ti ti-device-floppy"></i></button>' +
                '<button type="button" class="btn btn-sm btn-ghost-secondary" data-limpar title="Limpar conversa (antes, salva no acompanhamento do item)"><i class="ti ti-eraser"></i></button>' +
                (opcoes.modoCaixa ? '<button type="button" class="btn btn-sm btn-ghost-secondary" data-vincular-abrir title="Vincular a um chamado, problema ou mudança"><i class="ti ti-link"></i></button>' +
                    '<button type="button" class="btn btn-sm btn-ghost-secondary" data-arquivar title="Arquivar"><i class="ti ti-archive"></i></button>' : '') +
                '</div></div>' +
                '<div class="' + P + 'item" data-item hidden></div>' +
                '<div class="' + P + 'validacoes" data-validacoes hidden></div>' +
                '<form class="' + P + 'vincular" data-vincular hidden><select class="form-select form-select-sm" data-vincular-tipo><option value="Ticket">Chamado</option><option value="Problem">Problema</option><option value="Change">Mudança</option></select>' +
                '<input type="number" min="1" class="form-control form-control-sm" placeholder="Número" data-vincular-id>' +
                '<button type="submit" class="btn btn-sm centraldecontatos-btn-principal"><i class="ti ti-link"></i><span>Vincular</span></button>' +
                '<button type="button" class="btn btn-sm btn-ghost-secondary" data-desvincular title="Remover vínculo"><i class="ti ti-unlink"></i></button></form>' +
                '<div class="' + P + 'alerta" data-alerta hidden><i class="ti ti-plug-connected-x"></i><span>O servidor WhatsApp está desconectado: as mensagens não saem até ele voltar.</span></div>' +
                '<div class="' + P + 'mensagens" data-mensagens><button type="button" class="btn btn-sm btn-ghost-secondary ' + P + 'antigas" data-antigas hidden><i class="ti ti-history"></i><span>Mensagens anteriores</span></button><div data-lista></div></div>' +
                '<div class="' + P + 'citando" data-citando hidden><span class="' + P + 'citando-corpo" data-citando-corpo></span><button type="button" class="btn-close" data-citando-limpar aria-label="Cancelar resposta"></button></div>' +
                '<div class="' + P + 'anexo" data-anexo hidden><i class="ti ti-loader-2 ' + P + 'girando"></i><span data-anexo-nome></span><span class="' + P + 'progresso"><span data-anexo-barra></span></span></div>' +
                '<div class="' + P + 'gravacao" data-gravacao hidden><span class="' + P + 'gravando-ponto"></span><span data-gravacao-tempo>0:00</span><span class="text-muted">Gravando áudio</span>' +
                '<button type="button" class="btn btn-sm btn-ghost-secondary" data-gravacao-cancelar title="Descartar"><i class="ti ti-trash"></i></button>' +
                '<button type="button" class="btn btn-sm centraldecontatos-btn-principal" data-gravacao-enviar title="Parar e enviar"><i class="ti ti-send"></i></button></div>' +
                '<div class="' + P + 'compor" data-compor>' +
                '<input type="file" data-arquivo multiple hidden>' +
                '<input type="file" data-arquivo-audio accept="audio/*" capture hidden>' +
                '<button type="button" class="btn btn-sm btn-ghost-secondary" data-anexar title="Anexar e enviar imagens, documentos, áudios ou vídeos (pode escolher vários, colar ou arrastar)"><i class="ti ti-paperclip"></i></button>' +
                '<button type="button" class="btn btn-sm btn-ghost-secondary" data-gravar title="' + (podeGravar ? 'Gravar e enviar áudio' : 'Enviar áudio (para gravar aqui, o GLPI precisa estar em HTTPS)') + '"><i class="ti ti-microphone"></i></button>' +
                '<textarea class="form-control form-control-sm" rows="1" data-texto placeholder="Mensagem (Enter envia, Shift+Enter quebra a linha)"></textarea>' +
                '<button type="button" class="btn btn-sm centraldecontatos-btn-principal" data-enviar title="Enviar"><i class="ti ti-send"></i></button>' +
                '</div>';
            if (opcoes.rascunho) {
                chat.querySelector('[data-texto]').value = opcoes.rascunho;
                ajustarAltura();
            }
        };

        var q = function (sel) { return chat.querySelector(sel); };

        var ajustarAltura = function () {
            var t = q('[data-texto]');
            if (t) {
                t.style.height = 'auto';
                t.style.height = Math.min(140, t.scrollHeight + 2) + 'px';
            }
        };

        var desenharTopo = function (servidor) {
            var c = estado.conversa;
            q('[data-nome]').textContent = c.nome;
            var sub = [c.nome !== c.exibicao ? c.exibicao : '', c.nome_whatsapp && c.nome_whatsapp !== c.nome ? c.nome_whatsapp : ''].filter(Boolean).join(' · ');
            q('[data-numero]').textContent = sub;
            q('[data-numero]').hidden = !sub;
            q('[data-avatar]').innerHTML = iniciais(c.nome);
            var item = q('[data-item]');
            if (c.item) {
                item.hidden = false;
                item.innerHTML = '<i class="ti ti-link"></i> ' + (c.item_url ? '<a href="' + esc(c.item_url) + '">' + esc(c.item) + '</a>' : esc(c.item));
            } else {
                item.hidden = true;
            }
            if (servidor) {
                var s = q('[data-servidor]');
                s.className = P + 'servidor ' + (servidor.conectado ? P + 'on' : P + 'off');
                s.title = servidor.conectado ? 'Servidor WhatsApp conectado' : 'Servidor WhatsApp desconectado';
                q('[data-alerta]').hidden = !!servidor.conectado;
            }
            var salvar = q('[data-salvar]');
            salvar.dataset.semItem = c.item ? '' : '1';
            salvar.title = c.item ? 'Salvar a conversa inteira, com os anexos, no acompanhamento de ' + c.item : 'Vincule a conversa a um chamado, problema ou mudança para salvar';
            if (q('[data-limpar]').dataset.armado !== '1') {
                q('[data-limpar]').title = c.item ? 'Limpar conversa: antes, salva a conversa inteira no acompanhamento de ' + c.item : 'Limpar conversa (sem item vinculado: nada é salvo)';
            }
            q('[data-validar]').hidden = !c.pode_validar;
            if (!c.pode_validar) {
                q('[data-validacoes]').hidden = true;
            }
            var arq = q('[data-arquivar]');
            if (arq) {
                arq.title = c.arquivada ? 'Reabrir conversa' : 'Arquivar';
                arq.innerHTML = '<i class="ti ' + (c.arquivada ? 'ti-archive-off' : 'ti-archive') + '"></i>';
            }
            travar(estado.ocupado);
        };

        /** Enquanto envia arquivos, salva ou limpa: nenhuma outra ação no chat */
        var travar = function (sim) {
            estado.ocupado = !!sim;
            chat.classList.toggle(P + 'ocupado', estado.ocupado);
            chat.querySelectorAll('[data-compor] button, [data-compor] textarea, .' + P + 'topo-acoes button').forEach(function (b) {
                b.disabled = estado.ocupado || (b.hasAttribute('data-salvar') && b.dataset.semItem === '1');
            });
        };

        /** Depois de salvar ou limpar: a página do próprio item recarrega para mostrar o acompanhamento novo */
        var recarregarSeNoItem = function (c) {
            if (!c || !c.item_url) {
                return false;
            }
            var alvo = new URL(c.item_url, window.location.href);
            var aqui = new URL(window.location.href);
            if (alvo.pathname === aqui.pathname && alvo.searchParams.get('id') === aqui.searchParams.get('id')) {
                window.location.reload();
                return true;
            }
            return false;
        };

        var ICONE_TIPO = { imagem: 'ti-photo', figurinha: 'ti-sticker', audio: 'ti-microphone', video: 'ti-video', documento: 'ti-file-text' };

        /** Mensagem citada: autor, texto e miniatura da mídia */
        var htmlCitada = function (c) {
            if (!c) {
                return '';
            }
            var mini = '';
            if (c.url && (c.tipo === 'imagem' || c.tipo === 'figurinha')) {
                mini = '<img src="' + esc(c.url) + '" alt="" loading="lazy">';
            } else if (c.url && c.tipo === 'video') {
                mini = '<video src="' + esc(c.url) + '#t=0.5" preload="metadata" muted></video>';
            } else if (ICONE_TIPO[c.tipo] && c.tipo !== 'imagem' && c.tipo !== 'video') {
                mini = '<i class="ti ' + ICONE_TIPO[c.tipo] + '"></i>';
            }
            return '<div class="' + P + 'citada' + (c.autor === 'Você' ? ' ' + P + 'citada-minha' : '') + '"' + (c.id ? ' data-ir="' + c.id + '" title="Ir para a mensagem"' : '') + '>' +
                '<span class="' + P + 'citada-texto">' + (c.autor ? '<strong>' + esc(c.autor) + '</strong>' : '') + '<span>' + esc(c.texto || 'Mídia') + '</span></span>' +
                (mini ? '<span class="' + P + 'citada-mini">' + mini + '</span>' : '') + '</div>';
        };

        var htmlMidia = function (m) {
            if (!m.midia) {
                return '';
            }
            if (!m.midia.existe) {
                return '<div class="' + P + 'arquivo-ausente"><i class="ti ti-file-off"></i> Arquivo indisponível</div>';
            }
            var u = esc(m.midia.url);
            switch (m.tipo) {
                case 'imagem':
                case 'figurinha':
                    return '<a href="' + u + '" target="_blank" rel="noopener" class="' + P + 'imagem' + (m.tipo === 'figurinha' ? ' ' + P + 'figurinha' : '') + '"><img src="' + u + '" alt="Imagem" loading="lazy"></a>';
                case 'audio':
                    return '<audio controls preload="none" src="' + u + '"></audio>';
                case 'video':
                    return '<video controls preload="metadata" src="' + u + '"></video>';
                default:
                    return '<a href="' + u + '" class="' + P + 'documento"><i class="ti ti-file-text"></i><span><strong>' + esc(m.midia.nome || 'Documento') + '</strong><small>' + esc(tamanhoLegivel(m.midia.tamanho)) + '</small></span><i class="ti ti-download"></i></a>';
            }
        };

        var htmlStatus = function (m) {
            if (m.direcao !== 'saida') {
                return '';
            }
            var s = ICONE_STATUS[m.status] || ICONE_STATUS.enviada;
            return '<i class="' + s[0] + ' ' + P + 'tick" data-tick title="' + esc(m.status === 'erro' && m.erro ? 'Não enviada: ' + m.erro : s[1]) + '"></i>';
        };

        var htmlReacoes = function (m) {
            var r = [m.reacao_cliente, m.reacao_nossa].filter(Boolean);
            return '<span class="' + P + 'reacoes" data-reacoes' + (r.length ? '' : ' hidden') + '>' + r.map(esc).join(' ') + '</span>';
        };

        var htmlMensagem = function (m) {
            var autor = m.direcao === 'saida' ? (m.origem === 'celular' ? 'Celular' : (m.autor || '')) : '';
            return '<div class="' + P + 'msg ' + P + m.direcao + '" data-id="' + m.id + '" data-tipo="' + esc(m.tipo) + '" data-autor="' + esc(m.direcao === 'saida' ? 'Você' : (estado.conversa ? estado.conversa.nome : '')) + '">' +
                '<div class="' + P + 'balao">' +
                htmlCitada(m.citada) +
                htmlMidia(m) +
                (m.texto ? '<div class="' + P + 'texto">' + formatar(m.texto) + '</div>' : '') +
                '<div class="' + P + 'meta">' +
                (autor ? '<span class="' + P + 'autor">' + esc(autor) + '</span>' : '') +
                (m.acompanhamento ? '<i class="ti ti-message ' + P + 'acomp" title="Registrada como acompanhamento' + (m.item ? ' em ' + esc(m.item) : '') + '"></i>' : '') +
                '<span title="' + esc(m.quando) + '">' + esc(m.hora) + '</span>' + htmlStatus(m) + '</div>' +
                htmlReacoes(m) +
                '</div>' +
                '<div class="' + P + 'msg-acoes">' +
                (m.reage ? '<button type="button" data-responder title="Responder"><i class="ti ti-corner-up-left"></i></button><button type="button" data-reagir title="Reagir"><i class="ti ti-mood-smile"></i></button>' : '') +
                '</div></div>';
        };

        var lista = function () { return q('[data-lista]'); };
        var areaMensagens = function () { return q('[data-mensagens]'); };

        var noFim = function () {
            var a = areaMensagens();
            return a.scrollHeight - a.scrollTop - a.clientHeight < 60;
        };

        var rolarFim = function () {
            var a = areaMensagens();
            a.scrollTop = a.scrollHeight;
        };

        var acrescentar = function (mensagens, noInicio) {
            if (!mensagens.length) {
                return;
            }
            var html = '';
            var dia = noInicio ? '' : estado.ultimoDia;
            mensagens.forEach(function (m) {
                if (lista().querySelector('[data-id="' + m.id + '"]')) {
                    return;
                }
                if (m.dia !== dia) {
                    html += '<div class="' + P + 'dia"><span>' + esc(diaLegivel(m.dia)) + '</span></div>';
                    dia = m.dia;
                }
                html += htmlMensagem(m);
            });
            if (noInicio) {
                var a = areaMensagens();
                var antes = a.scrollHeight;
                lista().insertAdjacentHTML('afterbegin', html);
                a.scrollTop += a.scrollHeight - antes;
                estado.primeiroId = mensagens[0].id;
            } else {
                var colado = noFim();
                lista().insertAdjacentHTML('beforeend', html);
                estado.ultimoId = Math.max(estado.ultimoId, mensagens[mensagens.length - 1].id);
                estado.ultimoDia = dia;
                if (!estado.primeiroId) {
                    estado.primeiroId = mensagens[0].id;
                }
                if (colado) {
                    rolarFim();
                }
            }
        };

        var aplicarSituacao = function (situacao) {
            Object.keys(situacao || {}).forEach(function (id) {
                var el = lista().querySelector('[data-id="' + id + '"]');
                if (!el) {
                    return;
                }
                var s = situacao[id];
                var tick = el.querySelector('[data-tick]');
                if (tick) {
                    var info = ICONE_STATUS[s[0]] || ICONE_STATUS.enviada;
                    tick.className = info[0] + ' ' + P + 'tick';
                    tick.title = s[0] === 'erro' && s[1] ? 'Não enviada: ' + s[1] : info[1];
                }
                var r = el.querySelector('[data-reacoes]');
                var emojis = [s[2], s[3]].filter(Boolean);
                if (r) {
                    r.hidden = !emojis.length;
                    r.textContent = emojis.join(' ');
                }
            });
        };

        var visivel = function () {
            return document.visibilityState === 'visible' && chat.offsetParent !== null;
        };

        var consultar = function () {
            if (!estado.ativo || !estado.conversa) {
                return Promise.resolve();
            }
            return postar('mensagens', { conversas_id: estado.conversa.id, depois_de: estado.ultimoId, visivel: visivel() ? 1 : 0 }).then(function (r) {
                if (!estado.ativo || !r.success) {
                    return;
                }
                acrescentar(r.mensagens || [], false);
                aplicarSituacao(r.situacao);
                if (r.conversa) {
                    estado.conversa = r.conversa;
                    desenharTopo(r.servidor);
                }
            });
        };

        var agendar = function () {
            clearTimeout(estado.timer);
            if (!estado.ativo) {
                return;
            }
            estado.timer = setTimeout(function () {
                (document.visibilityState === 'visible' ? consultar() : Promise.resolve()).finally(agendar);
            }, 2500);
        };

        // ---------------------------------------------------------------- envio (texto e mídias)

        var limparCitacao = function () {
            estado.citar = 0;
            q('[data-citando]').hidden = true;
        };

        /** Sobe um arquivo em partes; devolve o upload_id */
        var subirArquivo = function (arquivo, nome, rotulo) {
            var total = Math.max(1, Math.ceil(arquivo.size / PARTE));
            var id = Array.from(window.crypto.getRandomValues(new Uint8Array(12))).map(function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
            q('[data-anexo]').hidden = false;
            q('[data-anexo-nome]').textContent = rotulo + nome + ' (' + tamanhoLegivel(arquivo.size) + ')';
            q('[data-anexo-barra]').style.width = '0%';
            var parte = 0;
            var proxima = function () {
                var pedaco = arquivo.slice(parte * PARTE, Math.min(arquivo.size, (parte + 1) * PARTE));
                return postar('upload', { upload_id: id, parte: parte, total: total, nome: nome }, pedaco).then(function (r) {
                    if (!r.success) {
                        throw new Error(r.mensagem || 'Falha no envio do arquivo.');
                    }
                    parte++;
                    q('[data-anexo-barra]').style.width = Math.round(parte * 100 / total) + '%';
                    return parte < total ? proxima() : id;
                });
            };
            return proxima();
        };

        var postarMensagem = function (texto, uploadId) {
            return postar('enviar', Object.assign({ conversas_id: estado.conversa.id, texto: texto, citar: estado.citar || '', upload_id: uploadId || '' }, contexto())).then(function (r) {
                if (!r.success) {
                    throw new Error(r.mensagem || 'Mensagem não enviada.');
                }
                return r;
            });
        };

        /**
         * Envia as mídias assim que são escolhidas, uma de cada vez: sobe, envia e passa para a próxima.
         * O texto digitado vai como legenda da primeira e a citação vale para a primeira.
         */
        var enviarArquivos = function (arquivos) {
            arquivos = Array.from(arquivos || []).filter(function (a) { return a && a.size > 0; });
            if (!arquivos.length || !estado.conversa || estado.ocupado) {
                return Promise.resolve();
            }
            var limite = (estado.limiteMb || 16) * 1048576;
            var grandes = arquivos.filter(function (a) { return a.size > limite; });
            if (grandes.length) {
                aviso('Acima do limite de ' + (estado.limiteMb || 16) + ' MB: ' + grandes.map(function (a) { return a.name; }).join(', '), true);
                arquivos = arquivos.filter(function (a) { return a.size <= limite; });
                if (!arquivos.length) {
                    return Promise.resolve();
                }
            }
            var campo = q('[data-texto]');
            var legenda = campo.value.trim();
            travar(true);
            var falhas = [];
            var i = 0;
            var proximo = function () {
                if (i >= arquivos.length) {
                    return Promise.resolve();
                }
                var a = arquivos[i];
                var nome = a.name || ('arquivo-' + Date.now());
                var rotulo = arquivos.length > 1 ? (i + 1) + ' de ' + arquivos.length + ': ' : '';
                var texto = i === 0 ? legenda : '';
                i++;
                return subirArquivo(a, nome, rotulo)
                    .then(function (id) { return postarMensagem(texto, id); })
                    .then(function () {
                        if (texto) {
                            campo.value = '';
                            ajustarAltura();
                        }
                        limparCitacao();
                        return consultar().then(rolarFim);
                    })
                    .catch(function (e) { falhas.push(nome + ': ' + e.message); })
                    .then(proximo);
            };
            return proximo().finally(function () {
                q('[data-anexo]').hidden = true;
                q('[data-arquivo]').value = '';
                q('[data-arquivo-audio]').value = '';
                travar(false);
                if (falhas.length) {
                    aviso(falhas.join(' · '), true);
                }
                campo.focus();
            });
        };

        var enviar = function () {
            if (estado.ocupado || !estado.conversa) {
                return;
            }
            var campo = q('[data-texto]');
            var texto = campo.value.trim();
            if (!texto) {
                return;
            }
            travar(true);
            postarMensagem(texto, '').then(function () {
                campo.value = '';
                ajustarAltura();
                limparCitacao();
                return consultar().then(rolarFim);
            }).catch(function (e) {
                aviso(e.message || 'Falha de comunicação.', true);
            }).finally(function () {
                travar(false);
                campo.focus();
            });
        };

        // ---------------------------------------------------------------- gravação de áudio

        var pararRelogio = function () {
            clearInterval(estado.relogio);
            q('[data-gravacao]').hidden = true;
            q('[data-compor]').hidden = false;
        };

        var gravar = function () {
            if (!podeGravar) {
                // Fora de HTTPS o navegador não libera o microfone: escolhe (ou grava pelo celular) um arquivo de áudio
                aviso('Para gravar aqui, o GLPI precisa estar em HTTPS. Escolha um arquivo de áudio para enviar.', false);
                q('[data-arquivo-audio]').click();
                return;
            }
            navigator.mediaDevices.getUserMedia({ audio: true }).then(function (fluxo) {
                var tipo = window.MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : '';
                var rec = new window.MediaRecorder(fluxo, tipo ? { mimeType: tipo } : undefined);
                var partes = [];
                rec.ondataavailable = function (e) { if (e.data.size) { partes.push(e.data); } };
                rec.onstop = function () {
                    fluxo.getTracks().forEach(function (t) { t.stop(); });
                    var descartar = rec.descartar;
                    estado.gravador = null;
                    pararRelogio();
                    var blob = new Blob(partes, { type: 'audio/webm' });
                    if (!descartar && blob.size > 0) {
                        enviarArquivos([new File([blob], 'gravacao-' + Date.now() + '.webm', { type: 'audio/webm' })]);
                    }
                };
                rec.start();
                estado.gravador = rec;
                var inicio = Date.now();
                q('[data-compor]').hidden = true;
                q('[data-gravacao]').hidden = false;
                q('[data-gravacao-tempo]').textContent = '0:00';
                estado.relogio = setInterval(function () {
                    var s = Math.floor((Date.now() - inicio) / 1000);
                    q('[data-gravacao-tempo]').textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
                }, 500);
            }).catch(function () {
                aviso('Não foi possível usar o microfone. Verifique a permissão do navegador.', true);
            });
        };

        var terminarGravacao = function (descartar) {
            if (estado.gravador) {
                estado.gravador.descartar = !!descartar;
                estado.gravador.stop();
            }
        };

        // ---------------------------------------------------------------- pedido de validação

        var mostrarValidacoes = function () {
            var box = q('[data-validacoes]');
            if (!box.hidden) {
                box.hidden = true;
                return;
            }
            box.hidden = false;
            box.innerHTML = '<div class="' + P + 'carregando"><i class="ti ti-loader-2"></i> Buscando validações...</div>';
            postar('validacoes', { conversas_id: estado.conversa.id }).then(function (r) {
                if (!r.success) {
                    box.innerHTML = '<div class="' + P + 'validacoes-vazio">' + esc(r.mensagem) + '</div>';
                    return;
                }
                var v = r.validacoes || [];
                box.innerHTML = '<div class="' + P + 'validacoes-titulo"><i class="ti ti-checkup-list"></i> Validações aguardando resposta em ' + esc(r.item) + '<button type="button" class="btn-close" data-validacoes-fechar aria-label="Fechar"></button></div>' +
                    (!v.length ? '<div class="' + P + 'validacoes-vazio">Nenhuma validação aguardando resposta. Crie a validação no item e ela aparece aqui.</div>' :
                        v.map(function (x) {
                            return '<div class="' + P + 'validacao">' +
                                '<div><strong>Aprovador: ' + esc(x.aprovadores || '—') + '</strong>' +
                                '<small>Pedida por ' + esc(x.solicitante) + ' em ' + esc(x.quando) + (x.enviado ? ' · enviada por aqui em ' + esc(x.enviado) : '') + '</small>' +
                                (x.comentario ? '<small>' + esc(x.comentario) + '</small>' : '') +
                                (!x.confere ? '<small class="' + P + 'validacao-aviso"><i class="ti ti-alert-triangle"></i> Este número não é o celular ou telefone cadastrado de um aprovador.</small>' : '') +
                                '</div>' +
                                '<button type="button" class="btn btn-sm centraldecontatos-btn-principal" data-validacao-enviar="' + x.id + '"' + (x.confere ? '' : ' disabled') + '><i class="ti ti-send"></i><span>' + (x.enviado ? 'Enviar de novo' : 'Enviar') + '</span></button></div>';
                        }).join(''));
            });
        };

        // ---------------------------------------------------------------- citação

        var citar = function (msg) {
            estado.citar = parseInt(msg.dataset.id, 10);
            var balao = msg.querySelector('.' + P + 'texto');
            var tipo = msg.dataset.tipo || 'texto';
            var mini = '';
            var img = msg.querySelector('.' + P + 'imagem img');
            var vid = msg.querySelector('video');
            if (img) {
                mini = '<img src="' + esc(img.getAttribute('src')) + '" alt="">';
            } else if (vid) {
                mini = '<video src="' + esc(vid.getAttribute('src')) + '#t=0.5" preload="metadata" muted></video>';
            } else if (ICONE_TIPO[tipo]) {
                mini = '<i class="ti ' + ICONE_TIPO[tipo] + '"></i>';
            }
            var doc = msg.querySelector('.' + P + 'documento strong');
            var texto = balao ? balao.textContent.slice(0, 160) : (doc ? doc.textContent : ({ imagem: 'Imagem', figurinha: 'Figurinha', audio: 'Áudio', video: 'Vídeo' }[tipo] || 'Mídia'));
            q('[data-citando-corpo]').innerHTML = '<i class="ti ti-corner-up-left"></i><span class="' + P + 'citada-texto"><strong>' + esc(msg.dataset.autor || '') + '</strong><span>' + esc(texto) + '</span></span>' +
                (mini ? '<span class="' + P + 'citada-mini">' + mini + '</span>' : '');
            q('[data-citando]').hidden = false;
            q('[data-texto]').focus();
        };

        // ---------------------------------------------------------------- eventos

        var ligarEventos = function () {
            chat.addEventListener('click', function (e) {
                var el;
                if (estado.ocupado && !e.target.closest('a, audio, video')) {
                    return;
                }
                if ((el = e.target.closest('[data-enviar]'))) {
                    enviar();
                } else if (e.target.closest('[data-anexar]')) {
                    q('[data-arquivo]').click();
                } else if (e.target.closest('[data-gravar]')) {
                    gravar();
                } else if (e.target.closest('[data-gravacao-enviar]')) {
                    terminarGravacao(false);
                } else if (e.target.closest('[data-gravacao-cancelar]')) {
                    terminarGravacao(true);
                } else if (e.target.closest('[data-citando-limpar]')) {
                    limparCitacao();
                } else if ((el = e.target.closest('[data-ir]'))) {
                    var original = lista().querySelector('[data-id="' + el.dataset.ir + '"]');
                    if (original) {
                        original.scrollIntoView({ block: 'center', behavior: 'smooth' });
                        original.classList.add(P + 'destaque');
                        setTimeout(function () { original.classList.remove(P + 'destaque'); }, 1600);
                    }
                } else if (e.target.closest('[data-antigas]')) {
                    postar('antigas', { conversas_id: estado.conversa.id, antes_de: estado.primeiroId }).then(function (r) {
                        var msgs = r.mensagens || [];
                        acrescentar(msgs, true);
                        q('[data-antigas]').hidden = msgs.length < 60;
                    });
                } else if ((el = e.target.closest('[data-responder]'))) {
                    citar(el.closest('[data-id]'));
                } else if ((el = e.target.closest('[data-reagir]'))) {
                    var alvo = el.closest('[data-id]');
                    var aberto = alvo.querySelector('.' + P + 'emojis');
                    chat.querySelectorAll('.' + P + 'emojis').forEach(function (x) { x.remove(); });
                    if (!aberto) {
                        alvo.querySelector('.' + P + 'balao').insertAdjacentHTML('beforeend', '<div class="' + P + 'emojis">' + EMOJIS.map(function (em) { return '<button type="button" data-emoji="' + em + '">' + em + '</button>'; }).join('') + '<button type="button" data-emoji="" title="Remover reação"><i class="ti ti-x"></i></button></div>');
                    }
                } else if ((el = e.target.closest('[data-emoji]'))) {
                    var m = el.closest('[data-id]');
                    el.closest('.' + P + 'emojis').remove();
                    postar('reagir', { mensagens_id: m.dataset.id, emoji: el.dataset.emoji }).then(function (r) {
                        if (!r.success) {
                            aviso(r.mensagem, true);
                        }
                        consultar();
                    });
                } else if (e.target.closest('[data-validar]')) {
                    mostrarValidacoes();
                } else if (e.target.closest('[data-validacoes-fechar]')) {
                    q('[data-validacoes]').hidden = true;
                } else if ((el = e.target.closest('[data-validacao-enviar]'))) {
                    el.disabled = true;
                    el.innerHTML = '<i class="ti ti-loader-2 ' + P + 'girando"></i><span>Enviando</span>';
                    postar('pedir_validacao', { conversas_id: estado.conversa.id, validacao_id: el.dataset.validacaoEnviar }).then(function (r) {
                        aviso(r.mensagem, !r.success);
                        q('[data-validacoes]').hidden = true;
                        if (r.success) {
                            consultar().then(rolarFim);
                        }
                    });
                } else if ((el = e.target.closest('[data-salvar]'))) {
                    travar(true);
                    el.innerHTML = '<i class="ti ti-loader-2 ' + P + 'girando"></i>';
                    postar('salvar_acompanhamento', { conversas_id: estado.conversa.id }).then(function (r) {
                        aviso(r.mensagem, !r.success);
                        if (r.conversa) {
                            estado.conversa = r.conversa;
                        }
                        if (r.success && r.followup && recarregarSeNoItem(estado.conversa)) {
                            return;
                        }
                        el.innerHTML = '<i class="ti ti-device-floppy"></i>';
                        travar(false);
                        desenharTopo();
                    });
                } else if ((el = e.target.closest('[data-limpar]'))) {
                    // Confirmação dentro do próprio botão
                    if (el.dataset.armado !== '1') {
                        el.dataset.armado = '1';
                        el.classList.add(P + 'confirmar');
                        el.innerHTML = '<i class="ti ti-alert-triangle"></i><span>Limpar?</span>';
                        el.title = estado.conversa.item ? 'Clique de novo: salva a conversa inteira no acompanhamento de ' + estado.conversa.item + ' e apaga as mensagens do chat' : 'Clique de novo: apaga as mensagens do chat (sem item vinculado, nada é salvo)';
                        setTimeout(function () {
                            if (el.dataset.armado === '1') {
                                el.dataset.armado = '';
                                el.classList.remove(P + 'confirmar');
                                el.innerHTML = '<i class="ti ti-eraser"></i>';
                            }
                        }, 4000);
                        return;
                    }
                    el.dataset.armado = '';
                    el.classList.remove(P + 'confirmar');
                    el.innerHTML = '<i class="ti ti-loader-2 ' + P + 'girando"></i>';
                    travar(true);
                    postar('limpar', { conversas_id: estado.conversa.id }).then(function (r) {
                        aviso(r.mensagem, !r.success);
                        if (r.success && r.conversa && r.conversa.item && recarregarSeNoItem(r.conversa)) {
                            return;
                        }
                        el.innerHTML = '<i class="ti ti-eraser"></i>';
                        travar(false);
                        if (!r.success) {
                            return;
                        }
                        lista().innerHTML = '';
                        estado.ultimoId = 0;
                        estado.primeiroId = 0;
                        estado.ultimoDia = '';
                        q('[data-antigas]').hidden = true;
                        if (r.conversa) {
                            estado.conversa = r.conversa;
                            desenharTopo();
                        }
                        if (opcoes.aoMudar) {
                            opcoes.aoMudar();
                        }
                    });
                } else if (e.target.closest('[data-arquivar]')) {
                    postar('arquivar', { conversas_id: estado.conversa.id, valor: estado.conversa.arquivada ? 0 : 1 }).then(function (r) {
                        aviso(r.mensagem, !r.success);
                        if (opcoes.aoMudar) {
                            opcoes.aoMudar();
                        }
                        consultar();
                    });
                } else if (e.target.closest('[data-vincular-abrir]')) {
                    var f = q('[data-vincular]');
                    f.hidden = !f.hidden;
                    if (!f.hidden && estado.conversa.itemtype) {
                        q('[data-vincular-tipo]').value = estado.conversa.itemtype;
                        q('[data-vincular-id]').value = estado.conversa.items_id || '';
                    }
                } else if (e.target.closest('[data-desvincular]')) {
                    postar('vincular', { conversas_id: estado.conversa.id, itemtype: '', items_id: 0 }).then(function (r) {
                        aviso(r.mensagem, !r.success);
                        if (r.conversa) {
                            estado.conversa = r.conversa;
                            desenharTopo();
                        }
                        q('[data-vincular]').hidden = true;
                    });
                }
            });
            q('[data-vincular]').addEventListener('submit', function (e) {
                e.preventDefault();
                postar('vincular', { conversas_id: estado.conversa.id, itemtype: q('[data-vincular-tipo]').value, items_id: q('[data-vincular-id]').value }).then(function (r) {
                    aviso(r.mensagem, !r.success);
                    if (r.success && r.conversa) {
                        estado.conversa = r.conversa;
                        desenharTopo();
                        q('[data-vincular]').hidden = true;
                        if (opcoes.aoMudar) {
                            opcoes.aoMudar();
                        }
                    }
                });
            });
            // Escolheu, enviou: cada arquivo sobe e vai para o contato na hora
            ['[data-arquivo]', '[data-arquivo-audio]'].forEach(function (sel) {
                q(sel).addEventListener('change', function () {
                    enviarArquivos(this.files);
                });
            });
            // Arrastar arquivos para dentro do chat
            chat.addEventListener('dragover', function (e) {
                if (e.dataTransfer && Array.from(e.dataTransfer.types || []).indexOf('Files') >= 0) {
                    e.preventDefault();
                    chat.classList.add(P + 'soltar');
                }
            });
            chat.addEventListener('dragleave', function (e) {
                if (!chat.contains(e.relatedTarget)) {
                    chat.classList.remove(P + 'soltar');
                }
            });
            chat.addEventListener('drop', function (e) {
                if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
                    e.preventDefault();
                    chat.classList.remove(P + 'soltar');
                    enviarArquivos(e.dataTransfer.files);
                }
            });
            var campo = q('[data-texto]');
            campo.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
                    e.preventDefault();
                    enviar();
                }
            });
            campo.addEventListener('input', ajustarAltura);
            // Colar imagens ou arquivos da área de transferência
            campo.addEventListener('paste', function (e) {
                var itens = (e.clipboardData && e.clipboardData.items) ? Array.from(e.clipboardData.items) : [];
                var arquivos = itens.filter(function (i) { return i.kind === 'file'; }).map(function (i, n) {
                    var f = i.getAsFile();
                    if (f && (!f.name || f.name === 'image.png') && f.type.indexOf('image/') === 0) {
                        f = new File([f], 'imagem-' + Date.now() + '-' + n + '.' + (f.type.split('/')[1] || 'png').replace('jpeg', 'jpg'), { type: f.type });
                    }
                    return f;
                }).filter(Boolean);
                if (arquivos.length) {
                    e.preventDefault();
                    enviarArquivos(arquivos);
                }
            });
        };

        // ---------------------------------------------------------------- abertura

        var pedido = Object.assign(opcoes.conversas_id ? { conversas_id: opcoes.conversas_id } : { telefone: opcoes.telefone || '', nome: opcoes.nome || '' }, contexto());
        postar('abrir', pedido).then(function (r) {
            if (!estado.ativo) {
                return;
            }
            if (!r.success) {
                chat.innerHTML = '<div class="' + P + 'carregando ' + P + 'falha"><i class="ti ti-alert-triangle"></i> ' + esc(r.mensagem) + '</div>';
                return;
            }
            estado.conversa = r.conversa;
            estado.limiteMb = r.limite_mb || 16;
            desenharEsqueleto();
            ligarEventos();
            desenharTopo(r.servidor);
            acrescentar(r.mensagens || [], false);
            q('[data-antigas]').hidden = (r.mensagens || []).length < 60;
            rolarFim();
            q('[data-texto]').focus();
            consultar();
            agendar();
            if (opcoes.aoAbrir) {
                opcoes.aoAbrir(r.conversa);
            }
        });

        return {
            fechar: function () {
                estado.ativo = false;
                clearTimeout(estado.timer);
                clearInterval(estado.relogio);
                if (estado.gravador) {
                    estado.gravador.descartar = true;
                    try { estado.gravador.stop(); } catch (e) { /* ok */ }
                }
            },
            conversaId: function () { return estado.conversa ? estado.conversa.id : 0; }
        };
    };

    // ------------------------------------------------------------------ janela no item

    var abrirModal = function (opcoes) {
        var m = document.createElement('div');
        m.className = 'modal fade centraldecontatos-modal ' + P + 'modal';
        m.tabIndex = -1;
        m.innerHTML = '<div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">' +
            '<div class="modal-header"><h5 class="modal-title"><i class="ti ti-brand-whatsapp"></i> WhatsApp</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>' +
            '<div class="modal-body p-0" data-corpo></div></div></div>';
        document.body.appendChild(m);
        var chat = null;
        var instancia = window.bootstrap.Modal.getOrCreateInstance(m);
        m.addEventListener('shown.bs.modal', function () {
            chat = montar(m.querySelector('[data-corpo]'), opcoes);
            window.CentraldecontatosChat.aberta = chat;
        });
        m.addEventListener('hidden.bs.modal', function () {
            if (chat) {
                chat.fechar();
            }
            window.CentraldecontatosChat.aberta = null;
            m.remove();
        });
        instancia.show();
    };

    // ------------------------------------------------------------------ caixa de conversas (página própria)

    var iniciarCaixa = function (raiz) {
        var estado = { busca: '', filtro: 'todas', aberta: 0, chat: null, timer: null };
        var listaEl = raiz.querySelector('[data-conversas]');
        var painel = raiz.querySelector('[data-painel]');

        var desenharLista = function (r) {
            var conv = r.conversas || [];
            var cont = raiz.querySelector('[data-total-nao-lidas]');
            if (cont) {
                cont.textContent = r.nao_lidas || '';
                cont.hidden = !r.nao_lidas;
            }
            var srv = raiz.querySelector('[data-servidor-geral]');
            if (srv && r.servidor) {
                srv.className = P + 'servidor ' + (r.servidor.conectado ? P + 'on' : P + 'off');
                srv.title = r.servidor.conectado ? 'Servidor WhatsApp conectado' : 'Servidor WhatsApp desconectado';
            }
            if (!conv.length) {
                listaEl.innerHTML = '<div class="centraldecontatos-vazio">' + (estado.busca ? 'Nenhuma conversa encontrada.' : 'Nenhuma conversa ainda.') + '</div>';
                return;
            }
            listaEl.innerHTML = conv.map(function (c) {
                return '<button type="button" class="' + P + 'conversa' + (c.id === estado.aberta ? ' ' + P + 'ativa' : '') + (c.nao_lidas ? ' ' + P + 'naolida' : '') + '" data-abrir="' + c.id + '">' +
                    '<span class="' + P + 'avatar">' + iniciais(c.nome) + '</span>' +
                    '<span class="' + P + 'conversa-corpo"><span class="' + P + 'conversa-linha"><strong>' + esc(c.nome) + '</strong><small>' + esc(c.quando ? c.quando.slice(0, 16) : '') + '</small></span>' +
                    '<span class="' + P + 'conversa-linha"><span class="' + P + 'previa">' + esc(c.previa || c.exibicao) + '</span>' + (c.nao_lidas ? '<span class="centraldecontatos-badge">' + c.nao_lidas + '</span>' : '') + '</span>' +
                    (c.item ? '<span class="' + P + 'conversa-item"><i class="ti ti-link"></i> ' + esc(c.item) + '</span>' : '') +
                    '</span></button>';
            }).join('');
        };

        var atualizar = function () {
            return postar('conversas', { busca: estado.busca, filtro: estado.filtro }).then(function (r) {
                if (r.success) {
                    desenharLista(r);
                }
            });
        };

        var agendar = function () {
            clearTimeout(estado.timer);
            estado.timer = setTimeout(function () {
                (document.visibilityState === 'visible' ? atualizar() : Promise.resolve()).finally(agendar);
            }, 4000);
        };

        var abrir = function (opcoes) {
            if (estado.chat) {
                estado.chat.fechar();
            }
            painel.classList.add(P + 'com-chat');
            estado.chat = montar(painel, Object.assign({ modoCaixa: true, aoMudar: atualizar, aoAbrir: function (c) {
                estado.aberta = c.id;
                var u = new URL(window.location.href);
                u.searchParams.set('conversa', c.id);
                window.history.replaceState(null, '', u.toString());
                atualizar();
            } }, opcoes));
            window.CentraldecontatosChat.aberta = estado.chat;
        };

        raiz.addEventListener('click', function (e) {
            var b = e.target.closest('[data-abrir]');
            if (b) {
                abrir({ conversas_id: parseInt(b.dataset.abrir, 10) });
                return;
            }
            var f = e.target.closest('[data-filtro]');
            if (f) {
                estado.filtro = f.dataset.filtro;
                raiz.querySelectorAll('[data-filtro]').forEach(function (x) { x.classList.toggle('active', x === f); });
                atualizar();
            }
        });
        var espera = null;
        raiz.querySelector('[data-busca]').addEventListener('input', function () {
            var v = this.value;
            clearTimeout(espera);
            espera = setTimeout(function () { estado.busca = v.trim(); atualizar(); }, 300);
        });
        raiz.querySelector('[data-nova]').addEventListener('submit', function (e) {
            e.preventDefault();
            var campo = raiz.querySelector('[data-nova-numero]');
            var v = campo.value.trim();
            if (v.replace(/\D+/g, '').length < 10) {
                aviso('Informe um número com DDD.', true);
                return;
            }
            abrir({ telefone: v });
            campo.value = '';
        });

        var inicial = parseInt(new URLSearchParams(window.location.search).get('conversa') || '0', 10);
        atualizar().then(function () {
            if (inicial > 0) {
                abrir({ conversas_id: inicial });
            }
        });
        agendar();
    };

    // ------------------------------------------------------------------ aviso de mensagem nova (qualquer página)

    var iniciarAviso = function () {
        if (document.querySelector('[data-centraldecontatos-caixa]')) {
            return;
        }
        var CHAVE = 'centraldecontatos-ultima-vista';
        var base = null;
        try {
            base = parseInt(window.sessionStorage.getItem(CHAVE) || '0', 10) || null;
        } catch (e) { /* sem armazenamento */ }
        var botao = null;
        var parar = false;
        var desenhar = function (n) {
            if (!botao) {
                botao = document.createElement('a');
                botao.className = P + 'flutuante';
                botao.href = PAGINA_CONVERSAS;
                botao.title = 'Conversas de WhatsApp';
                botao.innerHTML = '<i class="ti ti-brand-whatsapp"></i><span class="centraldecontatos-badge" data-n></span>';
                document.body.appendChild(botao);
            }
            botao.hidden = n <= 0;
            botao.querySelector('[data-n]').textContent = n > 99 ? '99+' : String(n);
        };
        var ciclo = function () {
            if (parar) {
                return;
            }
            if (document.visibilityState !== 'visible') {
                setTimeout(ciclo, 15000);
                return;
            }
            postar('resumo', {}).then(function (r) {
                if (!r.success) {
                    parar = !!r.sem_sessao || !!r.desligado || r.mensagem === 'Sem permissão.';
                    return;
                }
                desenhar(r.nao_lidas || 0);
                var u = r.ultima;
                if (u) {
                    var aberta = window.CentraldecontatosChat.aberta;
                    if (base !== null && u.id > base && !(aberta && aberta.conversaId() === u.conversas_id)) {
                        aviso('WhatsApp de ' + u.nome + ': ' + u.previa, false);
                    }
                    base = Math.max(base || 0, u.id);
                    try { window.sessionStorage.setItem(CHAVE, String(base)); } catch (e) { /* ok */ }
                } else if (base === null) {
                    base = 0;
                }
            }).finally(function () {
                setTimeout(ciclo, 15000);
            });
        };
        setTimeout(ciclo, 3000);
    };

    window.CentraldecontatosChat = { montar: montar, abrirModal: abrirModal, aberta: null };

    // Conversas listadas na aba "Contatos" do item
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-centraldecontatos-chat]');
        if (b) {
            e.preventDefault();
            abrirModal({ conversas_id: parseInt(b.dataset.centraldecontatosChat, 10), itemtype: b.dataset.itemtype, items_id: parseInt(b.dataset.itemsId, 10) });
        }
    });

    var iniciar = function () {
        var caixa = document.querySelector('[data-centraldecontatos-caixa]');
        if (caixa) {
            iniciarCaixa(caixa);
        }
        if (document.body && document.body.dataset.centraldecontatosAviso !== '0') {
            iniciarAviso();
        }
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
