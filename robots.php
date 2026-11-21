<?php
// robots.txt dinâmico (o .htaccess envia /robots.txt para cá quando o arquivo físico não existe)
require __DIR__ . '/includes/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
echo robots_content();
