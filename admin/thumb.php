<?php
/** Mostra no painel a thumb de um vídeo de outro site (antes de importar), passando pelo servidor. */
require __DIR__ . '/../includes/bootstrap.php';
if (!is_admin()) { http_response_code(403); exit; }
session_write_close();
$url = (string)($_GET['u'] ?? '');
$img = $url !== '' ? thumb_fetch($url, provider_referer((string)($_GET['p'] ?? ''))) : null;
if (!$img) {
    header('Content-Type: image/svg+xml'); header('Cache-Control: no-store');
    readfile(__DIR__ . '/../assets/img/no-thumb.svg');
    exit;
}
header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
echo $img;
