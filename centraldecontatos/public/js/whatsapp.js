/* Plugin Central de Contatos - tela do servidor WhatsApp: situação, QR Code, etapas de preparação e log */
(function () {
    'use strict';

    var raiz = document.querySelector('[data-centraldecontatos-servidor]');
    if (!raiz || raiz.dataset.pronto) {
        return;
    }
    raiz.dataset.pronto = '1';
    var AJAX = raiz.dataset.ajax;
    var timer = null;
    var ocupado = false;

    var q = function (s) { return raiz.querySelector(s); };
    var esc = function (t) {
        return String(t === null || t === undefined ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    };
    var lerJson = function (texto) {
        try { return JSON.parse(texto); } catch (e) {
            var m = String(texto).match(/\{[\s\S]*\}\s*$/);
            if (m) { try { return JSON.parse(m[0]); } catch (e2) { /* segue */ } }
        }
        return { success: false, mensagem: 'Resposta inválida do servidor.' };
    };
    var aviso = function (mensagem, erro) {
        var fn = erro ? window.glpi_toast_error : window.glpi_toast_info;
        if (typeof fn === 'function' && mensagem) { fn(mensagem); }
    };

    var postar = function (campos) {
        var fd = new FormData();
        Object.keys(campos).forEach(function (k) { fd.append(k, campos[k]); });
        var cab = { 'X-Requested-With': 'XMLHttpRequest' };
        var meta = document.querySelector('meta[property="glpi:csrf_token"]');
        var t = meta ? meta.getAttribute('content') : '';
        if (t) {
            fd.append('_glpi_csrf_token', t);
            cab['X-Glpi-Csrf-Token'] = t;
        }
        return fetch(AJAX, { method: 'POST', body: fd, credentials: 'same-origin', headers: cab })
            .then(function (r) { return r.text(); })
            .then(function (x) {
                var r = lerJson(x);
                if (r.new_token && meta) { meta.setAttribute('content', r.new_token); }
                return r;
            });
    };

    var ROTULOS = {
        node_instalar: ['ti ti-download', 'Instalar'],
        node_atualizar: ['ti ti-refresh', 'Atualizar'],
        dependencias_instalar: ['ti ti-package', 'Instalar/atualizar'],
        vigia_ativar: ['ti ti-eye', 'Ativar'],
        vigia_desativar: ['ti ti-eye-off', 'Desativar'],
        webhook_testar: ['ti ti-plug-connected', 'Testar']
    };
    var ICONES = { ok: 'ti ti-circle-check', pendente: 'ti ti-circle-dashed', erro: 'ti ti-alert-triangle', andamento: 'ti ti-loader-2' };

    var desenhar = function (r) {
        var sit = q('[data-situacao]');
        var texto;
        var classe;
        if (!r.ligado) {
            texto = r.deve_ligado ? 'Ligando...' : 'Desligado';
            classe = r.deve_ligado ? 'aviso' : 'neutro';
        } else if (r.conectado) {
            texto = 'Conectado';
            classe = 'ok';
        } else if (r.qr) {
            texto = 'Aguardando leitura do QR Code';
            classe = 'aviso';
        } else {
            texto = r.pareado ? 'Conectando...' : 'Preparando o QR Code...';
            classe = 'aviso';
        }
        sit.textContent = texto;
        sit.className = 'centraldecontatos-selo centraldecontatos-wa-selo-' + classe;
        q('[data-numero]').textContent = r.numero || '—';
        q('[data-nome]').textContent = r.nome || '—';
        q('[data-desde]').textContent = r.desde || '—';
        q('[data-pid]').textContent = r.pid ? 'pid ' + r.pid : '—';
        var v = r.versoes || {};
        q('[data-versoes]').textContent = [v.node ? 'Node ' + v.node : '', v.baileys ? 'Baileys ' + v.baileys : '', v.whatsapp ? 'WhatsApp Web ' + v.whatsapp : ''].filter(Boolean).join(' · ') || '—';
        raiz.querySelector('[data-acao="iniciar"]').disabled = !!r.ligado;
        raiz.querySelector('[data-acao="parar"]').disabled = !r.ligado && !r.deve_ligado;
        raiz.querySelector('[data-acao="reiniciar"]').disabled = !r.ligado;
        raiz.querySelector('[data-acao="desvincular"]').disabled = !r.ligado || !r.pareado;

        var card = q('[data-qr-card]');
        card.hidden = !r.qr;
        if (r.qr) {
            q('[data-qr]').src = r.qr;
        }

        q('[data-etapas]').innerHTML = (r.etapas || []).map(function (e) {
            var tarefa = e.tarefa && r.tarefas ? r.tarefas[e.tarefa] : null;
            var log = tarefa && tarefa.existe && tarefa.log ? '<details' + (tarefa.rodando ? ' open' : '') + '><summary>Saída da tarefa' + (tarefa.terminou ? (tarefa.sucesso ? ' (concluída)' : ' (falhou)') : '') + '</summary><pre>' + esc(tarefa.log) + '</pre></details>' : '';
            return '<li class="centraldecontatos-wa-etapa centraldecontatos-wa-etapa-' + esc(e.estado) + '"><i class="' + (ICONES[e.estado] || ICONES.pendente) + '"></i>' +
                '<div><strong>' + esc(e.rotulo) + '</strong><small>' + esc(e.detalhe) + '</small>' + log + '</div>' +
                '<span class="centraldecontatos-wa-etapa-acoes">' + (e.acoes || []).map(function (a) {
                    var rot = ROTULOS[a] || ['ti ti-player-play', a];
                    return '<button type="button" class="btn btn-sm btn-ghost-secondary" data-acao="' + esc(a) + '"' + (e.estado === 'andamento' ? ' disabled' : '') + '><i class="' + rot[0] + '"></i><span>' + rot[1] + '</span></button>';
                }).join('') + '</span></li>';
        }).join('');

        var pre = q('[data-log]');
        if (pre.textContent !== (r.log || '')) {
            pre.textContent = r.log || '(sem registro ainda)';
            if (q('[data-seguir]').checked) {
                pre.scrollTop = pre.scrollHeight;
            }
        }
    };

    var consultar = function (campos) {
        return postar(Object.assign({ action: 'wa_status' }, campos || {})).then(function (r) {
            if (r.mensagem && campos) {
                aviso(r.mensagem, !r.success);
                q('[data-retorno]').textContent = r.mensagem;
            }
            if (r.etapas) {
                desenhar(r);
            }
            return r;
        });
    };

    var agendar = function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
            (document.visibilityState === 'visible' && !ocupado ? consultar() : Promise.resolve()).finally(agendar);
        }, 3000);
    };

    raiz.addEventListener('click', function (e) {
        var b = e.target.closest('[data-acao]');
        if (!b || b.disabled) {
            return;
        }
        // Confirmação dentro do próprio botão (sem janelas do navegador)
        if (b.dataset.confirmar && b.dataset.armado !== '1') {
            b.dataset.armado = '1';
            b.dataset.original = b.innerHTML;
            b.innerHTML = '<i class="ti ti-alert-triangle"></i><span>Confirmar</span>';
            b.title = b.dataset.confirmar;
            setTimeout(function () {
                if (b.dataset.armado === '1') {
                    b.dataset.armado = '';
                    b.innerHTML = b.dataset.original;
                }
            }, 4000);
            return;
        }
        if (b.dataset.armado === '1') {
            b.dataset.armado = '';
            b.innerHTML = b.dataset.original;
        }
        ocupado = true;
        b.disabled = true;
        consultar({ action: 'wa_acao', acao: b.dataset.acao }).finally(function () {
            ocupado = false;
            b.disabled = false;
        });
    });

    consultar().finally(agendar);
})();
