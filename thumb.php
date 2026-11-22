<?php
/**
 * Compatibilidade: endereços antigos de thumb (thumb.php?v=ID).
 * Só redireciona — não baixa nada aqui, para nunca gerar carga na hospedagem.
 * Quem baixa e salva as thumbs é a fila do painel (Admin › Vídeos › Baixar thumbs que faltam).
 */
require __DIR__ . '/includes/bootstrap.php';
session_write_close();
$v = db()->find('videos', (int)($_GET['v'] ?? 0));
$url = $v ? video_thumb_url($v) : thumb_url('');
header('Cache-Control: public, max-age=3600');
header('Referrer-Policy: no-referrer');
header('Location: ' . $url, true, 302);
