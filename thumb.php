<?php
/**
 * Thumb "que se conserta sozinha".
 * Usada quando a thumb de um vídeo ainda não foi salva no servidor:
 *  1. tenta baixar agora (e já deixa salva para as próximas visitas);
 *  2. se não conseguir, manda o navegador buscar direto no site de origem;
 *  3. sem nada, mostra a imagem padrão.
 */
require __DIR__ . '/includes/bootstrap.php';
session_write_close();

$v = db()->find('videos', (int)($_GET['v'] ?? 0));
$noThumb = function (int $cache = 300) {
    header('Content-Type: image/svg+xml');
    header('Cache-Control: public, max-age=' . $cache);
    readfile(__DIR__ . '/assets/img/no-thumb.svg');
    exit;
};
if (!$v || ($v['status'] !== 'published' && !is_admin())) $noThumb(3600);

$go = function (string $url, int $cache) {
    header('Cache-Control: public, max-age=' . $cache);
    header('Referrer-Policy: no-referrer');
    header('Location: ' . $url, true, 302);
    exit;
};

// Já está salva (outra visita baixou antes)
if (thumb_is_local($v['thumb']) && is_file(__DIR__ . '/' . $v['thumb'])) $go(thumb_url($v['thumb']), 86400);

// Vídeo antigo com thumb de outro site e que nunca foi tentado: entra na fila agora
if (!thumbs_is_pending($v) && is_http_url($v['thumb']) && !str_starts_with((string)$v['thumbs'], 'failed:') && !str_starts_with((string)$v['thumbs'], 'sprite:')) {
    db()->update('videos', $v['id'], ['thumbs' => thumbs_pending_value([])]);
    $v = db()->find('videos', $v['id']);
}

// Tenta baixar agora — com limite por visitante, para ninguém usar isso para sobrecarregar o servidor
if (thumbs_is_pending($v) && rl_hit('thumbdl:' . client_ip(), 10, 60) && rl_hit('thumbdl-video:' . $v['id'], 1, 300)) {
    thumbs_process_batch(1, (int)$v['id']);
    $v = db()->find('videos', $v['id']);
    if (thumb_is_local($v['thumb'])) $go(thumb_url($v['thumb']), 86400);
}

// Plano B: o navegador do visitante busca no site de origem (sem enviar de onde veio)
if (is_http_url($v['thumb'])) $go($v['thumb'], 600);
$noThumb();
