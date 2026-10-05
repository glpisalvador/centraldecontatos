/* Plugin Central de Contatos - seção "Central de contatos" no formulário do item, janelas de
 * ligação, WhatsApp e e-mail (editor rico do GLPI dentro da janela), cópia, "outro contato",
 * abas e multiselect da configuração. */
(function () {
    'use strict';

    if (window.centraldecontatosCarregado) {
        return;
    }
    window.centraldecontatosCarregado = true;

    var CHAVE_RECOLHIDO = 'centraldecontatos-recolhido';

    // ------------------------------------------------------------------ utilitários

    var esc = function (t) {
        return String(t === null || t === undefined ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    };

    var lerJson = function (texto) {
        try {
            return JSON.parse(texto);
        } catch (e) {
            var m = String(texto).match(/\{[\s\S]*\}\s*$/);
            if (m) {
                try {
                    return JSON.parse(m[0]);
                } catch (e2) { /* segue */ }
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

    var dadosDe = function (raiz) {
        if (!raiz.__dados) {
            var s = raiz.querySelector('[data-centraldecontatos-dados]');
            raiz.__dados = s ? lerJson(s.textContent) : {};
        }
        return raiz.__dados;
    };

    var postar = function (raiz, acao, campos) {
        var d = dadosDe(raiz);
        var fd = new FormData();
        fd.append('action', acao);
        fd.append('itemtype', d.itemtype);
        fd.append('items_id', d.items_id);
        Object.keys(campos).forEach(function (k) {
            if (Array.isArray(campos[k])) {
                campos[k].forEach(function (v) { fd.append(k + '[]', v); });
            } else {
                fd.append(k, campos[k]);
            }
        });
        var cab = { 'X-Requested-With': 'XMLHttpRequest' };
        var meta = document.querySelector('meta[property="glpi:csrf_token"]');
        var t = d.token || (meta ? meta.getAttribute('content') : '');
        if (t) {
            fd.append('_glpi_csrf_token', t);
            cab['X-Glpi-Csrf-Token'] = t;
        }
        return fetch(d.ajax, { method: 'POST', body: fd, credentials: 'same-origin', headers: cab })
            .then(function (r) { return r.text(); })
            .then(function (texto) {
                var r = lerJson(texto);
                if (r.new_token) {
                    d.token = r.new_token;
                }
                return r;
            });
    };

    var preencher = function (modelo, raiz, nome) {
        var vars = Object.assign({}, dadosDe(raiz).variaveis || {}, { '{nome}': nome || '' });
        return String(modelo || '').replace(/\{[a-z_]+\}/g, function (v) { return vars[v] !== undefined ? vars[v] : v; }).replace(/\s+([,.!?])/g, '$1').replace(/,\s*!/g, '!');
    };

    var depoisDeRegistrar = function (r) {
        aviso(r.mensagem || (r.success ? 'Registrado.' : 'Não foi possível registrar.'), !r.success);
        if (r.success && r.followup) {
            setTimeout(function () { window.location.reload(); }, 900);
        }
    };

    // ------------------------------------------------------------------ janela (modal Bootstrap do GLPI)

    var janela = function (titulo, corpo, botaoTexto, botaoIcone, aoConfirmar, aoAbrir, aoFechar) {
        var m = document.createElement('div');
        m.className = 'modal fade centraldecontatos-modal';
        m.tabIndex = -1;
        m.innerHTML = '<div class="modal-dialog' + (aoAbrir ? ' modal-lg' : '') + '"><div class="modal-content">' +
            '<div class="modal-header"><h5 class="modal-title">' + titulo + '</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>' +
            '<div class="modal-body">' + corpo + '</div>' +
            '<div class="modal-footer"><button type="button" class="btn btn-sm btn-ghost-secondary" data-bs-dismiss="modal">Cancelar</button>' +
            '<button type="button" class="btn btn-sm centraldecontatos-btn-principal" data-confirmar><i class="' + botaoIcone + '"></i><span>' + esc(botaoTexto) + '</span></button></div>' +
            '</div></div>';
        document.body.appendChild(m);
        var instancia = window.bootstrap.Modal.getOrCreateInstance(m);
        var confirmar = m.querySelector('[data-confirmar]');
        confirmar.addEventListener('click', function () {
            confirmar.disabled = true;
            Promise.resolve(aoConfirmar(m)).then(function (fechar) {
                confirmar.disabled = false;
                if (fechar !== false) {
                    instancia.hide();
                }
            });
        });
        m.addEventListener('shown.bs.modal', function () {
            if (aoAbrir) {
                aoAbrir(m);
            }
            var foco = m.querySelector('[data-foco]');
            if (foco) {
                foco.focus();
            }
        });
        m.addEventListener('hidden.bs.modal', function () {
            if (aoFechar) {
                aoFechar(m);
            }
            m.remove();
        });
        instancia.show();
        return m;
    };

    // ------------------------------------------------------------------ ligação

    var registrarLigacao = function (raiz, nome, numero) {
        var d = dadosDe(raiz);
        if (!d.registrar || !d.registrar.ligacao) {
            return;
        }
        var opcoes = (d.resultados || []).map(function (r, i) {
            return '<label class="centraldecontatos-resultado"><input type="radio" name="centraldecontatos-resultado" value="' + esc(r) + '"' + (i === 0 ? ' data-foco' : '') + '><span>' + esc(r) + '</span></label>';
        }).join('');
        janela('<i class="ti ti-phone-call"></i> Registrar ligação',
            '<p class="centraldecontatos-explicacao"><i class="ti ti-info-circle"></i><span>Ligação para <strong>' + esc(nome || numero) + '</strong> · ' + esc(numero) + '</span></p>' +
            '<div class="centraldecontatos-campo"><label>Resultado</label><div class="centraldecontatos-resultados">' + opcoes + '</div></div>' +
            '<div class="centraldecontatos-campo"><label>Observação</label><textarea class="form-control form-control-sm" rows="3" data-obs placeholder="Opcional: o que foi combinado"></textarea></div>',
            'Registrar', 'ti ti-device-floppy',
            function (m) {
                var marcado = m.querySelector('input[name="centraldecontatos-resultado"]:checked');
                if (!marcado) {
                    aviso('Escolha o resultado da ligação.', true);
                    return false;
                }
                return postar(raiz, 'registrar', { tipo: 'ligacao', contato: nome, destino: numero, resultado: marcado.value, observacao: m.querySelector('[data-obs]').value })
                    .then(function (r) {
                        depoisDeRegistrar(r);
                        return r.success;
                    });
            });
    };

    // ------------------------------------------------------------------ WhatsApp

    var abrirWhatsapp = function (raiz, nome, numero, numeroWa) {
        var d = dadosDe(raiz);
        janela('<i class="ti ti-brand-whatsapp"></i> WhatsApp',
            '<p class="centraldecontatos-explicacao"><i class="ti ti-info-circle"></i><span>Conversa com <strong>' + esc(nome || numero) + '</strong> · ' + esc(numero) + '. A conversa abre no WhatsApp numa nova aba.</span></p>' +
            '<div class="centraldecontatos-campo"><label>Mensagem inicial</label><textarea class="form-control form-control-sm" rows="4" data-msg data-foco>' + esc(preencher(d.whatsapp, raiz, nome)) + '</textarea></div>',
            'Abrir WhatsApp', 'ti ti-brand-whatsapp',
            function (m) {
                var msg = m.querySelector('[data-msg]').value.trim();
                window.open('https://wa.me/' + encodeURIComponent(numeroWa) + (msg ? '?text=' + encodeURIComponent(msg) : ''), '_blank', 'noopener');
                if (d.registrar && d.registrar.whatsapp) {
                    postar(raiz, 'registrar', { tipo: 'whatsapp', contato: nome, destino: numero, mensagem: msg }).then(depoisDeRegistrar);
                }
                return true;
            });
    };

    // ------------------------------------------------------------------ e-mail

    var ID_EDITOR = 'centraldecontatos-email-corpo';

    var abrirEmail = function (raiz, email, nome) {
        var d = dadosDe(raiz);
        var todos = [];
        (d.contatos || []).forEach(function (c) {
            (c.emails || []).forEach(function (e) { todos.push({ email: e, nome: c.nome }); });
        });
        var atalhos = todos.length > 1 ? '<div class="centraldecontatos-atalhos-email">' + todos.map(function (t) {
            return '<button type="button" class="centraldecontatos-chip" data-adicionar-email="' + esc(t.email) + '" title="' + esc(t.nome) + '">+ ' + esc(t.email) + '</button>';
        }).join('') + '</div>' : '';
        janela('<i class="ti ti-mail"></i> Enviar e-mail',
            (!d.remetente ? '<div class="centraldecontatos-alerta centraldecontatos-alerta-aviso"><i class="ti ti-alert-triangle"></i><span>O GLPI não tem remetente de e-mail configurado: o envio vai falhar até um administrador configurar.</span></div>' : '') +
            '<div class="centraldecontatos-grade-email">' +
            '<div class="centraldecontatos-campo"><label>Para</label><input type="text" class="form-control form-control-sm" data-para value="' + esc(email || '') + '" placeholder="email@exemplo.com, outro@exemplo.com"' + (email ? '' : ' data-foco') + '>' + atalhos + '</div>' +
            '<div class="centraldecontatos-campo"><label>Cópia</label><input type="text" class="form-control form-control-sm" data-copia placeholder="Opcional"></div>' +
            '</div>' +
            '<div class="centraldecontatos-campo"><label>Assunto</label><input type="text" class="form-control form-control-sm" data-assunto maxlength="255" value="' + esc(d.assunto || '') + '"></div>' +
            '<div class="centraldecontatos-campo"><label>Mensagem</label><textarea id="' + ID_EDITOR + '" rows="10" class="form-control">' + (nome ? '<p>Olá, ' + esc(nome) + ',</p><p></p>' : '') + '</textarea></div>' +
            '<p class="centraldecontatos-explicacao"><i class="ti ti-info-circle"></i><span>O e-mail sai vinculado a este item: a resposta do destinatário volta como acompanhamento pelo coletor de e-mail do GLPI.</span></p>',
            'Enviar', 'ti ti-send',
            function (m) {
                var ed = window.tinymce ? window.tinymce.get(ID_EDITOR) : null;
                var corpo = ed ? ed.getContent() : m.querySelector('#' + ID_EDITOR).value;
                return postar(raiz, 'enviar_email', {
                    para: [m.querySelector('[data-para]').value],
                    copia: [m.querySelector('[data-copia]').value],
                    assunto: m.querySelector('[data-assunto]').value,
                    corpo: corpo,
                    contato: nome || ''
                }).then(function (r) {
                    aviso(r.mensagem, !r.success);
                    if (r.success) {
                        setTimeout(function () { window.location.reload(); }, 900);
                    }
                    return r.success;
                });
            },
            function (m) {
                // Editor rico com a mesma configuração dos editores do GLPI na página
                if (!window.tinymce) {
                    return;
                }
                var configs = window.tinymce_editor_configs || {};
                var base = configs[Object.keys(configs)[0]] || {};
                var cfg = Object.assign({}, base, {
                    selector: '#' + ID_EDITOR,
                    target: undefined,
                    height: 280,
                    min_height: 220,
                    setup: function (editor) {
                        editor.on('init', function () {
                            editor.focus();
                        });
                    },
                    init_instance_callback: undefined
                });
                // Na janela a barra fica sempre visível (o layout "em linha" do GLPI esconde a barra)
                cfg.license_key = 'gpl';
                if (!base.skin_url) {
                    // Sem editor do GLPI na página: a pele já vem no CSS do GLPI
                    cfg.skin = false;
                    cfg.content_css = false;
                    cfg.plugins = 'lists link table autoresize';
                }
                cfg.menubar = false;
                cfg.toolbar = 'bold italic underline | forecolor backcolor | bullist numlist | link table | removeformat';
                cfg.toolbar_location = 'top';
                cfg.quickbars_insert_toolbar = false;
                cfg.quickbars_selection_toolbar = false;
                cfg.statusbar = false;
                cfg.branding = false;
                cfg.content_style = (cfg.content_style || '') + ' body { font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; font-size: 13px; color: #333; }';
                window.tinymce.init(cfg);
                m.addEventListener('click', function (e) {
                    var b = e.target.closest('[data-adicionar-email]');
                    if (!b) {
                        return;
                    }
                    var campo = m.querySelector('[data-para]');
                    var lista = campo.value.split(/[\s,;]+/).filter(Boolean);
                    if (lista.indexOf(b.dataset.adicionarEmail) < 0) {
                        lista.push(b.dataset.adicionarEmail);
                    }
                    campo.value = lista.join(', ');
                });
            },
            function () {
                var ed = window.tinymce ? window.tinymce.get(ID_EDITOR) : null;
                if (ed) {
                    ed.save();
                    ed.remove();
                }
            });
    };

    // ------------------------------------------------------------------ cliques no bloco

    var canal = function (raiz, chave) {
        var p = String(chave).split(':');
        var c = (dadosDe(raiz).contatos || [])[parseInt(p[0], 10)];
        var t = c && c.telefones ? c.telefones[parseInt(p[1], 10)] : null;
        return c && t ? { nome: c.nome, numero: t.exibicao, wa: t.whatsapp } : null;
    };

    var copiar = function (texto) {
        var feito = function () { aviso('Copiado: ' + texto); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(texto).then(feito);
            return;
        }
        var area = document.createElement('textarea');
        area.value = texto;
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        try {
            document.execCommand('copy');
            feito();
        } catch (e) { /* sem cópia */ }
        area.remove();
    };

    var digitos = function (v) {
        return String(v || '').replace(/\D+/g, '');
    };

    document.addEventListener('click', function (e) {
        var raiz = e.target.closest('[data-centraldecontatos]');
        if (!raiz) {
            return;
        }
        var d = dadosDe(raiz);
        var el;
        if ((el = e.target.closest('[data-centraldecontatos-ligar]'))) {
            // O link tel: segue normalmente (abre o discador / softphone); a janela registra o resultado
            var lig = canal(raiz, el.dataset.centraldecontatosLigar);
            if (lig) {
                setTimeout(function () { registrarLigacao(raiz, lig.nome, lig.numero); }, 400);
            }
        } else if ((el = e.target.closest('[data-centraldecontatos-whatsapp]'))) {
            e.preventDefault();
            var w = canal(raiz, el.dataset.centraldecontatosWhatsapp);
            if (w) {
                abrirWhatsapp(raiz, w.nome, w.numero, w.wa);
            }
        } else if ((el = e.target.closest('[data-centraldecontatos-email]'))) {
            e.preventDefault();
            abrirEmail(raiz, el.dataset.centraldecontatosEmail, el.dataset.nome);
        } else if ((el = e.target.closest('[data-centraldecontatos-copiar]'))) {
            e.preventDefault();
            copiar(el.dataset.centraldecontatosCopiar);
        } else if ((el = e.target.closest('[data-centraldecontatos-outro-acao]'))) {
            var valor = raiz.querySelector('[data-centraldecontatos-outro]').value.trim();
            var acao = el.dataset.centraldecontatosOutroAcao;
            if (acao === 'email') {
                abrirEmail(raiz, valor.indexOf('@') > 0 ? valor : '', '');
                return;
            }
            var d1 = digitos(valor);
            if (d1.length < 8) {
                aviso('Informe um número com DDD.', true);
                return;
            }
            // Mesma regra do servidor: até 11 dígitos sem "+" recebe o código do país
            var mais = valor.charAt(0) === '+';
            var wa = (!mais && d1.length <= 11 ? digitos(d.codigo_pais) : '') + d1.replace(/^0+/, '');
            if (acao === 'ligar') {
                window.location.href = 'tel:' + (mais ? '+' : '') + d1;
                setTimeout(function () { registrarLigacao(raiz, '', valor); }, 400);
            } else {
                abrirWhatsapp(raiz, '', valor, wa);
            }
        }
    });

    // ------------------------------------------------------------------ seção nativa no formulário do item

    var montarSecao = function (bloco) {
        if (bloco.classList.contains('centraldecontatos-aba') || bloco.closest('.centraldecontatos-secao')) {
            return;
        }
        var secaoAtual = bloco.closest('section.accordion-item, .accordion-item');
        if (!secaoAtual) {
            return;
        }
        var acordeao = secaoAtual.parentNode;
        var atores = acordeao.querySelector(':scope > .accordion-item #actors');
        var depoisDe = atores ? atores.closest('.accordion-item') : secaoAtual;
        var titulo = bloco.querySelector('.centraldecontatos-titulo');
        var n = titulo ? titulo.querySelector('.centraldecontatos-n').textContent : '';
        if (titulo) {
            titulo.remove();
        }
        var recolhido = false;
        try {
            recolhido = window.localStorage.getItem(CHAVE_RECOLHIDO) === '1';
        } catch (e) { /* sem armazenamento */ }
        var secao = document.createElement('section');
        secao.className = 'accordion-item centraldecontatos-secao';
        secao.setAttribute('aria-label', 'Central de contatos');
        secao.innerHTML = '<div class="accordion-header" id="heading-centraldecontatos">' +
            '<button class="accordion-button' + (recolhido ? ' collapsed' : '') + '" type="button" data-bs-toggle="collapse" data-bs-target="#centraldecontatos-corpo" aria-expanded="' + (recolhido ? 'false' : 'true') + '" aria-controls="centraldecontatos-corpo">' +
            '<i class="ti ti-address-book" aria-hidden="true"></i><span class="item-title">Central de contatos</span>' +
            '<span class="badge bg-secondary text-secondary-fg ms-2">' + esc(n) + '</span></button></div>' +
            '<div id="centraldecontatos-corpo" class="accordion-collapse collapse' + (recolhido ? '' : ' show') + '" aria-labelledby="heading-centraldecontatos"><div class="accordion-body"></div></div>';
        secao.querySelector('.accordion-body').appendChild(bloco);
        depoisDe.parentNode.insertBefore(secao, depoisDe.nextSibling);
        var corpo = secao.querySelector('#centraldecontatos-corpo');
        corpo.addEventListener('shown.bs.collapse', function () { try { window.localStorage.setItem(CHAVE_RECOLHIDO, '0'); } catch (e) { /* ok */ } });
        corpo.addEventListener('hidden.bs.collapse', function () { try { window.localStorage.setItem(CHAVE_RECOLHIDO, '1'); } catch (e) { /* ok */ } });
    };

    var varrer = function () {
        document.querySelectorAll('[data-centraldecontatos]').forEach(function (bloco) {
            if (bloco.dataset.pronto === '1') {
                return;
            }
            bloco.dataset.pronto = '1';
            montarSecao(bloco);
        });
    };

    // O formulário do item chega por AJAX (abas do GLPI): observa a página
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', varrer);
    } else {
        varrer();
    }
    new MutationObserver(function () { varrer(); }).observe(document.documentElement, { childList: true, subtree: true });

    // ------------------------------------------------------------------ configuração: abas e multiselect

    document.addEventListener('click', function (e) {
        var aba = e.target.closest('.centraldecontatos-abas [data-aba]');
        if (!aba) {
            return;
        }
        e.preventDefault();
        var pagina = aba.closest('.centraldecontatos-pagina');
        pagina.querySelectorAll('.centraldecontatos-abas [data-aba]').forEach(function (l) { l.classList.toggle('active', l === aba); });
        pagina.querySelectorAll('[data-aba-painel]').forEach(function (p) { p.hidden = p.dataset.abaPainel !== aba.dataset.aba; });
        pagina.querySelectorAll('input[name="aba"]').forEach(function (i) { i.value = aba.dataset.aba; });
        var url = new URL(window.location.href);
        url.searchParams.set('aba', aba.dataset.aba);
        window.history.replaceState(null, '', url.toString());
    });

    var opcoesMs = function (ms) {
        return Array.from(ms.querySelectorAll('.centraldecontatos-ms-opcao'));
    };

    var atualizarMs = function (ms) {
        var lista = opcoesMs(ms);
        var marcadas = lista.filter(function (o) { return o.querySelector('input').checked; });
        var nome = function (o) { return o.querySelector('.centraldecontatos-ms-rotulo').childNodes[0].textContent.trim(); };
        var texto = ms.querySelector('.centraldecontatos-ms-texto');
        if (!marcadas.length) {
            texto.textContent = ms.dataset.placeholder || 'Selecione...';
        } else if (marcadas.length <= 3) {
            texto.textContent = marcadas.map(nome).join(', ');
        } else {
            texto.textContent = marcadas.slice(0, 2).map(nome).join(', ') + ' e mais ' + (marcadas.length - 2);
        }
        ms.querySelector('.centraldecontatos-ms-contador').textContent = marcadas.length + ' de ' + lista.length + ' selecionado(s)';
        var visiveis = lista.filter(function (o) { return !o.hidden; });
        var n = visiveis.filter(function (o) { return o.querySelector('input').checked; }).length;
        var todos = ms.querySelector('[data-centraldecontatos-ms-todos]');
        todos.checked = visiveis.length > 0 && n === visiveis.length;
        todos.indeterminate = n > 0 && n < visiveis.length;
    };

    var reordenarMs = function (ms) {
        var caixa = ms.querySelector('.centraldecontatos-ms-opcoes');
        opcoesMs(ms).sort(function (a, b) {
            var ca = a.querySelector('input').checked ? 0 : 1;
            var cb = b.querySelector('input').checked ? 0 : 1;
            return ca !== cb ? ca - cb : a.dataset.label.localeCompare(b.dataset.label, 'pt-BR', { numeric: true });
        }).forEach(function (o) { caixa.appendChild(o); });
    };

    var filtrarMs = function (ms) {
        var termo = ms.querySelector('.centraldecontatos-ms-busca').value.trim().toLowerCase();
        opcoesMs(ms).forEach(function (o) { o.hidden = termo !== '' && o.dataset.label.indexOf(termo) < 0; });
        atualizarMs(ms);
    };

    document.addEventListener('click', function (e) {
        var abrir = e.target.closest('[data-centraldecontatos-ms-abrir]');
        document.querySelectorAll('[data-centraldecontatos-ms]').forEach(function (ms) {
            var drop = ms.querySelector('.centraldecontatos-ms-dropdown');
            if (abrir && ms.contains(abrir)) {
                drop.hidden = !drop.hidden;
                if (!drop.hidden) {
                    ms.querySelector('.centraldecontatos-ms-busca').focus();
                }
            } else if (!ms.contains(e.target)) {
                drop.hidden = true;
            }
        });
    });

    document.addEventListener('input', function (e) {
        if (e.target.matches('.centraldecontatos-ms-busca')) {
            filtrarMs(e.target.closest('[data-centraldecontatos-ms]'));
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.matches('.centraldecontatos-ms-busca')) {
            e.preventDefault();
        }
    });

    document.addEventListener('change', function (e) {
        var ms = e.target.closest('[data-centraldecontatos-ms]');
        if (!ms) {
            return;
        }
        if (e.target.matches('[data-centraldecontatos-ms-todos]')) {
            opcoesMs(ms).forEach(function (o) {
                if (!o.hidden) {
                    o.querySelector('input').checked = e.target.checked;
                    o.classList.toggle('selected', e.target.checked);
                }
            });
        } else if (e.target.closest('.centraldecontatos-ms-opcao')) {
            e.target.closest('.centraldecontatos-ms-opcao').classList.toggle('selected', e.target.checked);
            var busca = ms.querySelector('.centraldecontatos-ms-busca');
            if (busca.value) {
                busca.value = '';
                filtrarMs(ms);
                busca.focus();
            }
        } else {
            return;
        }
        reordenarMs(ms);
        atualizarMs(ms);
    });

    var iniciarMs = function () {
        document.querySelectorAll('[data-centraldecontatos-ms]').forEach(atualizarMs);
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciarMs);
    } else {
        iniciarMs();
    }
})();
