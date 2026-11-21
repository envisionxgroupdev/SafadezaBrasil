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
    return ['pornhub' => 'Pornhub', 'redtube' => 'RedTube', 'eporner' => 'Eporner'];
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
    if (stripos($input, '<iframe') !== false && filter_var($src, FILTER_VALIDATE_URL)) {
        return ['provider' => 'custom', 'source_id' => substr(md5($src), 0, 16), 'embed_url' => $src, 'source_url' => $src];
    }
    return null;
}

/* ---------------- HTTP ---------------- */
function http_get(string $url, int $timeout = 15): ?string {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept-Language: pt-BR,pt;q=0.9,en;q=0.8'],
        CURLOPT_COOKIE => 'age_verified=1; platform=pc; accessAgeDisclaimerPH=1; accessAgeDisclaimerUK=1',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $code >= 200 && $code < 400) ? $body : null;
}
function http_json(string $url): ?array {
    $b = http_get($url);
    $j = $b ? json_decode($b, true) : null;
    return is_array($j) ? $j : null;
}

/* ---------------- Metadados ---------------- */
function importer_fetch_meta(array $p): array {
    $meta = ['title' => '', 'thumb' => '', 'duration' => 0, 'tags' => [], 'description' => ''];
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
function importer_map_pornhub(array $v): array {
    return [
        'source_id' => $v['video_id'] ?? '', 'title' => html_entity_decode($v['title'] ?? '', ENT_QUOTES, 'UTF-8'),
        'thumb' => $v['default_thumb'] ?? ($v['thumb'] ?? ''), 'duration' => parse_duration($v['duration'] ?? 0),
        'tags' => array_slice(array_column($v['tags'] ?? [], 'tag_name'), 0, 25), 'description' => '',
        'source_url' => $v['url'] ?? '',
    ];
}
function importer_map_redtube(array $v): array {
    return [
        'source_id' => (string)($v['video_id'] ?? ''), 'title' => html_entity_decode($v['title'] ?? '', ENT_QUOTES, 'UTF-8'),
        'thumb' => $v['default_thumb'] ?? ($v['thumb'] ?? ''), 'duration' => parse_duration($v['duration'] ?? 0),
        'tags' => array_slice(array_column($v['tags'] ?? [], 'tag_name'), 0, 25), 'description' => '',
        'source_url' => $v['url'] ?? '',
    ];
}
function importer_map_eporner(array $v): array {
    return [
        'source_id' => $v['id'] ?? '', 'title' => html_entity_decode($v['title'] ?? '', ENT_QUOTES, 'UTF-8'),
        'thumb' => $v['default_thumb']['src'] ?? '', 'duration' => (int)($v['length_sec'] ?? 0),
        'tags' => array_slice(split_list($v['keywords'] ?? ''), 0, 25), 'description' => '',
        'source_url' => $v['url'] ?? '',
    ];
}

/** Busca por palavra-chave nas APIs públicas. Retorna lista já no formato de importação. */
function importer_search(string $provider, string $q, int $page = 1, string $order = 'recentes'): array {
    $out = [];
    if ($provider === 'pornhub') {
        $ord = ['recentes' => 'newest', 'vistos' => 'mostviewed', 'curtidos' => 'rating'][$order] ?? 'newest';
        $j = http_json('https://www.pornhub.com/webmasters/search?thumbsize=large&ordering=' . $ord . '&page=' . $page . '&search=' . urlencode($q));
        foreach ($j['videos'] ?? [] as $v) $out[] = importer_map_pornhub($v) + ['provider' => 'pornhub'];
    } elseif ($provider === 'redtube') {
        $ord = ['recentes' => 'newest', 'vistos' => 'mostviewed', 'curtidos' => 'rating'][$order] ?? 'newest';
        $j = http_json('https://api.redtube.com/?data=redtube.Videos.searchVideos&output=json&thumbsize=big&ordering=' . $ord . '&page=' . $page . '&search=' . urlencode($q));
        foreach ($j['videos'] ?? [] as $w) if (!empty($w['video'])) $out[] = importer_map_redtube($w['video']) + ['provider' => 'redtube'];
    } elseif ($provider === 'eporner') {
        $ord = ['recentes' => 'latest', 'vistos' => 'most-popular', 'curtidos' => 'top-rated'][$order] ?? 'latest';
        $j = http_json('https://www.eporner.com/api/v2/video/search/?per_page=30&thumbsize=big&format=json&order=' . $ord . '&page=' . $page . '&query=' . urlencode($q));
        foreach ($j['videos'] ?? [] as $v) $out[] = importer_map_eporner($v) + ['provider' => 'eporner'];
    }
    $prov = importer_providers();
    foreach ($out as &$r) {
        $r['embed_url'] = str_replace('{id}', $r['source_id'], $prov[$r['provider']]['embed']);
        $r['exists'] = importer_exists($r['provider'], $r['source_id']);
    }
    return $out;
}

function importer_exists(string $provider, string $sourceId): bool {
    return db()->count('videos', ['where' => ['provider' => $provider, 'source_id' => $sourceId]]) > 0;
}

/**
 * Salva o vídeo. $d precisa de: provider, source_id, embed_url, title.
 * Retorna [true, id] ou [false, 'motivo'].
 */
function importer_save(array $d, array $opts = []): array {
    if (empty($d['embed_url']) || empty($d['provider'])) return [false, 'Embed inválido'];
    if (importer_exists($d['provider'], (string)$d['source_id'])) return [false, 'Já existe no site'];
    $title = trim($d['title'] ?? '') ?: ('Vídeo ' . ($d['source_id'] ?? ''));
    $tags = is_array($d['tags'] ?? null) ? implode(', ', $d['tags']) : (string)($d['tags'] ?? '');
    $cats = $opts['categories'] ?? ($d['categories'] ?? []);
    if (!empty($opts['auto_categories'])) $cats = array_unique(array_merge($cats, importer_guess_categories($title . ' ' . $tags)));
    $id = db()->insert('videos', [
        'title' => mb_substr($title, 0, 250), 'slug' => unique_slug('videos', $title),
        'description' => (string)($d['description'] ?? ''), 'provider' => $d['provider'],
        'source_url' => (string)($d['source_url'] ?? ''), 'source_id' => (string)($d['source_id'] ?? ''),
        'embed_url' => $d['embed_url'], 'thumb' => (string)($d['thumb'] ?? ''),
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
