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

function redirect(string $to): never { header('Location: ' . $to); exit; }
function now(): string { return date('Y-m-d H:i:s'); }
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

/* ---------------- Configurações ---------------- */
function setting(string $key, $default = null) {
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $row = db()->findBy('settings', 'skey', $key);
        $cache[$key] = $row ? $row['svalue'] : null;
    }
    return $cache[$key] ?? $default;
}
function set_setting(string $key, $value): void {
    $row = db()->findBy('settings', 'skey', $key);
    if ($row) db()->update('settings', $row['id'], ['svalue' => $value]);
    else db()->insert('settings', ['skey' => $key, 'svalue' => $value]);
}

/** Nome do site: painel (se preenchido) > config.php */
function site_name(): string { return trim((string)setting('site_name', '')) ?: SITE_NAME; }
function site_tagline(): string { return trim((string)setting('site_tagline', '')) ?: SITE_TAGLINE; }

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
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '')) {
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

/* ---------------- Dados iniciais ---------------- */
function seed_if_empty(): void {
    if (DB_DRIVER !== 'json') {
        if (db()->count('admins') === 0) create_default_admin();
        return;
    }
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
    $thumb = $v['thumb'] ?: base_url('assets/img/no-thumb.svg');
    $dur = fmt_duration((int)$v['duration']);
    $pct = rating_pct($v);
    return '<article class="card">
      <a href="' . e(video_url($v)) . '" class="thumb">
        <img src="' . e($thumb) . '" alt="' . e($v['title']) . '" loading="lazy" referrerpolicy="no-referrer" onerror="this.src=\'' . e(base_url('assets/img/no-thumb.svg')) . '\'">
        ' . ($dur ? '<span class="dur">' . $dur . '</span>' : '') . '
        ' . (!empty($v['featured']) ? '<span class="badge">Destaque</span>' : '') . '
      </a>
      <a href="' . e(video_url($v)) . '" class="title">' . e($v['title']) . '</a>
      <div class="meta"><span>' . fmt_views((int)$v['views']) . ' views</span>' . ($pct ? '<span class="up">👍 ' . $pct . '%</span>' : '') . '<span>' . time_ago($v['created_at']) . '</span></div>
    </article>';
}
