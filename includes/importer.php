<?php
/**
 * ============================================================
 *  IMPORTADOR DE EMBEDS
 * ============================================================
 *  - Reconhece links e códigos <iframe> dos principais sites
 *  - Gera a URL de embed oficial de cada site
 *  - Busca título, thumb, duração e tags automaticamente
 *    (pela API pública quando o site tem, senão pelas meta tags da página)
 *  - Busca em massa por palavra-chave (Pornhub, RedTube, Eporner)
 *
 *  Para adicionar um site novo: inclua um item em importer_providers().
 * ============================================================
 */

function importer_providers(): array {
    return [
        'xvideos' => [
            'name'  => 'XVideos',
            'match' => ['~xvideos\d*\.[a-z.]+/(?:video\.?|embedframe/)([a-z0-9]+)~i'],
            'embed' => 'https://www.xvideos.com/embedframe/{id}',
        ],
        'xnxx' => [
            'name'  => 'XNXX',
            'match' => ['~xnxx\d*\.[a-z.]+/(?:video-|embedframe/)([a-z0-9]+)~i'],
            'embed' => 'https://www.xnxx.com/embedframe/{id}',
        ],
        'pornhub' => [
            'name'  => 'Pornhub',
            'match' => ['~pornhub\.[a-z.]+/(?:view_video\.php\?viewkey=|embed/)([a-z0-9]+)~i'],
            'embed' => 'https://www.pornhub.com/embed/{id}',
            'api'   => 'pornhub',
        ],
        'xhamster' => [
            'name'  => 'xHamster',
            'match' => ['~xhamster\d*\.[a-z.]+/(?:xembed\.php\?video=|embed/)([a-z0-9]+)~i',
                        '~xhamster\d*\.[a-z.]+/videos/[^?#]*?-([a-z0-9]+)(?:[?#/]|$)~i'],
            'embed' => 'https://xhamster.com/xembed.php?video={id}',
        ],
        'redtube' => [
            'name'  => 'RedTube',
            'match' => ['~embed\.redtube\.[a-z.]+/\?id=(\d+)~i', '~redtube\.[a-z.]+/(\d+)~i'],
            'embed' => 'https://embed.redtube.com/?id={id}',
            'api'   => 'redtube',
        ],
        'youporn' => [
            'name'  => 'YouPorn',
            'match' => ['~youporn\.[a-z.]+/(?:watch|embed)/(\d+)~i'],
            'embed' => 'https://www.youporn.com/embed/{id}',
        ],
        'spankbang' => [
            'name'  => 'SpankBang',
            'match' => ['~spankbang\.[a-z.]+/([a-z0-9]+)/(?:video|embed|play)~i'],
            'embed' => 'https://spankbang.com/{id}/embed/',
        ],
        'eporner' => [
            'name'  => 'Eporner',
            'match' => ['~eporner\.[a-z.]+/(?:embed/|video-|hd-porn/)([a-z0-9]+)~i'],
            'embed' => 'https://www.eporner.com/embed/{id}/',
            'api'   => 'eporner',
        ],
        'tube8' => [
            'name'  => 'Tube8',
            'match' => ['~tube8\.[a-z.]+/(?:embed/)?(?:[^/]+/){0,2}(\d+)/?~i'],
            'embed' => 'https://www.tube8.com/embed/{id}/',
        ],
        'txxx' => [
            'name'  => 'TXXX',
            'match' => ['~txxx\.[a-z.]+/(?:videos|embed)/(\d+)~i'],
            'embed' => 'https://www.txxx.com/embed/{id}/',
        ],
    ];
}

/** Sites que permitem importar por palavra-chave (API pública) */
function importer_search_providers(): array {
    return ['eporner' => 'Eporner', 'xvideos' => 'XVideos', 'pornhub' => 'Pornhub', 'redtube' => 'RedTube'];
}

/** Analisa um link ou um código <iframe>. */
function importer_parse(string $input): ?array {
    $input = trim($input);
    if ($input === '') return null;

    $src = $input;
    if (stripos($input, '<iframe') !== false && preg_match('~src=["\']([^"\']+)["\']~i', $input, $m)) $src = html_entity_decode($m[1]);
    if (str_starts_with($src, '//')) $src = 'https:' . $src;

    foreach (importer_providers() as $key => $p) {
        foreach ($p['match'] as $re) {
            if (preg_match($re, $src, $m)) {
                return ['provider' => $key, 'source_id' => $m[1],
                        'embed_url' => str_replace('{id}', $m[1], $p['embed']),
                        'source_url' => $src];
            }
        }
    }
    // Iframe de um site não listado: aceita o src como embed "custom"
    if (stripos($input, '<iframe') !== false && is_http_url($src)) {
        return ['provider' => 'custom', 'source_id' => substr(md5($src), 0, 16), 'embed_url' => $src, 'source_url' => $src];
    }
    return null;
}

/* ---------------- HTTP ---------------- */
function http_get(string $url, int $timeout = 15): ?string {
    if (!function_exists('curl_init')) return null;
    [$code, $body] = safe_curl_get($url, [
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_ENCODING => '', CURLOPT_MAXFILESIZE => 8 * 1024 * 1024,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept-Language: pt-BR,pt;q=0.9,en;q=0.8'],
        CURLOPT_COOKIE => 'age_verified=1; platform=pc; accessAgeDisclaimerPH=1; accessAgeDisclaimerUK=1',
    ], 5);
    return ($body !== null && $code >= 200 && $code < 300) ? $body : null;
}
function http_json(string $url): ?array {
    $b = http_get($url);
    $j = $b ? json_decode($b, true) : null;
    return is_array($j) ? $j : null;
}

/* ---------------- Metadados ---------------- */
function importer_fetch_meta(array $p): array {
    $meta = ['title' => '', 'thumb' => '', 'thumbs' => [], 'duration' => 0, 'tags' => [], 'description' => ''];
    $api = importer_providers()[$p['provider']]['api'] ?? null;

    if ($api === 'pornhub') {
        $j = http_json('https://www.pornhub.com/webmasters/video_by_id?id=' . urlencode($p['source_id']) . '&thumbsize=large');
        if (!empty($j['video'])) $meta = importer_map_pornhub($j['video']);
    } elseif ($api === 'redtube') {
        $j = http_json('https://api.redtube.com/?data=redtube.Videos.getVideoById&output=json&thumbsize=big&video_id=' . urlencode($p['source_id']));
        if (!empty($j['video'])) $meta = importer_map_redtube($j['video']);
    } elseif ($api === 'eporner') {
        $j = http_json('https://www.eporner.com/api/v2/video/id/?thumbsize=big&format=json&id=' . urlencode($p['source_id']));
        if (!empty($j['id'])) $meta = importer_map_eporner($j);
    }

    if ($meta['title'] === '' && $p['provider'] !== 'custom') {
        $html = http_get($p['source_url']);
        if ($html) $meta = array_merge($meta, array_filter(importer_scrape_meta($html)));
    }
    // thumb com caminho relativo -> absoluto
    if ($meta['thumb'] !== '' && str_starts_with($meta['thumb'], '//')) $meta['thumb'] = 'https:' . $meta['thumb'];
    elseif ($meta['thumb'] !== '' && str_starts_with($meta['thumb'], '/')) {
        $u = parse_url($p['source_url']);
        $meta['thumb'] = ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? '') . $meta['thumb'];
    }
    return $meta;
}

/** Lê og:title, og:image, duração e tags (meta tags + JSON-LD) */
function importer_scrape_meta(string $html): array {
    $get = function (array $names) use ($html) {
        foreach ($names as $n) {
            $q = preg_quote($n, '~');
            if (preg_match('~<meta[^>]+(?:property|name|itemprop)=["\']' . $q . '["\'][^>]*content=["\']([^"\']*)["\']~i', $html, $m)
             || preg_match('~<meta[^>]+content=["\']([^"\']*)["\'][^>]*(?:property|name|itemprop)=["\']' . $q . '["\']~i', $html, $m))
                return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
        return '';
    };
    $r = [
        'title'       => $get(['og:title', 'twitter:title']),
        'thumb'       => $get(['og:image', 'twitter:image', 'thumbnailUrl']),
        'duration'    => parse_duration($get(['video:duration', 'og:video:duration', 'duration'])),
        'description' => $get(['og:description', 'description']),
        'tags'        => split_list($get(['keywords', 'video:tag'])),
    ];
    if (preg_match_all('~<script[^>]+application/ld\+json[^>]*>(.*?)</script>~is', $html, $mm)) {
        foreach ($mm[1] as $js) {
            $d = json_decode(trim($js), true);
            if (!is_array($d)) continue;
            foreach ((isset($d['@type']) ? [$d] : (array)$d) as $o) {
                if (!is_array($o) || ($o['@type'] ?? '') !== 'VideoObject') continue;
                if (!$r['title'] && !empty($o['name'])) $r['title'] = html_entity_decode($o['name'], ENT_QUOTES, 'UTF-8');
                if (!$r['duration'] && !empty($o['duration'])) $r['duration'] = parse_duration($o['duration']);
                if (!$r['thumb'] && !empty($o['thumbnailUrl'])) $r['thumb'] = is_array($o['thumbnailUrl']) ? $o['thumbnailUrl'][0] : $o['thumbnailUrl'];
                if (!$r['tags'] && !empty($o['keywords'])) $r['tags'] = is_array($o['keywords']) ? $o['keywords'] : split_list($o['keywords']);
            }
        }
    }
    if (!$r['title'] && preg_match('~<title>(.*?)</title>~is', $html, $m)) $r['title'] = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
    $r['title'] = trim(preg_replace('~\s*[-|]\s*(XVIDEOS\.COM|XNXX\.COM|xHamster|Pornhub\.com|YouPorn|SpankBang|EPORNER).*$~i', '', $r['title']));
    $r['tags'] = array_slice(array_values(array_unique(array_map('trim', $r['tags']))), 0, 25);
    return $r;
}

/* ---------------- Mapeadores das APIs ---------------- */
/** Melhor thumb: a maior disponível (as APIs mandam várias) */
function importer_best_thumb(array $v): string {
    foreach (['default_thumb', 'thumb'] as $k) {
        $t = $v[$k] ?? '';
        if (is_array($t)) $t = $t['src'] ?? '';
        if (is_string($t) && $t !== '') return str_starts_with($t, '//') ? 'https:' . $t : $t;
    }
    $f = importer_frames($v['thumbs'] ?? []);
    return $f[0] ?? '';
}
/** Lista de quadros (frames) do vídeo, para a prévia animada */
function importer_frames($list): array {
    $out = [];
    foreach ((array)$list as $t) {
        $u = is_array($t) ? ($t['src'] ?? '') : (string)$t;
        if ($u !== '') $out[] = str_starts_with($u, '//') ? 'https:' . $u : $u;
    }
    $out = array_values(array_unique($out));
    // pega quadros espalhados pelo vídeo (não só os primeiros)
    if (count($out) > 6) { $step = count($out) / 6; $pick = []; for ($i = 0; $i < 6; $i++) $pick[] = $out[(int)floor($i * $step)]; $out = $pick; }
    return $out;
}
function importer_map_pornhub(array $v): array {
    return [
        'source_id' => $v['video_id'] ?? '', 'title' => html_entity_decode($v['title'] ?? '', ENT_QUOTES, 'UTF-8'),
        'thumb' => importer_best_thumb($v), 'thumbs' => importer_frames($v['thumbs'] ?? []), 'duration' => parse_duration($v['duration'] ?? 0),
        'tags' => array_slice(array_column($v['tags'] ?? [], 'tag_name'), 0, 25), 'description' => '',
        'source_url' => $v['url'] ?? '',
    ];
}
function importer_map_redtube(array $v): array {
    return [
        'source_id' => (string)($v['video_id'] ?? ''), 'title' => html_entity_decode($v['title'] ?? '', ENT_QUOTES, 'UTF-8'),
        'thumb' => importer_best_thumb($v), 'thumbs' => importer_frames($v['thumbs'] ?? []), 'duration' => parse_duration($v['duration'] ?? 0),
        'tags' => array_slice(array_column($v['tags'] ?? [], 'tag_name'), 0, 25), 'description' => '',
        'source_url' => $v['url'] ?? '',
    ];
}
function importer_map_eporner(array $v): array {
    return [
        'source_id' => $v['id'] ?? '', 'title' => html_entity_decode($v['title'] ?? '', ENT_QUOTES, 'UTF-8'),
        'thumb' => $v['default_thumb']['src'] ?? '', 'thumbs' => importer_frames($v['thumbs'] ?? []), 'duration' => (int)($v['length_sec'] ?? 0),
        'tags' => array_slice(split_list($v['keywords'] ?? ''), 0, 25), 'description' => '',
        'source_url' => $v['url'] ?? '',
    ];
}

/** Busca por palavra-chave nas APIs públicas. Retorna lista já no formato de importação. */
/**
 * Busca por palavra-chave. Retorna ['items' => [...], 'error' => mensagem clara ou '' ].
 */
function importer_search_full(string $provider, string $q, int $page = 1, string $order = 'recentes'): array {
    $out = []; $raw = null; $isJson = true; $empty = false;
    if ($provider === 'pornhub') {
        $ord = ['recentes' => 'newest', 'vistos' => 'mostviewed', 'curtidos' => 'rating'][$order] ?? 'newest';
        $raw = http_get('https://www.pornhub.com/webmasters/search?thumbsize=large&ordering=' . $ord . '&page=' . $page . '&search=' . urlencode($q));
        $j = $raw ? json_decode($raw, true) : null;
        foreach ($j['videos'] ?? [] as $v) $out[] = importer_map_pornhub($v) + ['provider' => 'pornhub'];
        $empty = is_array($j) && (isset($j['code']) || array_key_exists('videos', $j));
    } elseif ($provider === 'redtube') {
        $ord = ['recentes' => 'newest', 'vistos' => 'mostviewed', 'curtidos' => 'rating'][$order] ?? 'newest';
        $raw = http_get('https://api.redtube.com/?data=redtube.Videos.searchVideos&output=json&thumbsize=big&ordering=' . $ord . '&page=' . $page . '&search=' . urlencode($q));
        $j = $raw ? json_decode($raw, true) : null;
        foreach ($j['videos'] ?? [] as $w) if (!empty($w['video'])) $out[] = importer_map_redtube($w['video']) + ['provider' => 'redtube'];
        $empty = is_array($j);
    } elseif ($provider === 'eporner') {
        $ord = ['recentes' => 'latest', 'vistos' => 'most-popular', 'curtidos' => 'top-rated'][$order] ?? 'latest';
        $raw = http_get('https://www.eporner.com/api/v2/video/search/?per_page=100&thumbsize=big&format=json&order=' . $ord . '&page=' . $page . '&query=' . urlencode($q));
        $j = $raw ? json_decode($raw, true) : null;
        foreach ($j['videos'] ?? [] as $v) $out[] = importer_map_eporner($v) + ['provider' => 'eporner'];
        $empty = is_array($j);
    } elseif ($provider === 'xvideos') {
        $isJson = false;
        $ord = ['recentes' => 'uploaddate', 'vistos' => 'views', 'curtidos' => 'rating'][$order] ?? 'relevance';
        $raw = http_get('https://www.xvideos.com/?k=' . urlencode($q) . '&sort=' . $ord . ($page > 1 ? '&p=' . ($page - 1) : ''));
        if ($raw) $out = importer_parse_xvideos_search($raw);
        $empty = $raw !== null && stripos($raw, 'thumb-block') === false && stripos($raw, 'mozaique') !== false;
    }
    $prov = importer_providers();
    foreach ($out as &$r) {
        $r['embed_url'] = str_replace('{id}', $r['source_id'], $prov[$r['provider']]['embed']);
        $r['exists'] = importer_exists($r['provider'], $r['source_id']);
    }
    unset($r);
    $error = $out ? '' : http_explain(http_last(), $raw, $isJson, $empty);
    return ['items' => $out, 'error' => $error];
}
function importer_search(string $provider, string $q, int $page = 1, string $order = 'recentes'): array {
    return importer_search_full($provider, $q, $page, $order)['items'];
}

/** Lê a página de busca do XVideos (não tem API: lê o HTML) */
function importer_parse_xvideos_search(string $html): array {
    $out = []; $seen = [];
    $chunks = preg_split('~(?=<div\b[^>]*\bthumb-block\b)~i', $html);
    array_shift($chunks);
    foreach ($chunks as $c) {
        if (!preg_match('~href="(/video\.?([a-z0-9]+)/[^"]*)"~i', $c, $m)) continue;
        $num = preg_match('~data-id="(\d+)"~', $c, $mm) ? $mm[1] : '';
        $code = $m[2];
        $id = $num !== '' ? $num : $code;
        if (isset($seen[$id])) continue;
        $seen[$id] = 1;
        $title = '';
        if (preg_match('~<p class="title"[^>]*>\s*<a[^>]+title="([^"]+)"~i', $c, $t) || preg_match('~<a[^>]+href="/video[^"]*"[^>]+title="([^"]+)"~i', $c, $t)) $title = $t[1];
        $thumb = preg_match('~data-src="(https?://[^"]+\.(?:jpg|jpeg|webp))"~i', $c, $t) ? $t[1] : (preg_match('~<img[^>]+src="(https?://[^"]+\.(?:jpg|jpeg|webp))"~i', $c, $t) ? $t[1] : '');
        $dur = 0;
        if (preg_match('~class="duration"[^>]*>([^<]+)<~i', $c, $d)) {
            $txt = strtolower($d[1]);
            if (preg_match('~(\d+)\s*h~', $txt, $x)) $dur += (int)$x[1] * 3600;
            if (preg_match('~(\d+)\s*min~', $txt, $x)) $dur += (int)$x[1] * 60;
            if (preg_match('~(\d+)\s*s(?:ec)?~', $txt, $x)) $dur += (int)$x[1];
        }
        // quadros da prévia: as thumbs do XVideos terminam em .N.jpg (1 a 30)
        $frames = [];
        if (preg_match('~^(.*\.)(\d{1,2})(\.jpg)$~', $thumb, $f)) foreach ([3, 8, 13, 18, 23, 28] as $n) if ((string)$n !== $f[2]) $frames[] = $f[1] . $n . $f[3];
        $out[] = ['provider' => 'xvideos', 'source_id' => $id, 'title' => html_entity_decode($title, ENT_QUOTES, 'UTF-8') ?: ('Vídeo ' . $id),
                  'thumb' => $thumb, 'thumbs' => array_slice($frames, 0, 5), 'duration' => $dur, 'tags' => [], 'description' => '',
                  'source_url' => 'https://www.xvideos.com' . html_entity_decode($m[1])];
    }
    return $out;
}

/** Explica em português por que uma busca não trouxe nada */
function http_explain(array $i, ?string $body, bool $expectJson = true, bool $emptyButOk = false): string {
    $code = (int)($i['code'] ?? 0);
    if (!empty($i['why'])) return 'conexão recusada por segurança (' . $i['why'] . ')';
    switch ((int)($i['errno'] ?? 0)) {
        case 6: return 'o servidor não conseguiu achar o endereço do site (DNS da hospedagem)';
        case 7: return 'a hospedagem bloqueou a conexão de saída (firewall do servidor)';
        case 28: return 'o site demorou demais para responder';
        case 35: case 51: case 58: case 60: case 77: return 'erro de certificado SSL na hospedagem (cURL desatualizado)';
        case 52: case 56: return 'o site fechou a conexão (bloqueio do site ou da hospedagem)';
    }
    if ($code === 0 && !empty($i['error'])) return 'falha de conexão: ' . $i['error'];
    if (in_array($code, [401, 403, 451], true)) return "o site bloqueou o seu servidor (HTTP $code) — geralmente é bloqueio por país ou de datacenter";
    if ($code === 404) return "o endereço de busca do site não existe mais (HTTP 404) — o site mudou a API";
    if ($code === 429) return 'o site limitou as buscas (muitas em pouco tempo). Espere alguns minutos';
    if ($code >= 500) return "o site está com problema agora (HTTP $code)";
    if ($code >= 200 && $code < 300) {
        if ($emptyButOk) return 'o site respondeu, mas não tem (mais) vídeos para essa busca';
        $b = strtolower(substr((string)$body, 0, 4000));
        if (str_contains($b, 'cloudflare') || str_contains($b, 'cf-chl') || str_contains($b, 'captcha')) return 'o site pediu verificação anti-robô para o seu servidor';
        if (str_contains($b, 'age') && (str_contains($b, 'verif') || str_contains($b, 'restricted') || str_contains($b, 'not available'))) return 'o site mostrou uma página de bloqueio/verificação de idade (bloqueio por país)';
        return $expectJson ? 'o site respondeu algo que não é a lista de vídeos (provável bloqueio por país)' : 'não consegui ler a página de busca do site';
    }
    return $code ? "resposta inesperada (HTTP $code)" : 'sem resposta do site';
}

/** Testa a conexão com cada site e informa o país/IP do servidor */
function importer_diagnose(): array {
    $res = ['server' => [
        'php' => PHP_VERSION, 'curl' => function_exists('curl_version') ? (curl_version()['version'] . ' / ' . (curl_version()['ssl_version'] ?? '')) : 'NÃO INSTALADO',
        'dns' => function_exists('gethostbynamel') ? (host_ips('www.eporner.com') ? 'ok' : 'não resolve') : 'gethostbynamel desativado',
        'ip' => '', 'country' => '',
        'gd' => function_exists('imagecreatefromstring') ? ('ok' . (function_exists('imagewebp') ? ' (WebP)' : ' (sem WebP: usa JPG)')) : 'NÃO ATIVO',
        'folder' => thumbs_dir() !== null ? 'ok' : 'SEM PERMISSÃO',
        'problem' => thumbs_problem(),
    ], 'sites' => []];
    [$c, $b] = safe_curl_get('https://ipinfo.io/json', [CURLOPT_TIMEOUT => 8]);
    if ($c === 200 && ($j = json_decode((string)$b, true))) { $res['server']['ip'] = $j['ip'] ?? ''; $res['server']['country'] = trim(($j['country'] ?? '') . ' ' . ($j['city'] ?? '')); }
    foreach (importer_search_providers() as $k => $name) {
        $t = microtime(true);
        $r = importer_search_full($k, 'amateur', 1, 'recentes');
        $i = http_last();
        $thumbOk = null; $thumbMsg = '';
        if (!empty($r['items'][0]['thumb'])) {
            [$tc, $tb] = safe_curl_get($r['items'][0]['thumb'], img_curl_opts(provider_referer($k)) + [CURLOPT_TIMEOUT => 12], 3);
            $thumbOk = $tc === 200 && is_string($tb) && img_open($tb) !== null;
            $thumbMsg = $thumbOk ? round(strlen($tb) / 1024) . ' KB' : http_explain(http_last(), $tb, false);
        }
        $res['sites'][] = ['site' => $name, 'ok' => count($r['items']) > 0, 'count' => count($r['items']),
            'http' => (int)($i['code'] ?? 0), 'time' => round(microtime(true) - $t, 1), 'msg' => $r['error'],
            'thumb' => $r['items'][0]['thumb'] ?? '', 'thumb_ok' => $thumbOk, 'thumb_msg' => $thumbMsg];
    }
    return $res;
}

function importer_exists(string $provider, string $sourceId): bool {
    return db()->count('videos', ['where' => ['provider' => $provider, 'source_id' => $sourceId]]) > 0;
}

/**
 * Salva o vídeo. $d precisa de: provider, source_id, embed_url, title.
 * Retorna [true, id] ou [false, 'motivo'].
 */
function importer_save(array $d, array $opts = []): array {
    if (empty($d['embed_url']) || empty($d['provider']) || !is_http_url($d['embed_url'])) return [false, 'Embed inválido'];
    if (!empty($d['source_url']) && !is_http_url($d['source_url'])) $d['source_url'] = '';
    if (importer_exists($d['provider'], (string)$d['source_id'])) return [false, 'Já existe no site'];
    $title = trim($d['title'] ?? '') ?: ('Vídeo ' . ($d['source_id'] ?? ''));
    $tags = is_array($d['tags'] ?? null) ? implode(', ', $d['tags']) : (string)($d['tags'] ?? '');
    $cats = $opts['categories'] ?? ($d['categories'] ?? []);
    $frames = is_array($d['thumbs'] ?? null) ? $d['thumbs'] : split_list($d['thumbs'] ?? '');
    if (!empty($opts['thumbs_now'])) {
        $th = thumbs_store($d['provider'], (string)($d['source_id'] ?? ''), (string)($d['thumb'] ?? ''), $frames);
    } else {
        // Importação rápida: grava o vídeo já e baixa as thumbs em segundo plano
        [$t0, $f0] = thumbs_clean_list((string)($d['thumb'] ?? ''), $frames);
        $th = setting_on('thumbs_local')
            ? ['thumb' => $t0, 'thumbs' => ($t0 !== '' || $f0) ? thumbs_pending_value($f0) : '']
            : ['thumb' => $t0, 'thumbs' => implode(',', $f0)];   // modo "direto do site de origem": guarda só os links
    }
    if (!empty($opts['auto_categories'])) $cats = array_unique(array_merge($cats, importer_guess_categories($title . ' ' . $tags)));
    $id = db()->insert('videos', [
        'title' => mb_substr($title, 0, 250), 'slug' => unique_slug('videos', $title),
        'description' => (string)($d['description'] ?? ''), 'provider' => $d['provider'],
        'source_url' => (string)($d['source_url'] ?? ''), 'source_id' => (string)($d['source_id'] ?? ''),
        'embed_url' => $d['embed_url'], 'thumb' => $th['thumb'], 'thumbs' => $th['thumbs'],
        'duration' => (int)($d['duration'] ?? 0), 'tags' => $tags,
        'categories' => implode(',', array_map('intval', (array)$cats)), 'models' => (string)($d['models'] ?? ''),
        'views' => 0, 'likes' => 0, 'dislikes' => 0, 'featured' => !empty($d['featured']) ? 1 : 0,
        'status' => $opts['status'] ?? 'published', 'created_at' => now(),
    ]);
    return [true, $id];
}

/** Liga o vídeo a categorias cujo nome aparece no título/tags */
function importer_guess_categories(string $text): array {
    $text = '-' . strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text), '-')) . '-';
    $ids = [];
    foreach (all_categories() as $c) {
        $s = $c['slug'];
        // aceita singular: loiras→loira, amadores→amador, casais→casal
        $forms = array_unique([$s, rtrim($s, 's'), preg_replace('/es$/', '', $s), preg_replace('/ais$/', 'al', $s)]);
        foreach ($forms as $f) if (strlen($f) > 2 && str_contains($text, "-$f-")) { $ids[] = (int)$c['id']; break; }
    }
    return $ids;
}
