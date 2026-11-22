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

/** Proteção SSRF: só http/https e só endereços públicos da internet */
function url_is_public(string $url): bool {
    $p = parse_url($url);
    if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) return false;
    $host = trim($p['host'], '[]');
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) return false;
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    }
    return true;
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
    if ($info[0] < 80 || $info[1] < 45 || $info[0] * $info[1] > 40000000) return null; // pixel de rastreio / gigante
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
function curl_img_handle(string $url, string $referer) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
        CURLOPT_HTTPHEADER => array_values(array_filter(['Accept: image/avif,image/webp,image/*,*/*;q=0.8', $referer ? 'Referer: ' . $referer : null])),
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    return $ch;
}
/** Baixa várias imagens em paralelo. Retorna [indice => bytes] das que responderam. */
function fetch_images(array $urls, string $referer): array {
    if (!function_exists('curl_multi_init')) return [];
    $mh = curl_multi_init(); $hs = [];
    foreach ($urls as $i => $u) {
        if (str_starts_with($u, '//')) $u = 'https:' . $u;
        if (!url_is_public($u)) continue;
        $hs[$i] = curl_img_handle($u, $referer);
        curl_multi_add_handle($mh, $hs[$i]);
    }
    do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1.0); } while ($run && $st === CURLM_OK);
    $out = [];
    foreach ($hs as $i => $ch) {
        $body = curl_multi_getcontent($ch);
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && is_string($body) && $body !== '') $out[$i] = $body;
        curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}
/** Baixa UMA imagem (prévia do painel). Retorna [bytes, ext] já validado. */
function thumb_fetch(string $url, string $referer = ''): ?array {
    $r = fetch_images([$url], $referer);
    return isset($r[0]) ? thumb_process($r[0]) : null;
}

/* ---------------- Salvar ---------------- */
/**
 * Baixa a thumb principal + quadros e salva no formato leve.
 * Retorna ['thumb' => caminho, 'thumbs' => 'sprite:caminho:N'] (mantém a URL remota se falhar).
 */
function thumbs_store(string $provider, string $sourceId, string $thumb, array $frames = []): array {
    $thumb = trim($thumb);
    $maxFrames = max(0, min(6, (int)setting('thumb_frames', 6)));
    $frames = array_slice(array_values(array_unique(array_filter($frames, fn($f) => is_string($f) && $f !== '' && $f !== $thumb))), 0, $maxFrames);
    if (!setting_on('thumbs_local')) return ['thumb' => $thumb, 'thumbs' => ''];

    $urls = $thumb !== '' ? [0 => $thumb] : [];
    foreach ($frames as $k => $f) $urls[$k + 1] = $f;
    if (!$urls) return ['thumb' => '', 'thumbs' => ''];

    $dir = THUMB_DIR . '/' . date('Ym');
    $absDir = __DIR__ . '/../' . $dir;
    if (!is_dir($absDir) && !@mkdir($absDir, 0755, true)) return ['thumb' => $thumb, 'thumbs' => ''];
    $base = substr(preg_replace('/[^a-z0-9-]/', '', strtolower($provider . '-' . $sourceId)) ?: 'v', 0, 40) . '-' . bin2hex(random_bytes(4));

    $raw = fetch_images($urls, provider_referer($provider));
    $imgs = [];
    foreach ($urls as $i => $_) if (isset($raw[$i]) && ($im = img_open($raw[$i]))) $imgs[$i] = $im;
    unset($raw);
    return thumbs_save_images($imgs, $dir, $base, $thumb);
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

/** true se a opção estiver ligada (padrão: ligada) */
function setting_on(string $key): bool { return setting($key, '1') !== '0'; }
