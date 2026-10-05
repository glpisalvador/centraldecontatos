<?php

/**
 * Plugin Central de Contatos - servidor WhatsApp (Node.js) administrado pelo GLPI
 *
 * Tudo fica em files/_plugins/centraldecontatos, onde o GLPI já tem escrita:
 *   node/   Node.js portátil (de nodejs.org, SHA-256 conferido)
 *   app/    servidor.js + package.json do plugin e node_modules instalados pelo npm
 *   auth/   sessão do aparelho pareado
 *   run/    pid, log, scripts, vigia e tarefas em segundo plano
 *   midia/  imagens, áudios, vídeos e documentos trocados (AAAAMM/arquivo)
 * O vigia é uma linha no crontab do usuário do servidor web: religa o processo em até 1 minuto.
 */
class PluginCentraldecontatosWhatsapp extends CommonGLPI
{
    public const PACOTES = ['@whiskeysockets/baileys', 'pino', 'qrcode'];
    public const NODE_MINIMO = 20;
    public const MARCA_CRON = '# centraldecontatos-vigia';

    public static function getTypeName($nb = 0): string
    {
        return 'Servidor WhatsApp';
    }

    public static function canView(): bool
    {
        return PluginCentraldecontatosConfig::ehAdmin();
    }

    // =====================================================================
    // Pastas
    // =====================================================================

    public static function base(): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/centraldecontatos';
    }

    public static function pastaNode(): string
    {
        return self::base() . '/node';
    }

    public static function pastaApp(): string
    {
        return self::base() . '/app';
    }

    public static function pastaAuth(): string
    {
        return self::base() . '/auth';
    }

    public static function pastaRun(): string
    {
        return self::base() . '/run';
    }

    public static function pastaMidia(): string
    {
        return self::base() . '/midia';
    }

    public static function pastaEnvios(): string
    {
        return self::base() . '/envios';
    }

    /** Caminho absoluto de uma mídia a partir do relativo gravado no banco (recusa o que estiver fora da pasta) */
    public static function caminhoMidia(string $relativo): ?string
    {
        if ($relativo === '' || !preg_match('#^[0-9]{6}/[A-Za-z0-9_.-]+$#', $relativo)) {
            return null;
        }
        $caminho = self::pastaMidia() . '/' . $relativo;
        return is_file($caminho) ? $caminho : null;
    }

    public static function prepararPastas(): ?string
    {
        foreach ([self::base(), self::pastaApp(), self::pastaAuth(), self::pastaRun(), self::pastaMidia(), self::pastaEnvios()] as $pasta) {
            if (!is_dir($pasta)) {
                @mkdir($pasta, 0770, true);
            }
            if (!is_dir($pasta) || !is_writable($pasta)) {
                return sprintf('A pasta %s não pode ser criada ou não aceita escrita pelo usuário %s.', $pasta, self::usuarioPhp());
            }
        }
        if (!is_file(self::base() . '/.htaccess')) {
            @file_put_contents(self::base() . '/.htaccess', "Require all denied\nOrder Deny,Allow\nDeny from all\n");
        }
        return null;
    }

    // =====================================================================
    // Comandos
    // =====================================================================

    public static function usuarioPhp(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $d = @posix_getpwuid(posix_geteuid());
            if (!empty($d['name'])) {
                return (string) $d['name'];
            }
        }
        return (string) get_current_user();
    }

    public static function shellDisponivel(): bool
    {
        if (!function_exists('shell_exec')) {
            return false;
        }
        return !in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
    }

    public static function executar(string $comando): string
    {
        return self::shellDisponivel() ? (string) @shell_exec($comando) : '';
    }

    public static function comandoExiste(string $nome): bool
    {
        return trim(self::executar('command -v ' . escapeshellarg($nome) . ' 2>/dev/null')) !== '';
    }

    public static function aspasShell(string $valor): string
    {
        return "'" . str_replace("'", "'\\''", $valor) . "'";
    }

    // =====================================================================
    // Node.js portátil
    // =====================================================================

    public static function arquitetura(): string
    {
        $m = strtolower(php_uname('m'));
        return in_array($m, ['x86_64', 'amd64'], true) ? 'x64' : (in_array($m, ['aarch64', 'arm64'], true) ? 'arm64' : '');
    }

    public static function binNode(): string
    {
        $bin = self::pastaNode() . '/bin/node';
        return (is_file($bin) && is_executable($bin)) ? $bin : '';
    }

    public static function versaoNode(): string
    {
        $bin = self::binNode();
        return $bin === '' ? '' : trim(self::executar(escapeshellarg($bin) . ' --version 2>/dev/null'));
    }

    public static function nodeValido(): bool
    {
        $v = ltrim(self::versaoNode(), 'v');
        return $v !== '' && (int) explode('.', $v)[0] >= self::NODE_MINIMO;
    }

    private static function baixarTexto(string $url, int $timeout = 20): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => $timeout, CURLOPT_USERAGENT => 'GLPI-centraldecontatos']);
        $corpo = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($corpo !== false && $http === 200) ? (string) $corpo : null;
    }

    /** Última LTS do Node para esta arquitetura, com a soma SHA-256 oficial */
    public static function ultimaLts(): array
    {
        $arq = self::arquitetura();
        if ($arq === '') {
            return ['erro' => 'Arquitetura ' . php_uname('m') . ' não suportada (apenas x86_64 e arm64).'];
        }
        $indice = json_decode((string) self::baixarTexto('https://nodejs.org/dist/index.json'), true);
        if (!is_array($indice)) {
            return ['erro' => 'Não foi possível consultar nodejs.org. Verifique o acesso à internet do servidor.'];
        }
        foreach ($indice as $r) {
            if (empty($r['lts']) || !in_array('linux-' . $arq, (array) ($r['files'] ?? []), true)) {
                continue;
            }
            $versao = (string) $r['version'];
            $formato = self::comandoExiste('xz') ? 'tar.xz' : 'tar.gz';
            $pasta = 'node-' . $versao . '-linux-' . $arq;
            $arquivo = $pasta . '.' . $formato;
            $somas = (string) self::baixarTexto('https://nodejs.org/dist/' . $versao . '/SHASUMS256.txt');
            if (!preg_match('/^([a-f0-9]{64})\s+' . preg_quote($arquivo, '/') . '$/m', $somas, $m)) {
                return ['erro' => 'Soma SHA-256 do Node.js ' . $versao . ' não encontrada.'];
            }
            return ['versao' => $versao, 'lts' => (string) $r['lts'], 'pasta' => $pasta, 'arquivo' => $arquivo, 'url' => 'https://nodejs.org/dist/' . $versao . '/' . $arquivo, 'sha256' => $m[1]];
        }
        return ['erro' => 'Nenhuma LTS do Node.js para linux-' . $arq . '.'];
    }

    public static function instalarNode(): ?string
    {
        if ($erro = self::verificarBasico()) {
            return $erro;
        }
        $lts = self::ultimaLts();
        if (isset($lts['erro'])) {
            return $lts['erro'];
        }
        $script = 'cd ' . escapeshellarg(self::base()) . "\n"
            . "rm -rf node.baixando\nmkdir node.baixando\n"
            . 'echo "Baixando Node.js ' . $lts['versao'] . ' (' . $lts['lts'] . ')..."' . "\n"
            . 'curl -fsSL --retry 3 --connect-timeout 15 -o ' . escapeshellarg('node.baixando/' . $lts['arquivo']) . ' ' . escapeshellarg($lts['url']) . "\n"
            . 'echo "Conferindo a soma SHA-256 oficial..."' . "\n"
            . 'echo ' . escapeshellarg($lts['sha256'] . '  node.baixando/' . $lts['arquivo']) . " | sha256sum -c -\n"
            . 'echo "Extraindo..."' . "\n"
            . 'tar -xf ' . escapeshellarg('node.baixando/' . $lts['arquivo']) . " -C node.baixando\n"
            . "rm -rf node.antigo\nif [ -d node ]; then mv node node.antigo; fi\n"
            . 'mv ' . escapeshellarg('node.baixando/' . $lts['pasta']) . " node\n"
            . "rm -rf node.baixando node.antigo\n"
            . 'echo "Instalado: $(./node/bin/node --version)"' . "\n";
        return self::iniciarTarefa('node', $script);
    }

    // =====================================================================
    // Aplicação Node e dependências
    // =====================================================================

    public static function arquivosFonte(): array
    {
        $fonte = dirname(__DIR__) . '/servidor';
        return ['servidor.js' => $fonte . '/servidor.js', 'package.json' => $fonte . '/package.json'];
    }

    public static function appSincronizado(): bool
    {
        foreach (self::arquivosFonte() as $nome => $origem) {
            $destino = self::pastaApp() . '/' . $nome;
            if (!is_file($destino) || @md5_file($destino) !== @md5_file($origem)) {
                return false;
            }
        }
        return true;
    }

    public static function sincronizarApp(): ?string
    {
        foreach (self::arquivosFonte() as $nome => $origem) {
            if (!is_file($origem)) {
                return 'Arquivo ' . $nome . ' não encontrado na pasta servidor do plugin.';
            }
            if (!@copy($origem, self::pastaApp() . '/' . $nome)) {
                return 'Não foi possível copiar ' . $nome . ' para ' . self::pastaApp() . '.';
            }
        }
        return null;
    }

    public static function situacaoDependencias(): array
    {
        $modulos = self::pastaApp() . '/node_modules';
        $pacotes = [];
        $faltando = [];
        foreach (self::PACOTES as $p) {
            $manifesto = $modulos . '/' . $p . '/package.json';
            $presente = is_file($manifesto);
            $versao = $presente ? (string) (json_decode((string) @file_get_contents($manifesto), true)['version'] ?? '') : '';
            if (!$presente) {
                $faltando[] = $p;
            }
            $pacotes[] = ['nome' => $p, 'presente' => $presente, 'versao' => $versao];
        }
        $fonte = self::arquivosFonte()['package.json'];
        $atual = is_file($fonte) ? (string) md5_file($fonte) : '';
        $instalado = (string) PluginCentraldecontatosConfig::getConfig('wa_dependencias_hash', '');
        return ['completo' => !$faltando, 'atualizado' => !$faltando && $instalado !== '' && $instalado === $atual, 'pacotes' => $pacotes, 'faltando' => $faltando];
    }

    public static function instalarDependencias(): ?string
    {
        if ($erro = self::verificarBasico()) {
            return $erro;
        }
        if (!self::nodeValido()) {
            return 'Instale o Node.js antes das dependências.';
        }
        if ($erro = self::sincronizarApp()) {
            return $erro;
        }
        $app = self::pastaApp();
        $script = 'export PATH=' . escapeshellarg(self::pastaNode() . '/bin') . ':"$PATH"' . "\n"
            . 'export HOME=' . escapeshellarg($app) . "\n"
            . 'export npm_config_cache=' . escapeshellarg($app . '/.npm-cache') . "\n"
            . "export npm_config_update_notifier=false npm_config_fund=false npm_config_audit=false npm_config_loglevel=warn\n"
            . 'cd ' . escapeshellarg($app) . "\n"
            . 'echo "Node $(node --version) - npm $(npm --version)"' . "\n"
            . 'echo "Instalando pacotes (pode levar alguns minutos)..."' . "\n"
            . "npm install --omit=dev --no-audit --no-fund\n"
            . 'echo "Dependências instaladas."' . "\n";
        return self::iniciarTarefa('dependencias', $script);
    }

    public static function confirmarDependencias(): void
    {
        $fonte = self::arquivosFonte()['package.json'];
        if (is_file($fonte) && self::situacaoDependencias()['completo']) {
            PluginCentraldecontatosConfig::setConfig('wa_dependencias_hash', (string) md5_file($fonte));
        }
    }

    /**
     * Cópia única do Node portátil e das dependências já baixados pelo plugin WhatsApp Empresa
     * (mesmas versões de pacote): evita baixar de novo. A pasta dele não é alterada.
     */
    public static function importarNodeExistente(): string
    {
        $origem = GLPI_PLUGIN_DOC_DIR . '/whatsappempresa';
        if (self::prepararPastas() !== null || !self::shellDisponivel()) {
            return '';
        }
        $feito = [];
        if (!self::nodeValido() && is_file($origem . '/node/bin/node')) {
            self::executar('cp -a ' . escapeshellarg($origem . '/node') . ' ' . escapeshellarg(self::pastaNode() . '.copiando') . ' && mv ' . escapeshellarg(self::pastaNode() . '.copiando') . ' ' . escapeshellarg(self::pastaNode()) . ' 2>&1');
            if (self::nodeValido()) {
                $feito[] = 'Node.js ' . self::versaoNode();
            }
        }
        $depsOrigem = $origem . '/app/node_modules';
        $baileys = json_decode((string) @file_get_contents($depsOrigem . '/@whiskeysockets/baileys/package.json'), true);
        $nosso = json_decode((string) @file_get_contents(self::arquivosFonte()['package.json']), true);
        if (!self::situacaoDependencias()['completo'] && is_array($baileys) && ($baileys['version'] ?? '') === ($nosso['dependencies']['@whiskeysockets/baileys'] ?? '-')) {
            self::sincronizarApp();
            self::executar('cp -a ' . escapeshellarg($depsOrigem) . ' ' . escapeshellarg(self::pastaApp() . '/node_modules') . ' 2>&1');
            if (self::situacaoDependencias()['completo']) {
                self::confirmarDependencias();
                $feito[] = 'dependências (Baileys ' . $baileys['version'] . ')';
            }
        }
        return implode(' e ', $feito);
    }

    // =====================================================================
    // Tarefas em segundo plano
    // =====================================================================

    public static function iniciarTarefa(string $nome, string $corpo): ?string
    {
        if (!in_array($nome, ['node', 'dependencias'], true)) {
            return 'Tarefa inválida.';
        }
        if (self::situacaoTarefa($nome)['rodando']) {
            return 'Esta etapa já está em andamento.';
        }
        $run = self::pastaRun();
        [$script, $log, $status, $pid] = [$run . "/tarefa-$nome.sh", $run . "/tarefa-$nome.log", $run . "/tarefa-$nome.status", $run . "/tarefa-$nome.pid"];
        @unlink($status);
        @file_put_contents($log, '');
        $conteudo = "#!/bin/sh\n# Gerado pelo plugin centraldecontatos - tarefa $nome\n"
            . 'echo $$ > ' . escapeshellarg($pid) . "\n"
            . "(\nset -e\n" . $corpo . ")\n"
            . 'echo $? > ' . escapeshellarg($status) . "\n";
        if (@file_put_contents($script, $conteudo) === false) {
            return 'Não foi possível gravar o script da tarefa em ' . $run . '.';
        }
        @unlink($pid);
        self::executar('cd ' . escapeshellarg($run) . ' && setsid sh ' . escapeshellarg($script) . ' > ' . escapeshellarg($log) . ' 2>&1 < /dev/null &');
        for ($i = 0; $i < 20 && !is_file($pid); $i++) {
            usleep(100000);
        }
        return null;
    }

    public static function situacaoTarefa(string $nome): array
    {
        $run = self::pastaRun();
        $status = $run . "/tarefa-$nome.status";
        $log = $run . "/tarefa-$nome.log";
        $pid = (int) @file_get_contents($run . "/tarefa-$nome.pid");
        $terminou = is_file($status);
        $codigo = $terminou ? (int) trim((string) @file_get_contents($status)) : null;
        $rodando = !$terminou && $pid > 1 && self::processoVivo($pid);
        if ($nome === 'dependencias' && $terminou && $codigo === 0) {
            self::confirmarDependencias();
        }
        return [
            'existe'   => is_file($log),
            'rodando'  => $rodando,
            'terminou' => $terminou,
            'sucesso'  => $terminou && $codigo === 0,
            'codigo'   => $codigo,
            'log'      => mb_substr(trim(is_file($log) ? (string) @file_get_contents($log) : ''), -6000),
        ];
    }

    // =====================================================================
    // Processo e vigia
    // =====================================================================

    public static function processoVivo(int $pid): bool
    {
        if ($pid <= 1) {
            return false;
        }
        return function_exists('posix_kill') ? @posix_kill($pid, 0) : is_dir('/proc/' . $pid);
    }

    public static function pid(): int
    {
        return (int) trim((string) @file_get_contents(self::pastaRun() . '/servidor.pid'));
    }

    public static function rodando(): bool
    {
        return self::processoVivo(self::pid());
    }

    public static function porta(): int
    {
        $p = (int) PluginCentraldecontatosConfig::getConfig('wa_porta');
        return $p >= 1024 && $p <= 65535 ? $p : 3470;
    }

    public static function token(): string
    {
        $t = (string) PluginCentraldecontatosConfig::getConfig('wa_token');
        if (strlen($t) < 32) {
            $t = bin2hex(random_bytes(24));
            PluginCentraldecontatosConfig::setConfig('wa_token', $t);
        }
        return $t;
    }

    public static function urlWebhook(): string
    {
        global $CFG_GLPI;
        $manual = trim((string) PluginCentraldecontatosConfig::getConfig('wa_webhook_url'));
        if ($manual !== '') {
            return rtrim($manual, '/');
        }
        return rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') . '/plugins/centraldecontatos/front/webhook.php';
    }

    public static function gravarScripts(): ?string
    {
        $run = self::pastaRun();
        $ambiente = [
            'CDC_PORTA'        => (string) self::porta(),
            'CDC_TOKEN'        => self::token(),
            'CDC_WEBHOOK'      => self::urlWebhook(),
            'CDC_AUTH'         => self::pastaAuth(),
            'CDC_MIDIA'        => self::pastaMidia(),
            'CDC_MIDIA_MAX_MB' => (string) max(1, (int) PluginCentraldecontatosConfig::getConfig('wa_midia_max_mb')),
            'CDC_TLS_INSEGURO' => PluginCentraldecontatosConfig::ligado('wa_tls_inseguro') ? '1' : '0',
            'HOME'             => $run,
        ];
        $linhas = [];
        foreach ($ambiente as $k => $v) {
            $linhas[] = $k . '=' . self::aspasShell($v);
        }
        $iniciar = "#!/bin/sh\n# Gerado pelo plugin centraldecontatos: inicia o servidor WhatsApp\n"
            . 'BASE=' . self::aspasShell(self::base()) . "\n"
            . "RUN=\"\$BASE/run\"\nLOG=\"\$RUN/servidor.log\"\nPID_ARQ=\"\$RUN/servidor.pid\"\n"
            . "exec 9>\"\$RUN/iniciar.lock\"\n"
            . "if command -v flock >/dev/null 2>&1; then flock -n 9 || exit 0; fi\n"
            . "if [ -f \"\$PID_ARQ\" ] && kill -0 \"\$(cat \"\$PID_ARQ\")\" 2>/dev/null; then exit 0; fi\n"
            . "if [ -f \"\$LOG\" ] && [ \"\$(wc -c < \"\$LOG\")\" -gt 5242880 ]; then tail -c 1048576 \"\$LOG\" > \"\$LOG.tmp\" && mv \"\$LOG.tmp\" \"\$LOG\"; fi\n"
            . "cd \"\$BASE/app\" || exit 1\n"
            . "set -a\n. \"\$RUN/ambiente.env\"\nset +a\n"
            . "setsid \"\$BASE/node/bin/node\" servidor.js --centraldecontatos >> \"\$LOG\" 2>&1 < /dev/null &\n"
            . "echo \$! > \"\$PID_ARQ\"\n";
        $vigia = "#!/bin/sh\n# Gerado pelo plugin centraldecontatos: religa o servidor WhatsApp se cair\n"
            . 'RUN=' . self::aspasShell($run) . "\n"
            . "[ -f \"\$RUN/ativo\" ] && [ -f \"\$RUN/iniciar.sh\" ] && /bin/sh \"\$RUN/iniciar.sh\"\nexit 0\n";
        $ok = @file_put_contents($run . '/ambiente.env', implode("\n", $linhas) . "\n") !== false
            && @file_put_contents($run . '/iniciar.sh', $iniciar) !== false
            && @file_put_contents($run . '/vigia.sh', $vigia) !== false;
        @chmod($run . '/ambiente.env', 0600);
        return $ok ? null : 'Não foi possível gravar os scripts em ' . $run . '.';
    }

    public static function linhaCron(): string
    {
        return '* * * * * /bin/sh ' . escapeshellarg(self::pastaRun() . '/vigia.sh') . ' >/dev/null 2>&1 ' . self::MARCA_CRON;
    }

    public static function vigiaInstalado(): bool
    {
        return str_contains(self::executar('crontab -l 2>/dev/null'), self::MARCA_CRON);
    }

    public static function ajustarCron(bool $instalar): ?string
    {
        if (!self::shellDisponivel()) {
            return 'A execução de comandos está desabilitada no PHP.';
        }
        if (!self::comandoExiste('crontab')) {
            return 'O comando crontab não existe neste servidor.';
        }
        $linhas = [];
        foreach (explode("\n", self::executar('crontab -l 2>/dev/null')) as $l) {
            if (trim($l) !== '' && !str_contains($l, self::MARCA_CRON)) {
                $linhas[] = $l;
            }
        }
        if ($instalar) {
            if ($erro = self::prepararPastas() ?? self::gravarScripts()) {
                return $erro;
            }
            $linhas[] = self::linhaCron();
        }
        $tmp = self::pastaRun() . '/crontab.tmp';
        @file_put_contents($tmp, implode("\n", $linhas) . "\n");
        $saida = trim(self::executar('crontab ' . escapeshellarg($tmp) . ' 2>&1'));
        @unlink($tmp);
        if (self::vigiaInstalado() !== $instalar) {
            return 'O crontab não aceitou a alteração' . ($saida !== '' ? ': ' . $saida : '.');
        }
        return null;
    }

    public static function verificarBasico(): ?string
    {
        if (!self::shellDisponivel()) {
            return 'A função shell_exec está em disable_functions no php.ini. O servidor WhatsApp precisa dela.';
        }
        return self::prepararPastas();
    }

    public static function iniciar(): ?string
    {
        if ($erro = self::verificarBasico()) {
            return $erro;
        }
        if (!self::nodeValido()) {
            return 'O Node.js ainda não foi instalado (etapa "Node.js").';
        }
        if (!self::appSincronizado() && ($erro = self::sincronizarApp())) {
            return $erro;
        }
        $deps = self::situacaoDependencias();
        if (!$deps['completo']) {
            return 'Faltam dependências (' . implode(', ', $deps['faltando']) . '). Use a etapa "Dependências".';
        }
        if ($erro = self::gravarScripts()) {
            return $erro;
        }
        @touch(self::pastaRun() . '/ativo');
        self::executar('/bin/sh ' . escapeshellarg(self::pastaRun() . '/iniciar.sh') . ' > /dev/null 2>&1');
        return null;
    }

    public static function parar(): void
    {
        @unlink(self::pastaRun() . '/ativo');
        $pid = self::pid();
        if (self::processoVivo($pid)) {
            function_exists('posix_kill') ? @posix_kill($pid, 15) : self::executar('kill -TERM ' . $pid . ' 2>/dev/null');
            for ($i = 0; $i < 20 && self::processoVivo($pid); $i++) {
                usleep(250000);
            }
            if (self::processoVivo($pid)) {
                self::executar('kill -KILL ' . $pid . ' 2>/dev/null');
            }
        }
        @unlink(self::pastaRun() . '/servidor.pid');
        self::executar('pkill -f -- ' . escapeshellarg(self::pastaNode() . '/bin/node servidor.js --centraldecontatos') . ' 2>/dev/null');
    }

    public static function deveEstarLigado(): bool
    {
        return is_file(self::pastaRun() . '/ativo');
    }

    /** Religa se deveria estar ligado e não está (ação automática) */
    public static function garantirLigado(): bool
    {
        if (!self::deveEstarLigado() || self::rodando()) {
            return false;
        }
        return self::iniciar() === null;
    }

    /** Desinstalação: desliga e tira o vigia (Node, dependências, sessão e mídias ficam) */
    public static function desligarTudo(): void
    {
        self::parar();
        if (self::shellDisponivel() && self::comandoExiste('crontab')) {
            self::ajustarCron(false);
        }
    }

    public static function logServidor(int $linhas = 120): string
    {
        $arq = self::pastaRun() . '/servidor.log';
        $c = is_file($arq) ? @file($arq, FILE_IGNORE_NEW_LINES) : false;
        return is_array($c) ? implode("\n", array_slice($c, -$linhas)) : '';
    }

    // =====================================================================
    // Comunicação com o Node
    // =====================================================================

    public static function requisitar(string $metodo, string $rota, array $dados = [], int $timeout = 8): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'http' => 0, 'dados' => null];
        }
        $ch = curl_init('http://127.0.0.1:' . self::porta() . $rota);
        $op = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Token-Interno: ' . self::token()],
        ];
        if ($dados) {
            $op[CURLOPT_POSTFIELDS] = json_encode($dados, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $op);
        $resp = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erro = curl_errno($ch);
        curl_close($ch);
        return ['ok' => $erro === 0 && $http >= 200 && $http < 300, 'http' => $http, 'dados' => json_decode((string) $resp, true)];
    }

    /** ligado (processo respondendo), conectado (WhatsApp aberto) e os dados do serviço */
    public static function status(): array
    {
        $r = self::requisitar('GET', '/status', [], 4);
        $s = $r['ok'] && is_array($r['dados']) ? $r['dados'] : [];
        if (!empty($s['numero']) && (string) PluginCentraldecontatosConfig::getConfig('wa_numero') !== (string) $s['numero']) {
            PluginCentraldecontatosConfig::setConfig('wa_numero', (string) $s['numero']);
        }
        return ['ligado' => $r['ok'], 'conectado' => !empty($s['conectado']), 'servico' => $s];
    }

    public static function conectado(): bool
    {
        static $cache = null;
        return $cache ??= self::status()['conectado'];
    }

    public static function qrcode(): ?string
    {
        $r = self::requisitar('GET', '/qr', [], 5);
        return ($r['ok'] && !empty($r['dados']['qr'])) ? (string) $r['dados']['qr'] : null;
    }

    /** Envia texto e/ou mídia; devolve ok, erro, jid, wa_id e o arquivo convertido (áudio gravado) */
    public static function enviar(string $telefone, string $texto, ?array $midia = null, ?array $citar = null): array
    {
        $corpo = ['telefone' => $telefone, 'texto' => $texto];
        if ($midia) {
            $corpo['midia'] = ['tipo' => (string) $midia['tipo'], 'arquivo' => (string) $midia['arquivo'], 'mime' => (string) $midia['mime'], 'nome' => (string) ($midia['nome'] ?? '')];
        }
        if ($citar) {
            $corpo['citar'] = $citar;
        }
        $r = self::requisitar('POST', '/enviar', $corpo, $midia ? 90 : 25);
        if (!$r['ok']) {
            return ['ok' => false, 'erro' => (string) ($r['dados']['erro'] ?? ($r['http'] === 0 ? 'Servidor WhatsApp desligado' : 'Servidor WhatsApp indisponível'))];
        }
        return ['ok' => true, 'erro' => null, 'jid' => (string) ($r['dados']['jid'] ?? ''), 'wa_id' => (string) ($r['dados']['wa_id'] ?? ''), 'convertido' => $r['dados']['convertido'] ?? null];
    }

    public static function reagir(string $telefone, string $waId, bool $deMim, string $emoji): array
    {
        $r = self::requisitar('POST', '/reagir', ['telefone' => $telefone, 'wa_id' => $waId, 'de_mim' => $deMim, 'emoji' => $emoji], 20);
        return ['ok' => $r['ok'], 'erro' => $r['ok'] ? null : (string) ($r['dados']['erro'] ?? 'Servidor WhatsApp indisponível')];
    }

    /** Confirma leitura ao cliente; $itens = [['jid' => ..., 'wa_id' => ...]] */
    public static function marcarLidas(array $itens): void
    {
        if ($itens) {
            self::requisitar('POST', '/ler', ['itens' => array_values($itens)], 8);
        }
    }

    // =====================================================================
    // Etapas da tela do servidor
    // =====================================================================

    public static function testarWebhook(int $timeout = 10): array
    {
        $url = self::urlWebhook();
        if ($url === '' || !str_starts_with($url, 'http')) {
            return ['ok' => false, 'url' => $url, 'erro' => 'URL do webhook não definida. Preencha a URL do GLPI em Configurar > Geral ou o campo na configuração do plugin.'];
        }
        $ch = curl_init($url);
        $op = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Token-Interno: ' . self::token()],
            CURLOPT_POSTFIELDS => json_encode(['evento' => 'teste', 'token' => self::token()]),
        ];
        if (PluginCentraldecontatosConfig::ligado('wa_tls_inseguro')) {
            $op[CURLOPT_SSL_VERIFYPEER] = false;
            $op[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $op);
        $resp = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erroCurl = curl_error($ch);
        curl_close($ch);
        $d = json_decode((string) $resp, true);
        $erro = match (true) {
            $erroCurl !== '' => 'Conexão falhou: ' . $erroCurl . '.',
            $http === 403    => 'O webhook respondeu 403 (token interno divergente).',
            $http !== 200    => 'O webhook respondeu HTTP ' . $http . '. Confira a URL.',
            empty($d['ok'])  => 'Resposta inesperada: o GLPI pode ter devolvido uma página de login ou de erro.',
            default          => '',
        };
        return ['ok' => $erro === '', 'url' => $url, 'erro' => $erro];
    }

    /** Cada etapa: chave, rótulo, estado (ok | pendente | erro | andamento), detalhe e ações */
    public static function etapas(bool $testarWebhook = false): array
    {
        $shell = self::shellDisponivel();
        $etapas = [[
            'chave' => 'comandos', 'rotulo' => 'Execução de comandos pelo PHP', 'estado' => $shell ? 'ok' : 'erro',
            'detalhe' => $shell ? 'Liberada para o usuário ' . self::usuarioPhp() . '.' : 'A função shell_exec está em disable_functions no php.ini.', 'acoes' => [],
        ]];
        $erroPasta = $shell ? self::prepararPastas() : 'Depende da execução de comandos.';
        $etapas[] = ['chave' => 'pasta', 'rotulo' => 'Pasta de trabalho', 'estado' => $erroPasta === null ? 'ok' : 'erro', 'detalhe' => $erroPasta ?? self::base(), 'acoes' => []];

        $tn = self::situacaoTarefa('node');
        $versao = self::versaoNode();
        $etapas[] = [
            'chave' => 'node', 'rotulo' => 'Node.js', 'estado' => $tn['rodando'] ? 'andamento' : (self::nodeValido() ? 'ok' : 'pendente'),
            'detalhe' => $tn['rodando'] ? 'Download em andamento...' : ($versao !== '' ? 'Versão ' . $versao . ' em ' . self::pastaNode() . '.' : 'Não instalado. Será baixada a LTS oficial de nodejs.org (cerca de 30 MB).'),
            'acoes' => [self::nodeValido() ? 'node_atualizar' : 'node_instalar'], 'tarefa' => 'node',
        ];
        $deps = self::situacaoDependencias();
        $td = self::situacaoTarefa('dependencias');
        $resumo = array_map(fn($p) => $p['nome'] . ' ' . ($p['presente'] ? ($p['versao'] ?: 'ok') : 'ausente'), $deps['pacotes']);
        $etapas[] = [
            'chave' => 'dependencias', 'rotulo' => 'Dependências do servidor', 'estado' => $td['rodando'] ? 'andamento' : ($deps['atualizado'] ? 'ok' : 'pendente'),
            'detalhe' => $td['rodando'] ? 'Instalação em andamento...' : implode(' | ', $resumo) . ($deps['completo'] && !$deps['atualizado'] ? ' (a versão do plugin pede atualização)' : ''),
            'acoes' => ['dependencias_instalar'], 'tarefa' => 'dependencias',
        ];
        $vigia = $shell && self::vigiaInstalado();
        $etapas[] = [
            'chave' => 'vigia', 'rotulo' => 'Vigia (religar automaticamente)', 'estado' => $vigia ? 'ok' : 'pendente',
            'detalhe' => $vigia ? 'Ativo no crontab de ' . self::usuarioPhp() . ': o servidor volta sozinho em até 1 minuto.' : 'Inativo: se o processo cair, só volta pela tela ou pela ação automática (a cada 5 min).',
            'acoes' => [$vigia ? 'vigia_desativar' : 'vigia_ativar'],
        ];
        if ($testarWebhook) {
            $w = self::testarWebhook(8);
            $etapas[] = ['chave' => 'webhook', 'rotulo' => 'Retorno do servidor para o GLPI (webhook)', 'estado' => $w['ok'] ? 'ok' : 'erro', 'detalhe' => $w['ok'] ? 'Respondendo em ' . $w['url'] : $w['erro'] . ' URL: ' . $w['url'], 'acoes' => ['webhook_testar']];
        } else {
            $etapas[] = ['chave' => 'webhook', 'rotulo' => 'Retorno do servidor para o GLPI (webhook)', 'estado' => 'pendente', 'detalhe' => 'Não testado agora. URL: ' . self::urlWebhook(), 'acoes' => ['webhook_testar']];
        }
        return $etapas;
    }

    // =====================================================================
    // Ação automática
    // =====================================================================

    public static function cronInfo($name): array
    {
        return ['description' => 'Central de Contatos: mantém o servidor WhatsApp ligado e apaga envios temporários antigos'];
    }

    public static function cronCentraldecontatosWhatsapp($task): int
    {
        $religou = self::garantirLigado();
        // Partes de upload abandonadas
        foreach ((array) glob(self::pastaEnvios() . '/*') as $arq) {
            if (is_file($arq) && filemtime($arq) < time() - 86400) {
                @unlink($arq);
            }
        }
        if ($task && $religou) {
            $task->log('Servidor WhatsApp religado.');
        }
        return $religou ? 1 : 0;
    }
}
