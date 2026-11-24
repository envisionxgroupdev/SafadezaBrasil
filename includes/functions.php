<?php
/* ---------------- Básico ---------------- */
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function base_url(string $path = ''): string {
    static $base = null;
    if ($base === null) {
        if (SITE_URL !== '') $base = rtrim(SITE_URL, '/');
        else {
            $root = realpath(__DIR__ . '/..');
            $doc  = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
            $sub  = ($doc && str_starts_with($root, $doc)) ? str_replace('\\', '/', substr($root, strlen($doc))) : '';
            $base = rtrim($sub, '/');
        }
    }
    return $base . '/' . ltrim($path, '/');
}

/** Link de CSS/JS com versão automática (muda sempre que o arquivo muda: o navegador nunca usa cópia velha) */
function asset_url(string $path): string {
    $f = __DIR__ . '/../' . ltrim($path, '/');
    return base_url($path) . '?v=' . (is_file($f) ? filemtime($f) : '1');
}
function redirect(string $to): void { header('Location: ' . $to); exit; }
function now(): string { return date('Y-m-d H:i:s'); }

/* ---------------- Configurações ---------------- */
function &settings_cache(): array { static $c = []; return $c; }
function setting(string $key, $default = null) {
    $cache = &settings_cache();
    if (!array_key_exists($key, $cache)) {
        $row = db()->findBy('settings', 'skey', $key);
        $cache[$key] = $row ? $row['svalue'] : null;
    }
    return $cache[$key] ?? $default;
}
function set_setting(string $key, $value): void {
    $cache = &settings_cache(); $cache[$key] = $value;
    $row = db()->findBy('settings', 'skey', $key);
    if ($row) db()->update('settings', $row['id'], ['svalue' => $value]);
    else db()->insert('settings', ['skey' => $key, 'svalue' => $value]);
}

/** Nome do site: painel (se preenchido) > config.php */
function site_name(): string { return trim((string)setting('site_name', '')) ?: SITE_NAME; }
function site_tagline(): string { return trim((string)setting('site_tagline', '')) ?: SITE_TAGLINE; }

/** Cor principal: painel > padrão laranja */
function primary_color(): string {
    $c = trim((string)setting('color_primary', ''));
    return preg_match('/^#[0-9a-f]{3,8}$/i', $c) ? $c : '#ff9000';
}

/** Logo: ícone + nome. A 1ª palavra fica branca e o resto na cor principal. */
function logo_html(string $size = ''): string {
    $parts = preg_split('/\s+/', trim(site_name()), 2);
    return '<span class="brand ' . e($size) . '"><span class="brand-mark"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4.5v15l12.5-7.5z"/></svg></span>'
         . '<span class="brand-word"><span class="b1">' . e($parts[0]) . '</span>'
         . (isset($parts[1]) ? '<span class="b2">' . e($parts[1]) . '</span>' : '') . '</span></span>';
}

/* ---------------- SEO ---------------- */
/** Aceita o código puro ou a tag <meta ...> inteira colada do Google/Bing */
function seo_verify_value(string $v): string {
    $v = trim($v);
    if (preg_match('~content=["\']([^"\']+)["\']~i', $v, $m)) return $m[1];
    return strip_tags($v);
}
function seo_head_tags(): string {
    $h = '';
    $verify = ['seo_google_verify' => 'google-site-verification', 'seo_bing_verify' => 'msvalidate.01',
               'seo_yandex_verify' => 'yandex-verification', 'seo_pinterest_verify' => 'p:domain_verify'];
    foreach ($verify as $k => $name) {
        if ($v = seo_verify_value((string)setting($k, ''))) $h .= '<meta name="' . $name . '" content="' . e($v) . '">' . "\n";
    }
    $ga = trim((string)setting('seo_ga_id', ''));
    if (preg_match('/^(G|UA|AW)-[A-Z0-9-]+$/i', $ga)) {
        $h .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . e($ga) . '"></script>' . "\n"
            . '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag("js",new Date());gtag("config",' . json_encode($ga) . ');</script>' . "\n";
    }
    $gtm = trim((string)setting('seo_gtm_id', ''));
    if (preg_match('/^GTM-[A-Z0-9]+$/i', $gtm)) {
        $h .= "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer'," . json_encode($gtm) . ");</script>\n";
    }
    return $h;
}
function default_robots(): string {
    return "User-agent: *\nDisallow: /admin/\nDisallow: /api.php\nDisallow: /install.php\n\nSitemap: " . absolute_url('sitemap.php') . "\n";
}
function robots_content(): string {
    if (setting('seo_noindex', '')) return "User-agent: *\nDisallow: /\n";
    return trim((string)setting('seo_robots', '')) !== '' ? str_replace('{SITEMAP}', absolute_url('sitemap.php'), (string)setting('seo_robots')) . "\n" : default_robots();
}
/** URL completa (sitemap, og:image...). Usa o domínio gravado na instalação, não o que o visitante manda (evita "host header injection"). */
function absolute_url(string $path = ''): string {
    $u = base_url($path);
    if (preg_match('~^https?://~', $u)) return $u;
    $host = site_host();
    return (is_https() || setting('force_https', '') === '1' ? 'https' : 'http') . '://' . $host . $u;
}
function site_host(): string {
    $h = strtolower((string)setting('site_host', ''));
    if ($h !== '' && preg_match('/^[a-z0-9.-]+(:\d+)?$/', $h)) return $h;
    $cur = strtolower($_SERVER['HTTP_HOST'] ?? 'localhost');
    return preg_match('/^[a-z0-9.-]+(:\d+)?$/', $cur) ? $cur : 'localhost';
}
/** Só aceita links http:// ou https:// (bloqueia javascript:, data:, file:...) */
function is_http_url($u): bool {
    return is_string($u) && preg_match('~^https?://[^\s<>"\']+$~i', $u) && filter_var($u, FILTER_VALIDATE_URL) !== false;
}

/* ---------------- Formatação ---------------- */
function slugify(string $s): string {
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $s));
    return trim(substr($s, 0, 120), '-') ?: 'video';
}
function unique_slug(string $table, string $base, ?int $ignoreId = null): string {
    $slug = slugify($base); $try = $slug; $i = 2;
    while (($r = db()->findBy($table, 'slug', $try)) && (int)$r['id'] !== (int)$ignoreId) $try = $slug . '-' . $i++;
    return $try;
}
function fmt_duration(int $s): string {
    if ($s <= 0) return '';
    $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60); $sec = $s % 60;
    return $h ? sprintf('%d:%02d:%02d', $h, $m, $sec) : sprintf('%d:%02d', $m, $sec);
}
function parse_duration($v): int {
    $v = trim((string)$v);
    if ($v === '') return 0;
    if (ctype_digit($v)) return (int)$v;
    if (preg_match('/^P(?:T)?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/i', $v, $m))        // ISO 8601 PT12M30S
        return (int)($m[1] ?? 0) * 3600 + (int)($m[2] ?? 0) * 60 + (int)($m[3] ?? 0);
    $p = array_reverse(array_map('intval', explode(':', $v)));                    // 12:30 ou 1:02:03
    return ($p[0] ?? 0) + ($p[1] ?? 0) * 60 + ($p[2] ?? 0) * 3600;
}
function fmt_views(int $n): string {
    if ($n >= 1000000) return rtrim(rtrim(number_format($n / 1000000, 1, ',', ''), '0'), ',') . 'M';
    if ($n >= 1000)    return rtrim(rtrim(number_format($n / 1000, 1, ',', ''), '0'), ',') . 'K';
    return (string)$n;
}
function time_ago(string $dt): string {
    $d = time() - strtotime($dt);
    if ($d < 60) return 'agora';
    foreach ([31536000 => 'ano', 2592000 => 'mês', 604800 => 'semana', 86400 => 'dia', 3600 => 'hora', 60 => 'minuto'] as $sec => $lbl) {
        if ($d >= $sec) {
            $n = intdiv($d, $sec);
            $pl = $lbl === 'mês' ? 'meses' : $lbl . 's';
            return "há $n " . ($n > 1 ? $pl : $lbl);
        }
    }
    return '';
}
function rating_pct(array $v): int {
    $t = (int)$v['likes'] + (int)$v['dislikes'];
    return $t ? (int)round($v['likes'] * 100 / $t) : 0;
}

/* ---------------- URLs ---------------- */
function video_url(array $v): string {
    return PRETTY_URLS ? base_url("video/{$v['id']}/{$v['slug']}") : base_url("video.php?id={$v['id']}&s={$v['slug']}");
}
function category_url(array $c, int $page = 1): string {
    $u = PRETTY_URLS ? base_url("categoria/{$c['slug']}") : base_url("category.php?c={$c['slug']}");
    return $page > 1 ? $u . (PRETTY_URLS ? "?" : "&") . "page=$page" : $u;
}
function tag_url(string $t): string { return base_url('search.php?q=' . urlencode($t)); }

/* ---------------- Vídeos ---------------- */
function list_videos(array $o = []): array {
    $o['where'] = ($o['where'] ?? []) + ['status' => 'published'];
    return db()->query('videos', $o);
}
function count_videos(array $o = []): int {
    $o['where'] = ($o['where'] ?? []) + ['status' => 'published'];
    return db()->count('videos', $o);
}
function sort_options(): array {
    return ['recentes' => ['Mais recentes', 'created_at DESC'], 'vistos' => ['Mais vistos', 'views DESC'],
            'curtidos' => ['Mais curtidos', 'likes DESC'], 'longos' => ['Mais longos', 'duration DESC']];
}
function all_categories(): array { return db()->query('categories', ['order' => 'sort ASC']); }
function category_map(): array {
    static $m = null;
    if ($m === null) { $m = []; foreach (all_categories() as $c) $m[$c['id']] = $c; }
    return $m;
}
function split_list(?string $s): array { return array_values(array_filter(array_map('trim', explode(',', (string)$s)), 'strlen')); }

/* ---------------- Segurança ---------------- */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }
function csrf_valid($token): bool {
    $s = (string)($_SESSION['csrf'] ?? '');
    return $s !== '' && is_string($token) && $token !== '' && hash_equals($s, $token);
}
function csrf_check(): void {
    if (!csrf_valid($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '')) {
        http_response_code(419); exit('Sessão expirada. Volte e tente novamente.');
    }
}
function is_admin(): bool { return !empty($_SESSION['admin_id']); }
function require_admin(): void { if (!is_admin()) redirect(base_url('admin/login.php')); }

function flash(?string $msg = null, string $type = 'ok') {
    if ($msg !== null) { $_SESSION['flash'][] = [$type, $msg]; return null; }
    $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f;
}

/* ---------------- Paginação ---------------- */
function paginate(int $total, int $page, int $per, callable $urlFor): string {
    $pages = (int)ceil($total / $per);
    if ($pages <= 1) return '';
    $h = '<nav class="pagination">';
    if ($page > 1) $h .= '<a href="' . e($urlFor($page - 1)) . '">&laquo; Anterior</a>';
    $start = max(1, $page - 3); $end = min($pages, $page + 3);
    if ($start > 1) $h .= '<a href="' . e($urlFor(1)) . '">1</a>' . ($start > 2 ? '<span>…</span>' : '');
    for ($i = $start; $i <= $end; $i++)
        $h .= $i === $page ? '<span class="current">' . $i . '</span>' : '<a href="' . e($urlFor($i)) . '">' . $i . '</a>';
    if ($end < $pages) $h .= ($end < $pages - 1 ? '<span>…</span>' : '') . '<a href="' . e($urlFor($pages)) . '">' . $pages . '</a>';
    if ($page < $pages) $h .= '<a href="' . e($urlFor($page + 1)) . '">Próxima &raquo;</a>';
    return $h . '</nav>';
}
function current_page(): int { return max(1, (int)($_GET['page'] ?? 1)); }

/* ---------------- Atualização automática do banco ---------------- */
const SCHEMA_VERSION = 4;
function db_upgrade(): void {
    if ((int)setting('schema_version', 1) >= SCHEMA_VERSION) return;
    $d = db();
    if ($d instanceof MysqlDriver) {
        $pdo = $d->pdo();
        $pdo->exec("CREATE TABLE IF NOT EXISTS `logins` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `username` VARCHAR(60) NULL,
            `ip` VARCHAR(64) NOT NULL, `user_agent` VARCHAR(255) NULL, `success` TINYINT(1) NOT NULL DEFAULT 0, `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`), KEY `idx_ip_time` (`ip`,`created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if (!$d->hasColumn('videos', 'thumbs')) $pdo->exec("ALTER TABLE `videos` ADD `thumbs` TEXT NULL AFTER `thumb`");
        if (!$d->hasColumn('admins', 'totp_secret')) $pdo->exec("ALTER TABLE `admins` ADD `totp_secret` VARCHAR(64) NULL, ADD `totp_recovery` TEXT NULL, ADD `totp_last` INT NOT NULL DEFAULT 0");
    }
    // v4: vídeos antigos com thumb de outro site entram na fila para baixar
    try { thumbs_requeue_remote(); } catch (Throwable $e) {}
    set_setting('schema_version', (string)SCHEMA_VERSION);
}

/* ---------------- Dados iniciais ---------------- */
function seed_if_empty(): void {
    if (DB_DRIVER !== 'json') return; // no MySQL, o instalador cria o admin e as categorias
    if (db()->count('admins') === 0) create_default_admin();
    if (db()->count('categories') === 0) {
        $cats = ['Amadores','Brasileiras','Casais','HD','Latinas','Loiras','Morenas','Ruivas','Lésbicas','Maduras','POV','Asiáticas'];
        foreach ($cats as $i => $c) db()->insert('categories', ['name' => $c, 'slug' => slugify($c), 'description' => '', 'thumb' => '', 'sort' => $i + 1]);
    }
}
function create_default_admin(): void {
    db()->insert('admins', ['username' => ADMIN_DEFAULT_USER, 'password_hash' => password_hash(ADMIN_DEFAULT_PASS, PASSWORD_DEFAULT), 'created_at' => now()]);
}

/* ---------------- Card de vídeo ---------------- */
function video_card(array $v): string {
    $noThumb = base_url('assets/img/no-thumb.svg');
    $pv = thumb_preview($v['thumbs'] ?? '');
    $attr = isset($pv['sprite']) ? ' data-sprite="' . e($pv['sprite']) . '" data-n="' . (int)$pv['n'] . '"'
          : (!empty($pv['frames']) ? ' data-frames="' . e(implode('|', $pv['frames'])) . '"' : '');
    $dur = fmt_duration((int)$v['duration']);
    $pct = rating_pct($v);
    $hd = (int)$v['duration'] >= 0 && preg_match('/\b(hd|1080|720|4k)\b/i', $v['title'] . ' ' . $v['tags']);
    return '<article class="card">
      <a href="' . e(video_url($v)) . '" class="thumb"' . $attr . '>
        <img src="' . e(video_thumb_url($v)) . '" alt="' . e($v['title']) . '" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="window.thumbFail?thumbFail(this):(this.onerror=null,this.src=\'' . e($noThumb) . '\')">
        ' . ($hd ? '<span class="hd">HD</span>' : '') . '
        ' . ($dur ? '<span class="dur">' . $dur . '</span>' : '') . '
        ' . (!empty($v['featured']) ? '<span class="badge">Destaque</span>' : (strtotime($v['created_at']) > time() - 172800 ? '<span class="badge new">Novo</span>' : '')) . '
        ' . ($attr ? '<span class="pbar"><i></i></span>' : '') . '
      </a>
      <a href="' . e(video_url($v)) . '" class="title">' . e($v['title']) . '</a>
      <div class="meta"><span>' . fmt_views((int)$v['views']) . ' views</span>' . ($pct ? '<span class="up">' . $pct . '%</span>' : '') . '<span class="ago">' . time_ago($v['created_at']) . '</span></div>
    </article>';
}
