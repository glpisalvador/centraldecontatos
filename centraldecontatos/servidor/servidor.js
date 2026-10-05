// servidor.js - servidor WhatsApp do plugin Central de Contatos (GLPI 11 e 12)
// Recebe e envia mensagens (texto, imagem, audio, video, documento), avisa o GLPI de tudo pelo webhook
// (mensagens recebidas, enviadas pelo proprio celular, confirmacoes de entrega/leitura e reacoes)
// e atende o GLPI so em 127.0.0.1, com token interno.

import http from 'http';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { createRequire } from 'module';
import {
   makeWASocket,
   useMultiFileAuthState,
   makeCacheableSignalKeyStore,
   DisconnectReason,
   fetchLatestBaileysVersion,
   downloadMediaMessage,
   normalizeMessageContent
} from '@whiskeysockets/baileys';
import pino from 'pino';
import QRCode from 'qrcode';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PASTA_AUTH = (process.env.CDC_AUTH || '').trim() || path.join(__dirname, 'auth');
const PASTA_MIDIA = (process.env.CDC_MIDIA || '').trim() || path.join(__dirname, 'midia');
const PORTA = parseInt(process.env.CDC_PORTA || '3470', 10);
const TOKEN = process.env.CDC_TOKEN || '';
const WEBHOOK = process.env.CDC_WEBHOOK || '';
const MIDIA_MAX = Math.max(1, parseInt(process.env.CDC_MIDIA_MAX_MB || '16', 10)) * 1024 * 1024;

if (process.env.CDC_TLS_INSEGURO === '1') {
   process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
}

let socket = null;
let conectado = false;
let paradaManual = false;
let ultimoQr = null;
let versaoWa = null;
let conexao = { numero: null, nome: null, desde: null };

let versaoBaileys = 'desconhecida';
try {
   versaoBaileys = createRequire(import.meta.url)('@whiskeysockets/baileys/package.json').version;
} catch (e) { /* sem versao */ }

const registrador = pino({ level: 'silent' });

function agora() {
   return new Date().toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo' });
}

function registrar(evento, detalhe = '') {
   process.stdout.write(`[${agora()}] ${evento}${detalhe ? ' - ' + detalhe : ''}\n`);
}

if (WEBHOOK === '' || TOKEN === '') {
   registrar('Webhook ou token ausente', `webhook="${WEBHOOK}" token=${TOKEN ? 'definido' : 'vazio'}`);
}

// ============================================
// Mensagens enviadas: reenvio quando o aparelho pede ("Aguardando mensagem")
// e para nao duplicar no GLPI o que o proprio GLPI mandou
// ============================================

const enviadas = new Map();
const LIMITE_ENVIADAS = 3000;

function guardarEnviada(id, conteudo) {
   if (!id || !conteudo) return;
   enviadas.set(id, conteudo);
   if (enviadas.size > LIMITE_ENVIADAS) enviadas.delete(enviadas.keys().next().value);
}

async function enviarGuardando(destino, conteudo, opcoes) {
   const enviada = await socket.sendMessage(destino, conteudo, opcoes || {});
   if (enviada && enviada.key) guardarEnviada(enviada.key.id, enviada.message);
   return enviada;
}

const contadorReenvio = {
   dados: new Map(),
   get(chave) { return this.dados.get(chave); },
   set(chave, valor) {
      this.dados.set(chave, valor);
      if (this.dados.size > 5000) this.dados.delete(this.dados.keys().next().value);
      return true;
   },
   del(chave) { this.dados.delete(chave); return 1; },
   flushAll() { this.dados.clear(); }
};

// ============================================
// Numeros, JIDs e LIDs
// ============================================

function autenticacaoExiste() {
   try { return fs.existsSync(path.join(PASTA_AUTH, 'creds.json')); } catch (e) { return false; }
}

function limparAutenticacao() {
   try {
      if (fs.existsSync(PASTA_AUTH)) fs.rmSync(PASTA_AUTH, { recursive: true, force: true });
      fs.mkdirSync(PASTA_AUTH, { recursive: true });
   } catch (e) {
      registrar('Falha ao limpar a pasta da sessao', e.message);
   }
}

function numeroDoJid(jid) {
   return String(jid || '').split('@')[0].split(':')[0];
}

function formatarNumero(numero) {
   let limpo = String(numero).replace(/\D/g, '');
   if (limpo.length === 10 || limpo.length === 11) limpo = '55' + limpo;
   return limpo + '@s.whatsapp.net';
}

const mapaJid = new Map();
const mapaLid = new Map();
const ARQUIVO_MAPA = path.join(PASTA_AUTH, 'mapa-jid.json');

function carregarMapa() {
   try {
      if (fs.existsSync(ARQUIVO_MAPA)) {
         const dados = JSON.parse(fs.readFileSync(ARQUIVO_MAPA, 'utf8'));
         Object.keys(dados.numeros || {}).forEach((k) => {
            if (!String(dados.numeros[k]).endsWith('@lid')) mapaJid.set(k, dados.numeros[k]);
         });
         Object.keys(dados.lids || {}).forEach((k) => mapaLid.set(k, dados.lids[k]));
         registrar('Mapa de conversas carregado', `${mapaJid.size} numero(s), ${mapaLid.size} lid(s)`);
      }
   } catch (e) {
      registrar('Falha ao carregar o mapa de conversas', e.message);
   }
}

function gravarMapa() {
   try {
      fs.writeFileSync(ARQUIVO_MAPA, JSON.stringify({ numeros: Object.fromEntries(mapaJid), lids: Object.fromEntries(mapaLid) }), 'utf8');
   } catch (e) { /* segue */ }
}

function associar(telefone, jid) {
   if (!telefone || !jid) return;
   let mudou = false;
   if (String(jid).endsWith('@lid')) {
      if (mapaLid.get(jid) !== telefone) { mapaLid.set(jid, telefone); mudou = true; }
   } else if (mapaJid.get(telefone) !== jid) {
      mapaJid.set(telefone, jid);
      mudou = true;
   }
   if (mudou) gravarMapa();
}

/** Variantes do numero brasileiro com e sem o nono digito */
function variantesDoNumero(telefone) {
   const lista = [];
   let base = String(telefone || '').replace(/\D/g, '');
   if (base === '') return lista;
   if (base.length === 10 || base.length === 11) base = '55' + base;
   lista.push(base);
   if (base.startsWith('55') && base.length === 12) lista.push(base.slice(0, 4) + '9' + base.slice(4));
   if (base.startsWith('55') && base.length === 13 && base[4] === '9') lista.push(base.slice(0, 4) + base.slice(5));
   return lista;
}

function lidDoTelefone(numero) {
   if (!numero) return null;
   const variantes = variantesDoNumero(numero);
   for (const [lid, fone] of mapaLid) {
      if (variantes.indexOf(String(fone)) >= 0) return lid;
   }
   return null;
}

/** Enderecos a tentar, do mais provavel para o menos provavel (LID primeiro: Android atual so decifra o que vai para ele) */
function candidatosDeEnvio(jid, telefone) {
   const lista = [];
   const incluir = (v) => { if (v && lista.indexOf(v) < 0) lista.push(v); };
   const numero = String(telefone || '').replace(/\D/g, '');
   const ehLid = String(jid || '').endsWith('@lid');
   incluir(ehLid ? jid : lidDoTelefone(numero));
   if (!ehLid) incluir(jid);
   incluir(numero !== '' ? mapaJid.get(numero) : null);
   variantesDoNumero(numero).forEach((v) => incluir(v + '@s.whatsapp.net'));
   return lista;
}

/** Telefone real quando o WhatsApp entrega a mensagem com LID */
async function telefoneDaMensagem(mensagem) {
   const chave = mensagem.key || {};
   for (const c of [chave.senderPn, chave.participantPn, chave.remoteJidAlt, chave.participantAlt, mensagem.senderPn, chave.remoteJid]) {
      if (c && String(c).includes('@s.whatsapp.net')) return numeroDoJid(c);
   }
   if (mapaLid.has(chave.remoteJid)) return mapaLid.get(chave.remoteJid);
   try {
      const mapeador = socket?.signalRepository?.lidMapping;
      if (mapeador && typeof mapeador.getPNForLID === 'function') {
         const achado = await mapeador.getPNForLID(chave.remoteJid);
         if (achado && String(achado).includes('@s.whatsapp.net')) return numeroDoJid(achado);
      }
   } catch (e) { /* segue */ }
   return '';
}

async function resolverJid(numero) {
   const limpo = String(numero).replace(/\D/g, '');
   if (mapaJid.has(limpo)) return mapaJid.get(limpo);
   try {
      const consulta = await socket.onWhatsApp(limpo);
      if (Array.isArray(consulta) && consulta[0] && consulta[0].exists) {
         const achado = consulta[0];
         if (achado.lid) { mapaLid.set(achado.lid, limpo); gravarMapa(); }
         if (achado.jid && !String(achado.jid).endsWith('@lid')) {
            associar(limpo, achado.jid);
            return achado.jid;
         }
      }
   } catch (e) {
      registrar('Falha ao consultar o numero no WhatsApp', `${limpo} - ${e.message}`);
   }
   return formatarNumero(limpo);
}

// ============================================
// Conversa com o GLPI (webhook)
// ============================================

async function avisarGlpi(dados, tentativa = 1) {
   if (!WEBHOOK) return null;
   try {
      const resposta = await fetch(WEBHOOK, {
         method: 'POST',
         headers: { 'Content-Type': 'application/json', 'X-Token-Interno': TOKEN },
         body: JSON.stringify(Object.assign({ token: TOKEN }, dados))
      });
      const bruto = await resposta.text();
      if (!resposta.ok) {
         registrar('Webhook recusou', `HTTP ${resposta.status} - ${bruto.substring(0, 200)}`);
         if (resposta.status >= 500 && tentativa < 3) {
            await new Promise((r) => setTimeout(r, 1500 * tentativa));
            return avisarGlpi(dados, tentativa + 1);
         }
         return null;
      }
      try { return JSON.parse(bruto); } catch (e) { return null; }
   } catch (e) {
      registrar('Falha ao avisar o GLPI', `${WEBHOOK} - ${e.message}`);
      if (tentativa < 3) {
         await new Promise((r) => setTimeout(r, 1500 * tentativa));
         return avisarGlpi(dados, tentativa + 1);
      }
      return null;
   }
}

// ============================================
// Midia
// ============================================

const EXTENSOES = {
   'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp', 'image/gif': 'gif',
   'audio/ogg': 'ogg', 'audio/opus': 'ogg', 'audio/mp4': 'm4a', 'audio/mpeg': 'mp3', 'audio/aac': 'aac', 'audio/amr': 'amr', 'audio/webm': 'webm',
   'video/mp4': 'mp4', 'video/3gpp': '3gp', 'video/quicktime': 'mov', 'video/webm': 'webm',
   'application/pdf': 'pdf'
};

function anoMes() {
   const d = new Date();
   return String(d.getFullYear()) + String(d.getMonth() + 1).padStart(2, '0');
}

function gravarMidia(buffer, extensao, prefixo) {
   const mes = anoMes();
   const pasta = path.join(PASTA_MIDIA, mes);
   fs.mkdirSync(pasta, { recursive: true });
   const nome = `${prefixo}-${Date.now()}-${Math.random().toString(36).slice(2, 10)}.${extensao}`;
   fs.writeFileSync(path.join(pasta, nome), buffer);
   return `${mes}/${nome}`;
}

function lerMidia(relativo) {
   if (!/^[0-9]{6}\/[A-Za-z0-9_.-]+$/.test(String(relativo || ''))) throw new Error('Caminho de midia invalido');
   return fs.readFileSync(path.join(PASTA_MIDIA, relativo));
}

function extensaoSegura(nome, mime, padrao) {
   const pelaExt = String(nome || '').toLowerCase().match(/\.([a-z0-9]{1,8})$/);
   if (pelaExt) return pelaExt[1];
   return EXTENSOES[mime] || padrao;
}

/** Tipo e no de midia da mensagem */
function midiaDaMensagem(conteudo) {
   if (conteudo.imageMessage) return { tipo: 'imagem', no: conteudo.imageMessage };
   if (conteudo.audioMessage) return { tipo: 'audio', no: conteudo.audioMessage };
   if (conteudo.videoMessage) return { tipo: 'video', no: conteudo.videoMessage };
   if (conteudo.documentMessage) return { tipo: 'documento', no: conteudo.documentMessage };
   if (conteudo.documentWithCaptionMessage?.message?.documentMessage) return { tipo: 'documento', no: conteudo.documentWithCaptionMessage.message.documentMessage };
   if (conteudo.stickerMessage) return { tipo: 'figurinha', no: conteudo.stickerMessage };
   return null;
}

async function baixarMidia(mensagem, info) {
   const tamanho = Number(info.no.fileLength || 0);
   if (tamanho > MIDIA_MAX) throw new Error(`arquivo de ${Math.round(tamanho / 1048576)} MB acima do limite`);
   const buffer = await downloadMediaMessage(mensagem, 'buffer', {}, { logger: registrador, reuploadRequest: socket.updateMediaMessage });
   const padrao = { imagem: 'image/jpeg', audio: 'audio/ogg', video: 'video/mp4', documento: 'application/octet-stream', figurinha: 'image/webp' }[info.tipo];
   const mime = String(info.no.mimetype || padrao).split(';')[0].trim();
   const nome = info.tipo === 'documento' ? String(info.no.fileName || 'documento') : '';
   const extensao = extensaoSegura(nome, mime, { imagem: 'jpg', audio: 'ogg', video: 'mp4', documento: 'bin', figurinha: 'webp' }[info.tipo]);
   return { tipo: info.tipo, arquivo: gravarMidia(buffer, extensao, 'wa'), mime, nome, tamanho: buffer.length };
}

// ============================================
// Conversao WebM (gravado no navegador) -> OGG/Opus (nota de voz do WhatsApp), sem recodificar
// ============================================

function lerVint(buf, pos, manterMarcador) {
   const primeiro = buf[pos];
   if (primeiro === undefined) return null;
   let tamanho = 1;
   let mascara = 0x80;
   while (tamanho <= 8 && !(primeiro & mascara)) { mascara >>= 1; tamanho++; }
   if (tamanho > 8 || pos + tamanho > buf.length) return null;
   let valor = manterMarcador ? primeiro : (primeiro & (mascara - 1));
   let desconhecido = (primeiro & (mascara - 1)) === (mascara - 1);
   for (let i = 1; i < tamanho; i++) {
      valor = valor * 256 + buf[pos + i];
      if (buf[pos + i] !== 0xff) desconhecido = false;
   }
   return { valor, tamanho, desconhecido: !manterMarcador && desconhecido };
}

function extrairOpusDoWebm(buf) {
   const CONTEINERES = new Set([0x18538067, 0x1F43B675, 0x1654AE6B, 0xAE, 0xE1, 0xA0]);
   const pacotes = [];
   let trilhaAudio = null;
   let trilhaAtual = null;
   let codecAtual = '';
   let cabecalho = null;
   let canais = 1;
   let pos = 0;
   while (pos < buf.length) {
      const id = lerVint(buf, pos, true);
      if (!id) break;
      const tam = lerVint(buf, pos + id.tamanho, false);
      if (!tam) break;
      const inicio = pos + id.tamanho + tam.tamanho;
      const fim = tam.desconhecido ? buf.length : Math.min(buf.length, inicio + tam.valor);
      if (CONTEINERES.has(id.valor)) {
         if (id.valor === 0xAE) { trilhaAtual = null; codecAtual = ''; }
         pos = inicio;
         continue;
      }
      const dados = buf.subarray(inicio, fim);
      switch (id.valor) {
         case 0xD7:
            trilhaAtual = dados.reduce((a, b) => a * 256 + b, 0);
            if (codecAtual === 'A_OPUS') trilhaAudio = trilhaAtual;
            break;
         case 0x86:
            codecAtual = dados.toString('latin1');
            if (codecAtual === 'A_OPUS' && trilhaAtual !== null) trilhaAudio = trilhaAtual;
            break;
         case 0x63A2:
            if (codecAtual === 'A_OPUS' || dados.subarray(0, 8).toString('latin1') === 'OpusHead') cabecalho = Buffer.from(dados);
            break;
         case 0x9F:
            canais = dados[0] || 1;
            break;
         case 0xA3:
         case 0xA1: {
            const trilha = lerVint(dados, 0, false);
            if (!trilha) break;
            if (trilhaAudio !== null && trilha.valor !== trilhaAudio) break;
            const flags = dados[trilha.tamanho + 2];
            if ((flags & 0x06) !== 0) break;
            pacotes.push(Buffer.from(dados.subarray(trilha.tamanho + 3)));
            break;
         }
      }
      pos = fim;
   }
   if (!cabecalho) {
      cabecalho = Buffer.alloc(19);
      cabecalho.write('OpusHead', 0, 'latin1');
      cabecalho[8] = 1;
      cabecalho[9] = canais;
      cabecalho.writeUInt16LE(312, 10);
      cabecalho.writeUInt32LE(48000, 12);
   }
   return { cabecalho, pacotes };
}

function amostrasDoPacoteOpus(pacote) {
   if (!pacote.length) return 0;
   const toc = pacote[0];
   const config = toc >> 3;
   let porQuadro;
   if (config < 12) porQuadro = [480, 960, 1920, 2880][config % 4];
   else if (config < 16) porQuadro = [480, 960][config % 2];
   else porQuadro = [120, 240, 480, 960][config % 4];
   const c = toc & 0x03;
   const quadros = c === 0 ? 1 : (c === 3 ? (pacote[1] & 0x3F) : 2);
   return porQuadro * quadros;
}

const TABELA_CRC = (() => {
   const t = new Uint32Array(256);
   for (let i = 0; i < 256; i++) {
      let r = i << 24;
      for (let j = 0; j < 8; j++) r = (r & 0x80000000) ? ((r << 1) ^ 0x04C11DB7) : (r << 1);
      t[i] = r >>> 0;
   }
   return t;
})();

function crcOgg(buf) {
   let crc = 0;
   for (let i = 0; i < buf.length; i++) crc = ((crc << 8) ^ TABELA_CRC[((crc >>> 24) ^ buf[i]) & 0xff]) >>> 0;
   return crc >>> 0;
}

function paginaOgg(pacotes, granulo, serie, sequencia, tipo) {
   const lacos = [];
   for (const p of pacotes) {
      let resta = p.length;
      while (resta >= 255) { lacos.push(255); resta -= 255; }
      lacos.push(resta);
   }
   const topo = Buffer.alloc(27 + lacos.length);
   topo.write('OggS', 0, 'latin1');
   topo[4] = 0;
   topo[5] = tipo;
   topo.writeBigInt64LE(BigInt(granulo), 6);
   topo.writeUInt32LE(serie, 14);
   topo.writeUInt32LE(sequencia, 18);
   topo.writeUInt32LE(0, 22);
   topo[26] = lacos.length;
   Buffer.from(lacos).copy(topo, 27);
   const pagina = Buffer.concat([topo, ...pacotes]);
   pagina.writeUInt32LE(crcOgg(pagina), 22);
   return pagina;
}

function webmParaOgg(buf) {
   const { cabecalho, pacotes } = extrairOpusDoWebm(buf);
   if (!pacotes.length) throw new Error('Nenhum audio Opus encontrado na gravacao');
   const serie = Math.floor(Math.random() * 0xffffffff) >>> 0;
   const fornecedor = Buffer.from('centraldecontatos', 'latin1');
   const tags = Buffer.alloc(8 + 4 + fornecedor.length + 4);
   tags.write('OpusTags', 0, 'latin1');
   tags.writeUInt32LE(fornecedor.length, 8);
   fornecedor.copy(tags, 12);
   tags.writeUInt32LE(0, 12 + fornecedor.length);
   const paginas = [paginaOgg([cabecalho], 0, serie, 0, 0x02), paginaOgg([tags], 0, serie, 1, 0x00)];
   let sequencia = 2;
   let granulo = 0;
   let grupo = [];
   let segmentos = 0;
   pacotes.forEach((pacote, i) => {
      const precisa = Math.floor(pacote.length / 255) + 1;
      if (grupo.length && (segmentos + precisa > 255 || grupo.length >= 50)) {
         paginas.push(paginaOgg(grupo, granulo, serie, sequencia++, 0x00));
         grupo = [];
         segmentos = 0;
      }
      grupo.push(pacote);
      segmentos += precisa;
      granulo += amostrasDoPacoteOpus(pacote);
      if (i === pacotes.length - 1) paginas.push(paginaOgg(grupo, granulo, serie, sequencia++, 0x04));
   });
   return { ogg: Buffer.concat(paginas), segundos: Math.round(granulo / 48000) };
}

// ============================================
// Envio
// ============================================

function mensagemCitada(destino, citar) {
   if (!citar || !citar.wa_id) return null;
   const deMim = !!citar.de_mim;
   return {
      key: { remoteJid: destino, fromMe: deMim, id: String(citar.wa_id), participant: deMim ? undefined : destino },
      message: { conversation: String(citar.texto || '') }
   };
}

/** Monta o conteudo do Baileys a partir do pedido do GLPI */
function montarConteudo(texto, midia) {
   if (!midia) return { conteudo: { text: texto }, convertido: null };
   let buffer = lerMidia(midia.arquivo);
   let mime = String(midia.mime || '').split(';')[0].trim();
   let convertido = null;
   switch (midia.tipo) {
      case 'imagem':
         return { conteudo: Object.assign({ image: buffer, mimetype: mime || 'image/jpeg' }, texto ? { caption: texto } : {}), convertido };
      case 'video':
         return { conteudo: Object.assign({ video: buffer, mimetype: mime || 'video/mp4' }, texto ? { caption: texto } : {}), convertido };
      case 'audio':
         if (mime === 'audio/webm' || mime === 'video/webm') {
            const { ogg, segundos } = webmParaOgg(buffer);
            buffer = ogg;
            mime = 'audio/ogg';
            convertido = { arquivo: gravarMidia(ogg, 'ogg', 'glpi'), mime, segundos };
         }
         return {
            conteudo: mime === 'audio/ogg' ? { audio: buffer, mimetype: 'audio/ogg; codecs=opus', ptt: true } : { audio: buffer, mimetype: mime || 'audio/mp4', ptt: false },
            convertido,
            legendaSeparada: texto
         };
      default:
         return {
            conteudo: Object.assign({ document: buffer, mimetype: mime || 'application/octet-stream', fileName: String(midia.nome || path.basename(midia.arquivo)) }, texto ? { caption: texto } : {}),
            convertido
         };
   }
}

/** Envia tentando cada endereco do contato; devolve { jid, id, convertido } */
async function enviar(telefone, texto, midia, citar) {
   if (!conectado || !socket) throw new Error('WhatsApp nao esta conectado');
   const { conteudo, convertido, legendaSeparada } = montarConteudo(texto, midia);
   const jid = await resolverJid(telefone);
   const falhas = [];
   for (const endereco of candidatosDeEnvio(jid, telefone)) {
      try {
         const citada = mensagemCitada(endereco, citar);
         const enviada = await enviarGuardando(endereco, conteudo, citada ? { quoted: citada } : {});
         // Audio nao tem legenda no WhatsApp: o texto vai logo depois
         if (legendaSeparada) await enviarGuardando(endereco, { text: legendaSeparada });
         associar(String(telefone).replace(/\D/g, ''), endereco);
         if (endereco !== jid) registrar('Enviada por endereco alternativo', `${jid} atendido em ${endereco}`);
         return { jid: endereco, id: enviada && enviada.key ? enviada.key.id : '', convertido };
      } catch (e) {
         falhas.push(`${endereco}: ${e.message}`);
         registrar('Endereco recusado pelo WhatsApp', `${endereco} - ${e.message}`);
      }
   }
   throw new Error('Nenhum endereco aceitou a mensagem (' + falhas.join(' | ') + ')');
}

async function reagir(telefone, waId, deMim, emoji) {
   if (!conectado || !socket) throw new Error('WhatsApp nao esta conectado');
   const jid = await resolverJid(telefone);
   const destino = candidatosDeEnvio(jid, telefone)[0] || jid;
   await socket.sendMessage(destino, { react: { text: String(emoji || ''), key: { remoteJid: destino, fromMe: !!deMim, id: String(waId) } } });
   return destino;
}

/** Confirmacao de leitura (tracos azuis) para mensagens recebidas */
async function marcarLidas(itens) {
   if (!conectado || !socket || !itens.length) return 0;
   const chaves = itens.filter((i) => i.jid && i.wa_id).map((i) => ({ remoteJid: i.jid, id: String(i.wa_id), fromMe: false }));
   if (chaves.length) await socket.readMessages(chaves);
   return chaves.length;
}

// ============================================
// Recebimento
// ============================================

function textoDaCitada(citada) {
   if (!citada) return '';
   const c = normalizeMessageContent(citada) || {};
   if (c.conversation) return c.conversation;
   if (c.extendedTextMessage?.text) return c.extendedTextMessage.text;
   if (c.imageMessage) return '📷 ' + (c.imageMessage.caption || 'Imagem');
   if (c.audioMessage) return '🎤 Áudio';
   if (c.videoMessage) return '🎬 ' + (c.videoMessage.caption || 'Vídeo');
   if (c.documentMessage) return '📄 ' + (c.documentMessage.fileName || 'Documento');
   if (c.stickerMessage) return 'Figurinha';
   return '';
}

function citacaoDaMensagem(conteudo) {
   const tipo = Object.keys(conteudo).find((k) => conteudo[k] && conteudo[k].contextInfo && conteudo[k].contextInfo.stanzaId);
   if (!tipo) return null;
   const info = conteudo[tipo].contextInfo;
   return { wa_id: String(info.stanzaId), texto: textoDaCitada(info.quotedMessage).substring(0, 500) };
}

function textoDaMensagem(conteudo) {
   return conteudo.conversation
      || conteudo.extendedTextMessage?.text
      || conteudo.imageMessage?.caption
      || conteudo.videoMessage?.caption
      || conteudo.documentMessage?.caption
      || conteudo.documentWithCaptionMessage?.message?.documentMessage?.caption
      || (conteudo.locationMessage ? `📍 Localização: https://maps.google.com/?q=${conteudo.locationMessage.degreesLatitude},${conteudo.locationMessage.degreesLongitude}` : '')
      || (conteudo.contactMessage ? `👤 Contato: ${conteudo.contactMessage.displayName || ''}` : '')
      || conteudo.buttonsResponseMessage?.selectedDisplayText
      || conteudo.listResponseMessage?.title
      || '';
}

const fila = new Map();

async function tratarMensagem(mensagem) {
   const jid = mensagem.key?.remoteJid || '';
   if (!jid || jid.endsWith('@g.us') || jid.endsWith('@newsletter') || jid.endsWith('@broadcast') || jid === 'status@broadcast') return;

   const deMim = !!mensagem.key?.fromMe;
   const waId = String(mensagem.key?.id || '');
   // O que o proprio GLPI enviou ja esta registrado la. O aviso pode chegar antes de o envio
   // devolver o id: espera um pouco antes de concluir que veio do celular.
   if (deMim) {
      if (enviadas.has(waId)) return;
      await new Promise((r) => setTimeout(r, 2500));
      if (enviadas.has(waId)) return;
   }

   const conteudo = normalizeMessageContent(mensagem.message) || {};
   if (conteudo.protocolMessage || conteudo.senderKeyDistributionMessage && Object.keys(conteudo).length === 1) return;

   let telefone = await telefoneDaMensagem(mensagem);
   if (telefone === '') telefone = numeroDoJid(jid);
   associar(telefone, jid);

   if (conteudo.reactionMessage) {
      const r = conteudo.reactionMessage;
      await avisarGlpi({ evento: 'reacao', telefone, de_mim: deMim, wa_id: String(r.key?.id || ''), emoji: String(r.text || '') });
      return;
   }

   let texto = String(textoDaMensagem(conteudo) || '').trim();
   const info = midiaDaMensagem(conteudo);
   if (texto === '' && !info) return;

   let midia = null;
   if (info) {
      try {
         midia = await baixarMidia(mensagem, info);
      } catch (e) {
         registrar('Falha ao baixar midia', `${jid} - ${e.message}`);
         if (texto === '') texto = `[${info.tipo} nao baixado: ${e.message}]`;
      }
   }

   registrar(deMim ? 'Mensagem enviada pelo celular' : 'Mensagem recebida', `${telefone}: ` + (midia ? `[${midia.tipo}] ` : '') + texto.substring(0, 60));

   const dados = {
      evento: 'mensagem',
      telefone,
      jid,
      nome: deMim ? '' : String(mensagem.pushName || ''),
      de_mim: deMim,
      wa_id: waId,
      texto,
      midia,
      citada: citacaoDaMensagem(conteudo),
      data: Number(mensagem.messageTimestamp || 0) || Math.floor(Date.now() / 1000)
   };

   // Por contato, na ordem de chegada
   const anterior = fila.get(jid) || Promise.resolve();
   const atual = anterior.then(() => avisarGlpi(dados)).catch((e) => registrar('Falha ao entregar ao GLPI', e.message));
   fila.set(jid, atual);
   atual.finally(() => { if (fila.get(jid) === atual) fila.delete(jid); });
   await atual;
}

// ============================================
// Conexao
// ============================================

let tentativasConexao = 0;

async function conectar() {
   paradaManual = false;
   try {
      await conectarSessao();
      tentativasConexao = 0;
   } catch (e) {
      tentativasConexao++;
      const espera = Math.min(60, 5 * tentativasConexao);
      registrar('Falha ao preparar a conexao', `${e && e.message ? e.message : e}. Nova tentativa em ${espera}s`);
      setTimeout(() => { if (!paradaManual) conectar(); }, espera * 1000);
   }
}

async function conectarSessao() {
   const { state, saveCreds } = await useMultiFileAuthState(PASTA_AUTH);
   let version;
   try {
      version = (await fetchLatestBaileysVersion()).version;
   } catch (e) {
      registrar('Versao do WhatsApp Web indisponivel', 'usando a padrao da biblioteca');
   }
   versaoWa = version ? version.join('.') : 'padrao';

   socket = makeWASocket({
      version,
      auth: { creds: state.creds, keys: makeCacheableSignalKeyStore(state.keys, registrador) },
      logger: registrador,
      printQRInTerminal: false,
      browser: ['Central de Contatos', 'Chrome', '1.0.0'],
      markOnlineOnConnect: false,
      syncFullHistory: false,
      msgRetryCounterCache: contadorReenvio,
      getMessage: async (chave) => {
         const conteudo = chave && chave.id ? enviadas.get(chave.id) : undefined;
         if (conteudo) registrar('Reenvio pedido pelo aparelho', `${chave.remoteJid || ''} - mensagem cifrada de novo`);
         return conteudo;
      }
   });

   socket.ev.on('connection.update', async (atualizacao) => {
      const { connection, lastDisconnect, qr } = atualizacao;
      if (qr) {
         try {
            ultimoQr = await QRCode.toDataURL(qr, { margin: 1, width: 300 });
            registrar('Novo QR Code gerado');
         } catch (e) {
            ultimoQr = null;
         }
      }
      if (connection === 'open') {
         conectado = true;
         ultimoQr = null;
         const usuario = socket.user || {};
         conexao = { numero: numeroDoJid(usuario.id), nome: usuario.name || usuario.verifiedName || null, desde: Date.now() };
         registrar('WhatsApp conectado', conexao.numero || '');
         avisarGlpi({ evento: 'conexao', conectado: true, numero: conexao.numero, nome: conexao.nome });
      }
      if (connection === 'close') {
         conectado = false;
         const codigo = lastDisconnect?.error?.output?.statusCode;
         if (paradaManual) {
            registrar('Servico parado manualmente');
            return;
         }
         const sessaoInvalida = codigo === DisconnectReason.loggedOut || codigo === DisconnectReason.badSession
            || codigo === DisconnectReason.forbidden || codigo === 401 || codigo === 403;
         if (sessaoInvalida) {
            registrar('Sessao invalida', `Codigo ${codigo}. Gerando novo QR Code`);
            limparAutenticacao();
            conexao = { numero: null, nome: null, desde: null };
            avisarGlpi({ evento: 'conexao', conectado: false, motivo: 'desvinculado' });
            setTimeout(conectar, 2000);
         } else {
            registrar('Conexao perdida', `Codigo ${codigo}. Reconectando em 5s`);
            setTimeout(conectar, 5000);
         }
      }
   });

   socket.ev.on('creds.update', saveCreds);

   // Entregue (3), lida (4) ou reproduzida (5): o GLPI mostra os tracos
   socket.ev.on('messages.update', (atualizacoes) => {
      const itens = [];
      for (const item of atualizacoes || []) {
         const id = item.key && item.key.id;
         const situacao = item.update && item.update.status;
         if (id && item.key.fromMe && situacao) itens.push({ wa_id: String(id), status: Number(situacao) });
      }
      if (itens.length) avisarGlpi({ evento: 'status', itens });
   });

   socket.ev.on('messages.upsert', async (evento) => {
      if (evento.type !== 'notify' && evento.type !== 'append') return;
      for (const mensagem of evento.messages || []) {
         try {
            await tratarMensagem(mensagem);
         } catch (e) {
            registrar('Falha ao tratar mensagem', e.message);
         }
      }
   });
}

async function pararServico() {
   paradaManual = true;
   ultimoQr = null;
   if (socket) {
      try { socket.end(undefined); } catch (e) { /* segue */ }
      try { socket.ws?.close(); } catch (e) { /* segue */ }
   }
   conectado = false;
   socket = null;
}

async function desvincular() {
   try { if (socket) await socket.logout(); } catch (e) { /* segue */ }
   try { socket?.end(undefined); } catch (e) { /* segue */ }
   socket = null;
   conectado = false;
   ultimoQr = null;
   conexao = { numero: null, nome: null, desde: null };
   limparAutenticacao();
   paradaManual = false;
   setTimeout(conectar, 1500);
}

// ============================================
// API local para o GLPI
// ============================================

function corpoDaRequisicao(req) {
   return new Promise((resolve) => {
      let dados = '';
      req.on('data', (parte) => { dados += parte; if (dados.length > 1048576) req.destroy(); });
      req.on('end', () => {
         try { resolve(JSON.parse(dados || '{}')); } catch (e) { resolve({}); }
      });
   });
}

function responder(res, codigo, dados) {
   res.writeHead(codigo);
   res.end(JSON.stringify(dados));
}

const servidor = http.createServer(async (req, res) => {
   res.setHeader('Content-Type', 'application/json; charset=utf-8');
   const origem = req.socket.remoteAddress || '';
   if (!origem.includes('127.0.0.1') && !origem.includes('::1')) return responder(res, 403, { erro: 'Origem nao autorizada' });
   if (TOKEN !== '' && req.headers['x-token-interno'] !== TOKEN) return responder(res, 403, { erro: 'Token invalido' });

   if (req.method === 'GET' && req.url === '/status') {
      return responder(res, 200, {
         conectado, tem_auth: autenticacaoExiste(), tem_qr: !!ultimoQr,
         numero: conexao.numero, nome: conexao.nome, desde: conexao.desde,
         tempo_ms: conexao.desde ? Date.now() - conexao.desde : 0,
         versao_wa: versaoWa, versao_baileys: versaoBaileys, versao_node: process.version
      });
   }
   if (req.method === 'GET' && req.url === '/qr') return responder(res, 200, { qr: ultimoQr });

   if (req.method === 'POST' && req.url === '/enviar') {
      const d = await corpoDaRequisicao(req);
      const texto = String(d.texto || '').trim();
      const midia = d.midia && d.midia.arquivo ? d.midia : null;
      if (!d.telefone || (texto === '' && !midia)) return responder(res, 400, { ok: false, erro: 'telefone e texto (ou midia) sao obrigatorios' });
      try {
         const r = await enviar(d.telefone, texto, midia, d.citar || null);
         registrar(midia ? 'Midia enviada' : 'Mensagem enviada', `${d.telefone} via ${r.jid}`);
         return responder(res, 200, { ok: true, jid: r.jid, wa_id: r.id, convertido: r.convertido });
      } catch (e) {
         registrar('Falha no envio', e.message);
         return responder(res, 500, { ok: false, erro: e.message });
      }
   }

   if (req.method === 'POST' && req.url === '/reagir') {
      const d = await corpoDaRequisicao(req);
      if (!d.telefone || !d.wa_id) return responder(res, 400, { ok: false, erro: 'telefone e wa_id sao obrigatorios' });
      try {
         await reagir(d.telefone, d.wa_id, d.de_mim, d.emoji || '');
         return responder(res, 200, { ok: true });
      } catch (e) {
         return responder(res, 500, { ok: false, erro: e.message });
      }
   }

   if (req.method === 'POST' && req.url === '/ler') {
      const d = await corpoDaRequisicao(req);
      try {
         return responder(res, 200, { ok: true, lidas: await marcarLidas(Array.isArray(d.itens) ? d.itens : []) });
      } catch (e) {
         return responder(res, 500, { ok: false, erro: e.message });
      }
   }

   if (req.method === 'POST' && req.url === '/servico/parar') { await pararServico(); return responder(res, 200, { ok: true }); }
   if (req.method === 'POST' && req.url === '/servico/reiniciar') { await pararServico(); setTimeout(conectar, 1000); return responder(res, 200, { ok: true }); }
   if (req.method === 'POST' && req.url === '/servico/desvincular') { await desvincular(); return responder(res, 200, { ok: true }); }

   return responder(res, 404, { erro: 'Rota nao encontrada' });
});

servidor.listen(PORTA, '127.0.0.1', () => {
   try { fs.mkdirSync(PASTA_AUTH, { recursive: true }); } catch (e) { registrar('Falha ao preparar a pasta da sessao', e.message); }
   registrar('Servidor iniciado', `porta ${PORTA} - sessao em ${PASTA_AUTH}`);
   registrar('Webhook do GLPI', WEBHOOK || 'NAO CONFIGURADO');
   carregarMapa();
   conectar();
});

function encerrar(motivo) {
   registrar('Servidor parado', motivo);
   try { servidor.close(); } catch (e) { /* segue */ }
   process.exit(0);
}

process.on('SIGINT', () => encerrar('SIGINT'));
process.on('SIGTERM', () => encerrar('SIGTERM'));
process.on('SIGHUP', () => encerrar('SIGHUP'));
process.on('uncaughtException', (erro) => { registrar('Erro nao tratado', erro.message); process.exit(1); });
process.on('unhandledRejection', (erro) => registrar('Promise rejeitada', erro && erro.message ? erro.message : String(erro)));
