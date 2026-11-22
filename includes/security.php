<?php
/**
 * ============================================================
 *  SEGURANÇA
 * ============================================================
 *  - Cabeçalhos de proteção (clickjacking, sniffing, etc.)
 *  - Forçar HTTPS (opcional, em Admin > Segurança)
 *  - Sessão do admin presa ao navegador, com tempo de expiração
 *    e botão "desconectar todos os dispositivos"
 *  - Limite de tentativas de login por IP e por usuário
 *  - Registro de todos os acessos ao painel
 * ============================================================
 */
const ADMIN_IDLE_SECONDS = 4 * 3600;   // desloga após 4h sem uso
const LOGIN_MAX_FAILS_IP = 5;          // por IP...
const LOGIN_MAX_FAILS_USER = 10;       // ...e por usuário
const LOGIN_WINDOW_MIN = 15;           // em 15 minutos

function security_boot(): void {
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    firewall();
    if (setting('force_https', '') === '1' && !is_https() && !empty($_SERVER['HTTP_HOST'])) {
        header('Location: https://' . $_SERVER['HTTP_HOST'] . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }
    security_headers();
    admin_session_check();
}

function security_headers(): void {
    if (headers_sent()) return;
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header("Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; object-src 'none'");
    header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
    if (is_https() && setting('force_https', '') === '1') header('Strict-Transport-Security: max-age=31536000');
}

/* ============================================================
 *  IP REAL DO VISITANTE (com suporte a Cloudflare)
 * ============================================================ */
function cloudflare_ranges(): array {
    return ['173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
            '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32',
            '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32'];
}
function ip_in_cidr(string $ip, string $cidr): bool {
    if (!str_contains($cidr, '/')) return $ip === $cidr;
    [$net, $bits] = explode('/', $cidr, 2);
    $a = @inet_pton($ip); $b = @inet_pton($net);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;
    $bits = (int)$bits; $bytes = intdiv($bits, 8); $rem = $bits % 8;
    if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) return false;
    if ($rem === 0) return true;
    $mask = chr((0xff << (8 - $rem)) & 0xff);
    return (($a[$bytes] & $mask) === ($b[$bytes] & $mask));
}
function ip_in_list(string $ip, array $list): bool {
    foreach ($list as $c) { $c = trim($c); if ($c !== '' && ip_in_cidr($ip, $c)) return true; }
    return false;
}
/** O IP de verdade. Só confia no cabeçalho do Cloudflare se a conexão vier mesmo do Cloudflare (não dá para falsificar). */
function client_ip(): string {
    static $ip = null;
    if ($ip !== null) return $ip;
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $cf = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) && ip_in_list($ip, cloudflare_ranges())) $ip = $cf;
    return $ip;
}

/* ============================================================
 *  LIMITADOR DE TAXA (contador por IP/ação, em arquivos)
 * ============================================================ */
function rl_file(string $key): string {
    $dir = DATA_DIR . '/rl';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (mt_rand(1, 200) === 1) rl_gc($dir);
    return $dir . '/' . sha1($key) . '.json';
}
function rl_gc(string $dir): void {
    foreach ((array)glob($dir . '/*.json') as $f) if (@filemtime($f) < time() - 2 * 86400) @unlink($f);
}
/** Registra um acesso e diz se ainda está dentro do limite ($max em $window segundos) */
function rl_hit(string $key, int $max, int $window): bool {
    // Caminho rápido: memória (APCu), quando a hospedagem tem
    if (function_exists('apcu_enabled') && apcu_enabled()) {
        $k = 'sb_rl_' . sha1($key);
        $hits = array_values(array_filter((array)apcu_fetch($k), fn($t) => $t > time() - $window));
        $ok = count($hits) < $max;
        if ($ok) { $hits[] = time(); apcu_store($k, $hits, $window); }
        return $ok;
    }
    $f = rl_file($key);
    $fp = @fopen($f, 'c+');
    if (!$fp) return true;
    flock($fp, LOCK_EX);
    $data = json_decode((string)stream_get_contents($fp), true);
    $now = time();
    $hits = array_values(array_filter((array)($data['h'] ?? []), fn($t) => $t > $now - $window));
    $ok = count($hits) < $max;
    if ($ok) $hits[] = $now;
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode(['h' => $hits, 'until' => (int)($data['until'] ?? 0)]));
    flock($fp, LOCK_UN); fclose($fp);
    return $ok;
}
function rl_reset(string $key): void {
    if (function_exists('apcu_enabled') && apcu_enabled()) apcu_delete('sb_rl_' . sha1($key));
    @unlink(DATA_DIR . '/rl/' . sha1($key) . '.json');
}
function rl_count(string $key, int $window): int {
    if (function_exists('apcu_enabled') && apcu_enabled()) return count(array_filter((array)apcu_fetch('sb_rl_' . sha1($key)), fn($t) => $t > time() - $window));
    $data = @json_decode((string)@file_get_contents(rl_file($key)), true);
    return count(array_filter((array)($data['h'] ?? []), fn($t) => $t > time() - $window));
}

/* ============================================================
 *  FIREWALL: bloqueio de IPs e de ataques comuns
 * ============================================================ */
function ip_ban(string $ip, int $seconds, string $reason): void {
    @file_put_contents(DATA_DIR . '/rl/' . sha1('ban:' . $ip) . '.json', json_encode(['h' => [], 'until' => time() + $seconds, 'reason' => $reason, 'ip' => $ip, 'at' => time()]), LOCK_EX);
    security_event('ban', "IP $ip bloqueado por " . round($seconds / 3600) . "h: $reason");
}
function ip_unban(string $ip): void { @unlink(DATA_DIR . '/rl/' . sha1('ban:' . $ip) . '.json'); }
function ip_banned(string $ip): bool {
    if (ip_in_list($ip, preg_split('/[\s,]+/', (string)setting('blocked_ips', '')) ?: [])) return true;
    $f = DATA_DIR . '/rl/' . sha1('ban:' . $ip) . '.json';
    if (!is_file($f)) return false;
    $d = json_decode((string)file_get_contents($f), true);
    return (int)($d['until'] ?? 0) > time();
}
/** Lista os bloqueios automáticos que ainda valem */
function ip_bans_active(): array {
    $out = [];
    foreach ((array)glob(DATA_DIR . '/rl/*.json') as $f) {
        $d = json_decode((string)@file_get_contents($f), true);
        if (!empty($d['ip']) && (int)($d['until'] ?? 0) > time()) $out[] = $d;
    }
    usort($out, fn($a, $b) => $b['at'] <=> $a['at']);
    return $out;
}

function firewall_block(int $code = 403): void {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    exit('<!doctype html><meta charset="utf-8"><title>Acesso bloqueado</title><body style="font-family:system-ui;background:#000;color:#ddd;display:flex;align-items:center;justify-content:center;height:100vh;margin:0"><div style="text-align:center"><h1 style="color:#ff9000">Acesso bloqueado</h1><p>Detectamos atividade suspeita vinda da sua conexão.</p></div>');
}

function firewall(): void {
    $ip = client_ip();
    if (!empty($_SESSION['admin_id'])) return;                 // o admin logado não é barrado
    if (ip_banned($ip)) firewall_block();

    // Padrões típicos de ataque na URL (SQL injection, XSS, invasão de pastas, execução de código)
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $u = urldecode(urldecode($uri));
    $patterns = [
        '~\.\./|\.\.\\\\~', '~<\s*/?\s*(script|iframe|svg|img|object|embed)\b~i', '~\bunion\b[\s\S]{0,40}\bselect\b~i',
        '~\b(sleep|benchmark|pg_sleep|waitfor\s+delay)\s*\(~i', '~/etc/(passwd|shadow)|/proc/self~i', '~\b(information_schema|load_file|into\s+outfile)\b~i',
        '~\b(base64_decode|eval|assert|system|passthru|shell_exec|proc_open|popen)\s*\(~i', '~(javascript|vbscript|data)\s*:[^/]~i',
        '~\bon(error|load|mouseover|focus|click)\s*=~i', '~(php|file|zip|phar|expect|glob)://~i', '~\$\{jndi:~i',
    ];
    foreach ($patterns as $re) {
        if (preg_match($re, $u)) {
            security_event('attack', 'Bloqueado: ' . mb_substr($uri, 0, 200));
            if (!rl_hit('fw:' . $ip, 4, 3600)) ip_ban($ip, 86400, 'várias tentativas de ataque');   // 5ª tentativa em 1h = bloqueio de 24h
            firewall_block();
        }
    }
    // Excesso de acessos (robô/derrubar o site): mais de 600 páginas por minuto do mesmo IP
    if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'thumb.php' && !rl_hit('req:' . $ip, 600, 60)) { ip_ban($ip, 3600, 'excesso de acessos por minuto'); firewall_block(429); }
}

/** Registro de eventos de segurança (últimos 300) */
function security_event(string $type, string $msg): void {
    $f = DATA_DIR . '/security-log.json';
    $fp = @fopen($f, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $list = json_decode((string)stream_get_contents($fp), true) ?: [];
    array_unshift($list, ['t' => time(), 'type' => $type, 'ip' => client_ip(), 'msg' => $msg]);
    $list = array_slice($list, 0, 300);
    ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($list, JSON_UNESCAPED_UNICODE)); flock($fp, LOCK_UN); fclose($fp);
}
function security_events(int $n = 50): array {
    return array_slice(json_decode((string)@file_get_contents(DATA_DIR . '/security-log.json'), true) ?: [], 0, $n);
}

/** Cabeçalhos extras para o painel e o instalador */
function private_page_headers(): void {
    if (headers_sent()) return;
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}

/* ---------------- Sessão do admin ---------------- */
function ua_hash(): string { return hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? ''); }

function admin_session_check(): void {
    if (empty($_SESSION['admin_id'])) return;
    $expired = time() - (int)($_SESSION['admin_last'] ?? 0) > ADMIN_IDLE_SECONDS;
    $otherBrowser = !hash_equals((string)($_SESSION['admin_ua'] ?? ''), ua_hash());
    $revoked = (int)($_SESSION['admin_sv'] ?? -1) !== (int)setting('admin_session_ver', 0);
    if ($expired || $otherBrowser || $revoked) { admin_logout_session(); return; }
    $_SESSION['admin_last'] = time();
}

function admin_login_session(array $admin): void {
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$admin['id'];
    $_SESSION['admin_user'] = $admin['username'];
    $_SESSION['admin_ua'] = ua_hash();
    $_SESSION['admin_last'] = time();
    $_SESSION['admin_sv'] = (int)setting('admin_session_ver', 0);
    unset($_SESSION['csrf']);
}

function admin_logout_session(): void {
    unset($_SESSION['admin_id'], $_SESSION['admin_user'], $_SESSION['admin_ua'], $_SESSION['admin_last'], $_SESSION['admin_sv'], $_SESSION['csrf']);
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
}

/** Desconecta TODAS as sessões do admin (todos os navegadores/celulares) */
function admin_revoke_all_sessions(): void {
    set_setting('admin_session_ver', (string)((int)setting('admin_session_ver', 0) + 1));
}

/* ---------------- Tentativas de login ---------------- */
function login_recent_fails(array $where, int $max): int {
    $rows = db()->query('logins', ['where' => $where + ['success' => 0], 'order' => 'id DESC', 'limit' => $max]);
    $limit = time() - LOGIN_WINDOW_MIN * 60;
    return count(array_filter($rows, fn($r) => strtotime($r['created_at']) >= $limit));
}

/** IP de onde o admin já entrou com sucesso nos últimos 30 dias */
function login_trusted_ip(string $ip): bool {
    foreach (db()->query('logins', ['where' => ['ip' => $ip, 'success' => 1], 'order' => 'id DESC', 'limit' => 1]) as $r)
        if (strtotime($r['created_at']) > time() - 30 * 86400) return true;
    return false;
}

/**
 * Retorna quantos minutos faltam para liberar, ou 0 se pode tentar.
 *  - 5 erros do mesmo IP em 15 min  -> bloqueia 15 min
 *  - 20 erros do mesmo IP em 24 h   -> IP bloqueado no site inteiro por 24 h
 *  - 10 erros no mesmo usuário em 15 min (vários IPs) -> só IPs de confiança conseguem tentar
 */
function login_blocked(string $user): int {
    $ip = client_ip();
    $rows = db()->query('logins', ['where' => ['ip' => $ip, 'success' => 0], 'order' => 'id DESC', 'limit' => 20]);
    if (count(array_filter($rows, fn($r) => strtotime($r['created_at']) > time() - 86400)) >= 20) {
        ip_ban($ip, 86400, '20 senhas erradas em 24h');
        return 1440;
    }
    if (count(array_filter($rows, fn($r) => strtotime($r['created_at']) >= time() - LOGIN_WINDOW_MIN * 60)) >= LOGIN_MAX_FAILS_IP) {
        return LOGIN_WINDOW_MIN;
    }
    if ($user !== '' && login_recent_fails(['username' => $user], LOGIN_MAX_FAILS_USER) >= LOGIN_MAX_FAILS_USER && !login_trusted_ip($ip)) {
        return LOGIN_WINDOW_MIN;
    }
    return 0;
}

function login_log(string $user, bool $success): void {
    try {
        db()->insert('logins', ['username' => mb_substr($user, 0, 60), 'ip' => client_ip(),
            'user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250), 'success' => $success ? 1 : 0, 'created_at' => now()]);
    } catch (Throwable $e) { /* tabela ainda não existe: segue sem registrar */ }
}

/* ---------------- Instalador ---------------- */
function install_key_file(): string { return DATA_DIR . '/chave-instalacao.txt'; }

/** Cria (uma vez) a chave que prova que quem está instalando tem acesso aos arquivos da hospedagem */
function install_key(): string {
    $f = install_key_file();
    if (is_file($f) && preg_match('/([A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4})/', (string)file_get_contents($f), $m)) return $m[1];
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $k = '';
    for ($i = 0; $i < 12; $i++) $k .= $chars[random_int(0, strlen($chars) - 1)] . (in_array($i, [3, 7], true) ? '-' : '');
    @file_put_contents($f, "CHAVE DE INSTALAÇÃO\r\n\r\n$k\r\n\r\nCopie a chave acima e cole no instalador.\r\nEste arquivo é apagado sozinho quando a instalação termina.\r\n");
    return $k;
}

/* ---------------- Verificações (Admin > Segurança) ---------------- */
function http_status_of(string $url): int {
    if (!function_exists('curl_init')) return 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
    curl_exec($ch);
    $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $c;
}

/** Lista de verificações: [titulo, status ok|warn|bad, detalhe, ação opcional] */
function security_checks(): array {
    $c = [];
    $root = realpath(__DIR__ . '/..');
    $me = db()->find('admins', $_SESSION['admin_id'] ?? 0);

    $c[] = ['Instalador bloqueado', is_installed() ? 'ok' : 'bad', is_installed() ? 'Ninguém consegue rodar a instalação de novo.' : 'O instalador está aberto!'];
    $c[] = ['Arquivo install.php', is_file($root . '/install.php') ? 'warn' : 'ok',
        is_file($root . '/install.php') ? 'Está bloqueado, mas o ideal é apagar depois de instalar.' : 'Removido.', is_file($root . '/install.php') ? 'delete_installer' : null];
    $c[] = ['Chave de instalação', is_file(install_key_file()) ? 'warn' : 'ok', is_file(install_key_file()) ? 'O arquivo da chave ainda existe.' : 'Removida.', is_file(install_key_file()) ? 'delete_key' : null];
    $weak = $me && (password_verify('admin123', $me['password_hash']) || password_verify('12345678', $me['password_hash']) || password_verify($me['username'], $me['password_hash']));
    $c[] = ['Senha do admin', $weak ? 'bad' : 'ok', $weak ? 'Senha fraca ou padrão. Troque em Configurações.' : 'Senha personalizada.'];
    $c[] = ['Verificação em duas etapas', !empty($me['totp_secret']) ? 'ok' : 'warn', !empty($me['totp_secret']) ? 'Ativada: o login pede o código do celular.' : 'Desativada. Ative abaixo — é a proteção mais forte contra invasão.'];
    $c[] = ['Firewall', 'ok', 'Ativo: bloqueia ataques na URL, excesso de acessos e IPs da lista. ' . count(ip_bans_active()) . ' IP(s) bloqueado(s) agora.'];
    $c[] = ['Usuário do admin', in_array(strtolower($_SESSION['admin_user'] ?? ''), ['admin', 'administrador', 'root'], true) ? 'warn' : 'ok',
        in_array(strtolower($_SESSION['admin_user'] ?? ''), ['admin', 'administrador', 'root'], true) ? 'Usuário “admin” é o primeiro que robôs tentam. Prefira outro nome.' : 'Nome de usuário personalizado.'];
    $c[] = ['Conexão segura (HTTPS)', is_https() ? (setting('force_https', '') === '1' ? 'ok' : 'warn') : 'bad',
        is_https() ? (setting('force_https', '') === '1' ? 'HTTPS ativo e obrigatório.' : 'HTTPS ativo, mas não obrigatório. Ative “Forçar HTTPS”.') : 'O site está sem HTTPS. Ative o SSL grátis (Let’s Encrypt) no painel da hospedagem.'];
    $code = http_status_of(absolute_url('data/installed.lock'));
    $c[] = ['Pasta de dados protegida', in_array($code, [401, 403, 404], true) ? 'ok' : ($code === 0 ? 'warn' : 'bad'),
        in_array($code, [401, 403, 404], true) ? 'Arquivos internos não podem ser baixados.' : ($code === 0 ? 'Não foi possível testar daqui.' : 'ATENÇÃO: a pasta data está acessível pela internet (código ' . $code . '). Confira se o .htaccess foi enviado.')];
    $c[] = ['Pasta de thumbs com permissão de escrita', thumbs_dir() !== null ? 'ok' : 'bad', thumbs_dir() !== null ? 'As thumbs podem ser salvas.' : 'Sem permissão: as thumbs não são salvas. Dê permissão 755 às pastas uploads e uploads/thumbs.'];
    $c[] = ['Pasta de thumbs sem execução de código', is_file($root . '/uploads/.htaccess') ? 'ok' : 'bad', is_file($root . '/uploads/.htaccess') ? 'Arquivos .php não rodam em /uploads.' : 'Falta o arquivo uploads/.htaccess.'];
    $c[] = ['Erros do PHP escondidos', (!DEBUG && !ini_get('display_errors')) ? 'ok' : 'bad', !DEBUG ? 'Visitantes não veem mensagens de erro.' : 'DEBUG está ligado no config.php. Desligue.'];
    $c[] = ['Versão do PHP', version_compare(PHP_VERSION, '8.1', '>=') ? 'ok' : 'warn', 'PHP ' . PHP_VERSION . (version_compare(PHP_VERSION, '8.1', '>=') ? '' : ' — atualize para 8.1 ou superior no painel da hospedagem.')];
    $cfg = DATA_DIR . '/config.local.php';
    if (is_file($cfg)) {
        $perm = fileperms($cfg) & 0777;
        $c[] = ['Permissão do arquivo do banco', ($perm & 0002) ? 'bad' : 'ok', ($perm & 0002) ? 'config.local.php pode ser alterado por qualquer usuário do servidor (' . decoct($perm) . '). Use 640 ou 644.' : 'OK (' . decoct($perm) . ').'];
    }
    $fails = count(array_filter(db()->query('logins', ['where' => ['success' => 0], 'order' => 'id DESC', 'limit' => 200]), fn($r) => strtotime($r['created_at']) > time() - 86400));
    $c[] = ['Tentativas de login erradas (24h)', $fails > 20 ? 'warn' : 'ok', $fails . ' tentativa(s). IPs com 5 erros ficam bloqueados por 15 minutos.'];
    return $c;
}
