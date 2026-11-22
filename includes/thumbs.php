<?php
/**
 * ============================================================
 *  THUMBS — leves e sempre funcionando
 * ============================================================
 *  Na importação a thumb é baixada para /uploads/thumbs:
 *   - thumb principal: WebP 480px (~10–25 KB)
 *   - prévia animada: TODOS os quadros juntos numa única imagem
 *     ("sprite") WebP (~20–35 KB)
 *  Total: ~40 KB e 2 arquivos por vídeo (antes eram ~300 KB e 7 arquivos).
 *  Exemplo: 10.000 vídeos ≈ 400 MB.
 *
 *  Formato da coluna `thumbs`:
 *    "sprite:uploads/thumbs/202611/x-s.webp:6"  (novo)
 *    "a.jpg,b.jpg,..."                          (antigo, ainda funciona)
 * ============================================================
 */
const THUMB_DIR = 'uploads/thumbs';
const THUMB_MAX_BYTES = 5 * 1024 * 1024;

function thumb_width(): int { $w = (int)setting('thumb_width', 480); return in_array($w, [400, 480, 640], true) ? $w : 480; }
function thumb_quality(): int { return thumb_width() >= 640 ? 80 : 72; }

function thumb_url(?string $t): string {
    $t = trim((string)$t);
    if ($t === '') return base_url('assets/img/no-thumb.svg');
    return preg_match('~^https?://~i', $t) ? $t : base_url($t);
}
function thumb_abs(?string $t): string {
    $t = trim((string)$t);
    if ($t === '') return '';
    return preg_match('~^https?://~i', $t) ? $t : absolute_url($t);
}
function thumb_is_local(?string $t): bool { return $t !== null && $t !== '' && !preg_match('~^https?://~i', $t); }

/** IPs de um domínio (com plano B quando a hospedagem desativa gethostbynamel) */
function host_ips(string $host): array {
    if (filter_var($host, FILTER_VALIDATE_IP)) return [$host];
    $ips = function_exists('gethostbynamel') ? (@gethostbynamel($host) ?: []) : [];
    if (!$ips && function_exists('dns_get_record')) {
        foreach ((array)@dns_get_record($host, DNS_A | DNS_AAAA) as $r) { $ip = $r['ip'] ?? ($r['ipv6'] ?? null); if ($ip) $ips[] = $ip; }
    }
    return array_values(array_unique(array_filter($ips)));
}
/** A hospedagem usa um proxy de saída? (aí o IP conectado é o do proxy, não o do site) */
function via_proxy(): bool { return (string)(getenv('https_proxy') ?: getenv('HTTPS_PROXY') ?: getenv('all_proxy') ?: '') !== ''; }
function ip_is_public(string $ip): bool {
    return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/**
 * Proteção SSRF: só http/https e só endereços públicos da internet.
 * Retorna ['ok' => bool, 'pin' => "host:porta:ip" ou null, 'why' => motivo].
 * O "pin" fixa o IP na conexão (impede trocar o DNS no meio do caminho). Se o DNS do PHP
 * não funcionar na hospedagem, deixa o cURL resolver e confere o IP depois de conectar.
 */
function url_guard(string $url): array {
    $p = parse_url($url);
    if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host']) || isset($p['user']) || isset($p['pass']))
        return ['ok' => false, 'pin' => null, 'why' => 'link inválido'];
    $host = strtolower(trim($p['host'], '[]'));
    $port = (int)($p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80));
    if (!in_array($port, [80, 443, 8080, 8443], true)) return ['ok' => false, 'pin' => null, 'why' => 'porta não permitida'];
    if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) return ['ok' => false, 'pin' => null, 'why' => 'endereço interno bloqueado'];
    $ips = host_ips($host);
    if (!$ips) return ['ok' => true, 'pin' => null, 'why' => ''];
    foreach ($ips as $ip) if (!ip_is_public($ip)) return ['ok' => false, 'pin' => null, 'why' => 'endereço interno bloqueado'];
    $ip = $ips[0];
    return ['ok' => true, 'pin' => "$host:$port:" . (str_contains($ip, ':') ? "[$ip]" : $ip), 'why' => ''];
}
function url_public_resolve(string $url): ?string { $g = url_guard($url); return $g['ok'] ? ($g['pin'] ?? '') : null; }
function url_is_public(string $url): bool { return url_guard($url)['ok']; }

/** Último resultado de rede (para mensagens de erro claras) */
function http_last(?array $set = null): array { static $last = []; if ($set !== null) $last = $set; return $last; }

/** GET seguro: confere cada redirecionamento e fixa o IP. Retorna [código, corpo, info] */
function safe_curl_get(string $url, array $opts = [], int $maxRedirects = 4): array {
    $info = ['url' => $url, 'errno' => 0, 'error' => '', 'ip' => '', 'time' => 0, 'ctype' => '', 'why' => ''];
    for ($i = 0; $i <= $maxRedirects; $i++) {
        if (str_starts_with($url, '//')) $url = 'https:' . $url;
        $g = url_guard($url);
        if (!$g['ok']) { $info['why'] = $g['why']; http_last($info + ['code' => 0]); return [0, null, $info]; }
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts + [CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_ENCODING => '']);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
        if ($g['pin']) curl_setopt($ch, CURLOPT_RESOLVE, [$g['pin']]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $info = ['url' => $url, 'errno' => curl_errno($ch), 'error' => curl_error($ch), 'ip' => (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP),
                 'time' => round((float)curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2), 'ctype' => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE), 'why' => ''];
        curl_close($ch);
        if (!$g['pin'] && !via_proxy() && $info['ip'] !== '' && !ip_is_public($info['ip'])) { $info['why'] = 'endereço interno bloqueado'; http_last($info + ['code' => 0]); return [0, null, $info]; }
        if ($code >= 300 && $code < 400 && $loc !== '') { $url = $loc; continue; }
        http_last($info + ['code' => $code]);
        return [$code, $body === false ? null : $body, $info];
    }
    http_last($info + ['code' => 0, 'why' => 'redirecionamentos demais']);
    return [0, null, $info];
}

function provider_referer(string $provider): string {
    $map = ['xvideos' => 'https://www.xvideos.com/', 'xnxx' => 'https://www.xnxx.com/', 'pornhub' => 'https://www.pornhub.com/',
            'xhamster' => 'https://xhamster.com/', 'redtube' => 'https://www.redtube.com/', 'youporn' => 'https://www.youporn.com/',
            'spankbang' => 'https://spankbang.com/', 'eporner' => 'https://www.eporner.com/', 'tube8' => 'https://www.tube8.com/', 'txxx' => 'https://www.txxx.com/'];
    return $map[$provider] ?? '';
}

/* ---------------- Imagens ---------------- */
/** Confere que os bytes são uma imagem de verdade e abre com o GD */
function img_open(string $bytes) {
    if ($bytes === '' || strlen($bytes) > THUMB_MAX_BYTES || !function_exists('imagecreatefromstring')) return null;
    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) return null;
    if ($info[0] < 80 || $info[1] < 45 || $info[0] * $info[1] > 9000000) return null; // pixel de rastreio / gigante
    return @imagecreatefromstring($bytes) ?: null;
}
/** Recorta no centro em 16:9 e redimensiona (o card é 16:9: o resto seria desperdício) */
function img_cover($src, int $w, int $h) {
    $sw = imagesx($src); $sh = imagesy($src);
    $r = $w / $h;
    if ($sw / $sh > $r) { $cw = (int)round($sh * $r); $ch = $sh; $cx = (int)(($sw - $cw) / 2); $cy = 0; }
    else { $cw = $sw; $ch = (int)round($sw / $r); $cx = 0; $cy = (int)(($sh - $ch) / 2); }
    $dst = imagecreatetruecolor($w, $h);
    imagecopyresampled($dst, $src, 0, 0, $cx, $cy, $w, $h, $cw, $ch);
    return $dst;
}
/** Grava em WebP (ou JPG se o servidor não tiver WebP). Retorna [bytes, extensão]. */
function img_encode($im, int $q): array {
    ob_start();
    if (function_exists('imagewebp')) { imagewebp($im, null, $q); $ext = 'webp'; }
    else { imagejpeg($im, null, min(90, $q + 6)); $ext = 'jpg'; }
    return [ob_get_clean(), $ext];
}
/** Usado pela prévia do painel: valida e devolve [bytes, ext] */
function thumb_process(string $bytes): ?array {
    $src = img_open($bytes);
    if (!$src) return null;
    $w = thumb_width();
    $im = img_cover($src, $w, (int)round($w * 9 / 16));
    $out = img_encode($im, thumb_quality());
    imagedestroy($src); imagedestroy($im);
    return $out[0] ? $out : null;
}

/* ---------------- Download ---------------- */
function img_curl_opts(string $referer): array {
    return [
        CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
        CURLOPT_HTTPHEADER => array_values(array_filter(['Accept: image/avif,image/webp,image/*,*/*;q=0.8', $referer ? 'Referer: ' . $referer : null])),
        CURLOPT_MAXFILESIZE => THUMB_MAX_BYTES,
    ];
}
/** Baixa várias imagens em paralelo. Retorna [indice => bytes] das que responderam. */
function fetch_images(array $urls, string $referer): array {
    if (!function_exists('curl_multi_init')) return [];
    $mh = curl_multi_init(); $hs = []; $redirects = []; $pins = [];
    if (defined('CURLMOPT_MAX_TOTAL_CONNECTIONS')) { curl_multi_setopt($mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, 8); curl_multi_setopt($mh, CURLMOPT_MAX_HOST_CONNECTIONS, 4); }
    foreach ($urls as $i => $u) {
        if (!is_string($u)) continue;
        if (str_starts_with($u, '//')) $u = 'https:' . $u;
        $pin = url_public_resolve($u);
        if ($pin === null) continue;
        $ch = curl_init($u);
        curl_setopt_array($ch, img_curl_opts($referer) + [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
        if ($pin !== '') curl_setopt($ch, CURLOPT_RESOLVE, [$pin]);
        $pins[$i] = $pin;
        curl_multi_add_handle($mh, $ch);
        $hs[$i] = $ch;
    }
    do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1.0); } while ($run && $st === CURLM_OK);
    $out = [];
    foreach ($hs as $i => $ch) {
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body = curl_multi_getcontent($ch);
        $pip = (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        if (($pins[$i] ?? '') === '' && !via_proxy() && $pip !== '' && !ip_is_public($pip)) $code = 0;
        if ($code === 200 && is_string($body) && $body !== '') $out[$i] = $body;
        elseif ($code >= 300 && $code < 400 && ($loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL)) !== '') $redirects[$i] = $loc;
        curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
    curl_multi_close($mh);
    // Redirecionamentos (raros): segue um por um, conferindo cada destino
    foreach ($redirects as $i => $loc) {
        [$code, $body] = safe_curl_get($loc, img_curl_opts($referer), 3);
        if ($code === 200 && is_string($body) && $body !== '') $out[$i] = $body;
    }
    return $out;
}
/** Baixa UMA imagem (prévia do painel). Retorna [bytes, ext] já validado. */
function thumb_fetch(string $url, string $referer = ''): ?array {
    $r = fetch_images([$url], $referer);
    return isset($r[0]) ? thumb_process($r[0]) : null;
}

/* ---------------- Salvar ---------------- */
/** Pasta onde as thumbs são gravadas (cria se precisar). Retorna o caminho relativo ou null se não der para gravar. */
function thumbs_dir(): ?string {
    $dir = THUMB_DIR . '/' . date('Ym');
    $abs = __DIR__ . '/../' . $dir;
    if (!is_dir($abs)) @mkdir($abs, 0755, true);
    return (is_dir($abs) && is_writable($abs)) ? $dir : null;
}
function thumbs_clean_list(string $thumb, array $frames): array {
    $thumb = trim($thumb);
    if ($thumb !== '' && !is_http_url($thumb) && !str_starts_with($thumb, '//')) $thumb = '';
    $max = max(0, min(6, (int)setting('thumb_frames', 6)));
    $frames = array_slice(array_values(array_unique(array_filter($frames, fn($f) => is_string($f) && (is_http_url($f) || str_starts_with($f, '//')) && $f !== $thumb))), 0, $max);
    return [$thumb, $frames];
}

/**
 * Baixa e salva as thumbs de VÁRIOS vídeos de uma vez (todas as imagens em paralelo).
 * $jobs = [chave => ['provider','source_id','thumb','frames']]
 * Retorna [chave => ['thumb' => ..., 'thumbs' => ..., 'ok' => bool]]
 */
function thumbs_store_many(array $jobs): array {
    $out = [];
    $dir = thumbs_dir();
    $urls = []; $refs = [];
    foreach ($jobs as $k => $j) {
        [$thumb, $frames] = thumbs_clean_list((string)($j['thumb'] ?? ''), (array)($j['frames'] ?? []));
        $jobs[$k]['thumb'] = $thumb; $jobs[$k]['frames'] = $frames;
        $out[$k] = ['thumb' => $thumb, 'thumbs' => '', 'ok' => false];
        if (!$dir || !setting_on('thumbs_local')) continue;
        if ($thumb !== '') $urls["$k|0"] = $thumb;
        foreach ($frames as $i => $f) $urls["$k|" . ($i + 1)] = $f;
    }
    if (!$urls) return $out;
    // agrupa por site para mandar o Referer certo
    $byRef = [];
    foreach ($urls as $key => $u) { $k = explode('|', $key)[0]; $byRef[provider_referer((string)$jobs[$k]['provider'])][$key] = $u; }
    $raw = [];
    foreach ($byRef as $ref => $list) $raw += fetch_images($list, $ref);
    foreach ($jobs as $k => $j) {
        $imgs = [];
        foreach ($raw as $key => $bytes) {
            [$kk, $i] = explode('|', $key);
            if ((string)$kk !== (string)$k) continue;
            if ($im = img_open($bytes)) $imgs[(int)$i] = $im;
            unset($raw[$key]);           // libera a memória já
        }
        if (!$imgs) continue;
        ksort($imgs);
        $base = substr(preg_replace('/[^a-z0-9-]/', '', strtolower($j['provider'] . '-' . $j['source_id'])) ?: 'v', 0, 40) . '-' . bin2hex(random_bytes(4));
        $r = thumbs_save_images($imgs, $dir, $base, $j['thumb']);
        $out[$k] = $r + ['ok' => thumb_is_local($r['thumb'])];
    }
    return $out;
}

/** Baixa a thumb principal + quadros de um vídeo (mantém a URL remota se falhar) */
function thumbs_store(string $provider, string $sourceId, string $thumb, array $frames = []): array {
    $r = thumbs_store_many([0 => ['provider' => $provider, 'source_id' => $sourceId, 'thumb' => $thumb, 'frames' => $frames]])[0];
    return ['thumb' => $r['thumb'], 'thumbs' => $r['thumbs']];
}

/* ---------------- Thumbs em segundo plano ---------------- */
/** Marca para baixar depois: guarda os links dos quadros na coluna thumbs */
function thumbs_pending_value(array $frames): string { return 'remote:' . json_encode(array_values(array_filter($frames, 'is_string')), JSON_UNESCAPED_SLASHES); }
function thumbs_is_pending(array $v): bool { return str_starts_with((string)($v['thumbs'] ?? ''), 'remote:'); }
function thumbs_remote_frames(array $v): array {
    $t = (string)($v['thumbs'] ?? '');
    return str_starts_with($t, 'remote:') ? (array)(json_decode(substr($t, 7), true) ?: []) : [];
}
function thumbs_pending_count(): int { return db()->count('videos', ['search' => [['thumbs'], 'remote:']]); }

/**
 * Processa a fila: baixa as thumbs de até $limit vídeos pendentes em paralelo.
 * Depois de 3 falhas o vídeo sai da fila (a thumb passa a ser carregada direto do site de origem).
 */
/** No máximo 2 processos baixando thumbs ao mesmo tempo (protege a hospedagem de sobrecarga) */
function thumbs_slot() {
    for ($i = 1; $i <= 2; $i++) {
        $fp = @fopen(DATA_DIR . "/thumbdl-$i.lock", 'c');
        if ($fp && flock($fp, LOCK_EX | LOCK_NB)) return $fp;
        if ($fp) fclose($fp);
    }
    return null;
}

function thumbs_process_batch(int $limit = 8, ?int $onlyId = null): array {
    if (thumbs_problem() !== '') return ['saved' => 0, 'failed' => 0, 'pending' => thumbs_pending_count(), 'writable' => thumbs_dir() !== null];
    $slot = thumbs_slot();
    if (!$slot) return ['saved' => 0, 'failed' => 0, 'pending' => thumbs_pending_count(), 'writable' => true, 'busy' => true];
    @set_time_limit(60);
    $list = $onlyId ? array_filter([db()->find('videos', $onlyId)]) : db()->query('videos', ['search' => [['thumbs'], 'remote:'], 'order' => 'id DESC', 'limit' => $limit]);
    $list = array_values(array_filter($list, 'thumbs_is_pending'));
    $ok = 0; $fail = 0; $start = microtime(true);
    // de 2 em 2 vídeos, por no máximo ~15 segundos (menos pedidos ao servidor, pouca memória)
    foreach (array_chunk($list, 2) as $group) {
        if (microtime(true) - $start > 15) break;
        $jobs = [];
        foreach ($group as $v) $jobs[$v['id']] = ['provider' => $v['provider'], 'source_id' => $v['source_id'], 'thumb' => $v['thumb'], 'frames' => thumbs_remote_frames($v)];
        foreach (thumbs_store_many($jobs) as $id => $r) {
            if ($r['ok']) { db()->update('videos', $id, ['thumb' => $r['thumb'], 'thumbs' => $r['thumbs']]); $ok++; continue; }
            $fail++;
            if (!rl_hit('thumbfail:' . $id, 2, 86400)) db()->update('videos', $id, ['thumbs' => 'failed:' . time()]);  // 3ª falha: desiste
        }
    }
    if ($ok) @unlink(__DIR__ . '/../data/cache_tags.json');
    flock($slot, LOCK_UN); fclose($slot);
    return ['saved' => $ok, 'failed' => $fail, 'pending' => thumbs_pending_count(), 'writable' => thumbs_dir() !== null];
}

/** Coloca de volta na fila os vídeos cuja thumb ainda é um link de outro site */
function thumbs_requeue_remote(): int {
    $n = 0; $offset = 0;
    while ($chunk = db()->query('videos', ['search' => [['thumb'], 'http'], 'order' => 'id ASC', 'limit' => 500, 'offset' => $offset])) {
        foreach ($chunk as $v) if (!thumbs_is_pending($v) && !thumb_is_local($v['thumb']) && is_http_url($v['thumb'])) {
            db()->update('videos', $v['id'], ['thumbs' => thumbs_pending_value([])]);
            rl_reset('thumbfail:' . $v['id']);
            $n++;
        }
        $offset += 500;
    }
    return $n;
}

/**
 * URL da thumb para mostrar no site.
 * Salva no servidor -> arquivo local. Ainda não salva -> link do site de origem (o navegador do visitante
 * busca lá; NÃO gera pedido ao seu servidor, para não sobrecarregar a hospedagem).
 */
function video_thumb_url(array $v): string {
    $t = (string)($v['thumb'] ?? '');
    if (thumb_is_local($t)) return thumb_url($t);
    if (is_http_url($t)) return $t;
    return thumb_url('');
}
function video_thumb_abs(array $v): string {
    $u = video_thumb_url($v);
    return preg_match('~^https?://~', $u) ? $u : absolute_url(ltrim(preg_replace('~^' . preg_quote(rtrim(base_url(), '/'), '~') . '~', '', $u), '/'));
}

/** Grava a principal (índice 0) e o sprite (índices 1+) a partir de imagens GD já abertas */
function thumbs_save_images(array $imgs, string $dir, string $base, string $fallback): array {
    $result = ['thumb' => $fallback, 'thumbs' => ''];
    // Principal (se ela falhar, usa o 1º quadro)
    $mainIdx = isset($imgs[0]) ? 0 : (array_key_first($imgs) ?? null);
    if ($mainIdx !== null) {
        $w = thumb_width();
        $im = img_cover($imgs[$mainIdx], $w, (int)round($w * 9 / 16));
        [$bytes, $ext] = img_encode($im, thumb_quality());
        imagedestroy($im);
        $file = "$dir/$base.$ext";
        if ($bytes && file_put_contents(__DIR__ . '/../' . $file, $bytes) !== false) $result['thumb'] = $file;
    }
    // Sprite com os quadros
    $frameImgs = array_values(array_filter($imgs, fn($k) => $k > 0, ARRAY_FILTER_USE_KEY));
    if (count($frameImgs) >= 2) {
        $fw = thumb_width() >= 640 ? 320 : 240; $fh = (int)round($fw * 9 / 16);
        $n = count($frameImgs);
        $sprite = imagecreatetruecolor($fw * $n, $fh);
        foreach ($frameImgs as $k => $src) { $c = img_cover($src, $fw, $fh); imagecopy($sprite, $c, $k * $fw, 0, 0, 0, $fw, $fh); imagedestroy($c); }
        [$bytes, $ext] = img_encode($sprite, 62);
        imagedestroy($sprite);
        $file = "$dir/$base-s.$ext";
        if ($bytes && file_put_contents(__DIR__ . '/../' . $file, $bytes) !== false) $result['thumbs'] = "sprite:$file:$n";
    }
    foreach ($imgs as $im) imagedestroy($im);
    return $result;
}

/** Converte as thumbs antigas (JPG 640px + vários arquivos) de um vídeo para o formato leve, sem baixar de novo */
function thumbs_optimize_local(array $v): ?array {
    $pv = thumb_preview($v['thumbs'] ?? '');
    $isLegacy = (thumb_is_local($v['thumb'] ?? '') && !str_ends_with((string)$v['thumb'], '.webp')) || !empty($pv['paths']);
    if (!$isLegacy || !function_exists('imagecreatefromstring')) return null;
    $files = array_merge([$v['thumb']], $pv['paths'] ?? []);
    $imgs = [];
    foreach ($files as $i => $f) {
        if (!thumb_is_local($f) || !is_file(__DIR__ . '/../' . $f)) continue;
        if ($im = img_open((string)file_get_contents(__DIR__ . '/../' . $f))) $imgs[$i] = $im;
    }
    if (!$imgs) return null;
    $dir = THUMB_DIR . '/' . date('Ym');
    if (!is_dir(__DIR__ . '/../' . $dir)) @mkdir(__DIR__ . '/../' . $dir, 0755, true);
    $base = substr(preg_replace('/[^a-z0-9-]/', '', strtolower($v['provider'] . '-' . $v['source_id'])) ?: 'v', 0, 40) . '-' . bin2hex(random_bytes(4));
    $new = thumbs_save_images($imgs, $dir, $base, (string)$v['thumb']);
    if ($new['thumb'] === $v['thumb']) return null;
    if ($new['thumbs'] === '' && empty($pv['paths'])) $new['thumbs'] = (string)($v['thumbs'] ?? '');
    thumbs_delete($v);   // apaga os arquivos antigos
    return $new;
}

/** Lê a coluna thumbs: ['sprite' => url, 'n' => 6] ou ['frames' => [urls]] ou [] */
function thumb_preview(?string $thumbs): array {
    $t = trim((string)$thumbs);
    if ($t === '') return [];
    if (str_starts_with($t, 'remote:') || str_starts_with($t, 'failed:')) return [];
    if (preg_match('/^sprite:(.+):(\d+)$/', $t, $m)) return ['sprite' => thumb_url($m[1]), 'path' => $m[1], 'n' => (int)$m[2]];
    return ['frames' => array_map('thumb_url', split_list($t)), 'paths' => split_list($t)];
}

/** Todos os arquivos locais de um vídeo */
function thumbs_files(array $v): array {
    $p = thumb_preview($v['thumbs'] ?? '');
    $all = array_merge([$v['thumb'] ?? ''], isset($p['path']) ? [$p['path']] : ($p['paths'] ?? []));
    return array_values(array_filter($all, fn($t) => thumb_is_local($t) && str_starts_with($t, THUMB_DIR . '/') && !str_contains($t, '..')));
}
function thumbs_delete(array $v): void {
    foreach (thumbs_files($v) as $t) @unlink(__DIR__ . '/../' . $t);
}

/* ---------------- Espaço em disco ---------------- */
function thumbs_scan(callable $fn): void {
    $root = __DIR__ . '/../' . THUMB_DIR;
    if (!is_dir($root)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getFilename() === '.gitkeep' || $f->getFilename() === 'index.php') continue;
        $fn($f, THUMB_DIR . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1)));
    }
}
function thumbs_storage(): array {
    $bytes = 0; $files = 0;
    thumbs_scan(function ($f) use (&$bytes, &$files) { $bytes += $f->getSize(); $files++; });
    return ['bytes' => $bytes, 'files' => $files];
}
/** Apaga arquivos que nenhum vídeo usa mais. Retorna [arquivos, bytes] liberados. */
function thumbs_clean_orphans(): array {
    $used = [];
    $offset = 0;
    while ($chunk = db()->query('videos', ['limit' => 1000, 'offset' => $offset])) {
        foreach ($chunk as $v) foreach (thumbs_files($v) as $t) $used[$t] = true;
        $offset += 1000;
    }
    $n = 0; $b = 0; $limit = time() - 3600; // não mexe no que foi baixado na última hora (importação em andamento)
    thumbs_scan(function ($f, $rel) use ($used, &$n, &$b, $limit) {
        if (isset($used[$rel]) || $f->getMTime() > $limit) return;
        $size = $f->getSize();
        if (@unlink($f->getPathname())) { $n++; $b += $size; }
    });
    return [$n, $b];
}
function fmt_bytes(int $b): string {
    if ($b >= 1073741824) return number_format($b / 1073741824, 2, ',', '.') . ' GB';
    if ($b >= 1048576) return number_format($b / 1048576, 1, ',', '.') . ' MB';
    return number_format($b / 1024, 0, ',', '.') . ' KB';
}

/** Explica por que o servidor não consegue salvar thumbs (ou '' se está tudo certo) */
function thumbs_problem(): string {
    if (!setting_on('thumbs_local')) return 'A opção “Salvar as thumbs no meu servidor” está desligada (Configurações › Thumbs).';
    if (!function_exists('imagecreatefromstring')) return 'A extensão GD do PHP não está ativa. Ative “gd” nas extensões do PHP da hospedagem.';
    if (!function_exists('curl_multi_init')) return 'A extensão cURL do PHP não está ativa.';
    if (thumbs_dir() === null) return 'A pasta uploads/thumbs não tem permissão de escrita. No Gerenciador de Arquivos, dê permissão 755 (ou 775) às pastas “uploads” e “uploads/thumbs”.';
    return '';
}

/** true se a opção estiver ligada (padrão: ligada) */
function setting_on(string $key): bool { return setting($key, '1') !== '0'; }
