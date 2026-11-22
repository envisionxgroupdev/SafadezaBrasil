<?php
/**
 * ============================================================
 *  THUMBS
 * ============================================================
 *  As thumbs são BAIXADAS para o próprio servidor na hora da
 *  importação (pasta /uploads/thumbs). Assim elas:
 *   - sempre aparecem (sem bloqueio de hotlink, sem link expirado,
 *     sem erro de "conteúdo misto" http/https);
 *   - carregam mais rápido (redimensionadas para 640px).
 *  Também guarda até 6 quadros extras para a prévia animada
 *  quando o mouse passa por cima do vídeo.
 * ============================================================
 */
const THUMB_DIR = 'uploads/thumbs';
const THUMB_MAX_BYTES = 5 * 1024 * 1024;
const THUMB_WIDTH = 640;

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

/** Baixa UMA imagem e devolve o JPG já validado (usado na prévia do painel) */
function thumb_fetch(string $url, string $referer = ''): ?string {
    if (str_starts_with($url, '//')) $url = 'https:' . $url;
    if (!url_is_public($url)) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
        CURLOPT_HTTPHEADER => array_values(array_filter(['Accept: image/*', $referer ? 'Referer: ' . $referer : null])),
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && is_string($body)) ? thumb_process($body) : null;
}

/** Valida que os bytes são mesmo uma imagem e regrava como JPG (remove qualquer conteúdo escondido). */
function thumb_process(string $bytes): ?string {
    if ($bytes === '' || strlen($bytes) > THUMB_MAX_BYTES) return null;
    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) return null;
    if ($info[0] < 80 || $info[1] < 45) return null; // pixel de rastreio / imagem vazia
    if (!function_exists('imagecreatefromstring')) return $info[2] === IMAGETYPE_JPEG ? $bytes : null;
    $src = @imagecreatefromstring($bytes);
    if (!$src) return null;
    $w = imagesx($src); $h = imagesy($src);
    $nw = min(THUMB_WIDTH, $w); $nh = (int)round($h * $nw / $w);
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 20, 20, 20));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    ob_start(); imagejpeg($dst, null, 82); $out = ob_get_clean();
    imagedestroy($src); imagedestroy($dst);
    return $out ?: null;
}

/**
 * Baixa várias imagens em paralelo. Retorna [indice => caminho_local] só das que deram certo.
 */
function thumbs_download(array $urls, string $referer, string $name): array {
    if (!function_exists('curl_multi_init')) return [];
    $dir = THUMB_DIR . '/' . date('Ym');
    $abs = __DIR__ . '/../' . $dir;
    if (!is_dir($abs) && !@mkdir($abs, 0755, true)) return [];
    $name = preg_replace('/[^a-z0-9-]/', '', strtolower($name)) ?: 'v';
    $name = substr($name, 0, 40) . '-' . bin2hex(random_bytes(4));

    $mh = curl_multi_init(); $handles = [];
    foreach ($urls as $i => $u) {
        if (str_starts_with($u, '//')) $u = 'https:' . $u;
        if (!url_is_public($u)) continue;
        $ch = curl_init($u);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
            CURLOPT_HTTPHEADER => array_values(array_filter(['Accept: image/avif,image/webp,image/*,*/*;q=0.8', $referer ? 'Referer: ' . $referer : null])),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }
    do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $st === CURLM_OK);

    $saved = [];
    foreach ($handles as $i => $ch) {
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch); curl_close($ch);
        if ($code !== 200 || !is_string($body)) continue;
        $img = thumb_process($body);
        if (!$img) continue;
        $file = $dir . '/' . $name . ($i === 0 ? '' : '-' . $i) . '.jpg';
        if (file_put_contents(__DIR__ . '/../' . $file, $img) !== false) $saved[$i] = $file;
    }
    curl_multi_close($mh);
    return $saved;
}

/**
 * Baixa a thumb principal + quadros da prévia de um vídeo.
 * Retorna ['thumb' => ..., 'thumbs' => 'a.jpg,b.jpg'] (mantém a URL remota se o download falhar).
 */
function thumbs_store(string $provider, string $sourceId, string $thumb, array $frames = []): array {
    $thumb = trim($thumb);
    $max = (int)setting('thumb_frames', 6);
    $frames = array_slice(array_values(array_unique(array_filter($frames, fn($f) => $f && $f !== $thumb))), 0, max(0, $max));
    if (!setting_on('thumbs_local')) return ['thumb' => $thumb, 'thumbs' => ''];
    $urls = $thumb !== '' ? [0 => $thumb] : [];
    foreach ($frames as $k => $f) $urls[$k + 1] = $f;
    if (!$urls) return ['thumb' => '', 'thumbs' => ''];
    $saved = thumbs_download($urls, provider_referer($provider), $provider . '-' . $sourceId);
    $main = $saved[0] ?? (array_values($saved)[0] ?? $thumb);   // se a principal falhar, usa o 1º quadro
    $rest = array_values(array_diff($saved, [$main]));
    return ['thumb' => $main, 'thumbs' => implode(',', $rest)];
}

function thumbs_delete(array $v): void {
    foreach (array_merge([$v['thumb'] ?? ''], split_list($v['thumbs'] ?? '')) as $t) {
        if (thumb_is_local($t) && str_starts_with($t, THUMB_DIR . '/') && !str_contains($t, '..')) @unlink(__DIR__ . '/../' . $t);
    }
}

/** true se a opção estiver ligada (padrão: ligada) */
function setting_on(string $key): bool { return setting($key, '1') !== '0'; }
