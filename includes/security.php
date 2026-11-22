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
    if (is_https() && setting('force_https', '') === '1') header('Strict-Transport-Security: max-age=31536000');
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

/** Retorna quantos minutos faltam para liberar, ou 0 se pode tentar */
function login_blocked(string $user): int {
    $ip = client_ip();
    if (login_recent_fails(['ip' => $ip], LOGIN_MAX_FAILS_IP) >= LOGIN_MAX_FAILS_IP
        || ($user !== '' && login_recent_fails(['username' => $user], LOGIN_MAX_FAILS_USER) >= LOGIN_MAX_FAILS_USER)) {
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
    $c[] = ['Usuário do admin', in_array(strtolower($_SESSION['admin_user'] ?? ''), ['admin', 'administrador', 'root'], true) ? 'warn' : 'ok',
        in_array(strtolower($_SESSION['admin_user'] ?? ''), ['admin', 'administrador', 'root'], true) ? 'Usuário “admin” é o primeiro que robôs tentam. Prefira outro nome.' : 'Nome de usuário personalizado.'];
    $c[] = ['Conexão segura (HTTPS)', is_https() ? (setting('force_https', '') === '1' ? 'ok' : 'warn') : 'bad',
        is_https() ? (setting('force_https', '') === '1' ? 'HTTPS ativo e obrigatório.' : 'HTTPS ativo, mas não obrigatório. Ative “Forçar HTTPS”.') : 'O site está sem HTTPS. Ative o SSL grátis (Let’s Encrypt) no painel da hospedagem.'];
    $code = http_status_of(absolute_url('data/installed.lock'));
    $c[] = ['Pasta de dados protegida', in_array($code, [401, 403, 404], true) ? 'ok' : ($code === 0 ? 'warn' : 'bad'),
        in_array($code, [401, 403, 404], true) ? 'Arquivos internos não podem ser baixados.' : ($code === 0 ? 'Não foi possível testar daqui.' : 'ATENÇÃO: a pasta data está acessível pela internet (código ' . $code . '). Confira se o .htaccess foi enviado.')];
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
