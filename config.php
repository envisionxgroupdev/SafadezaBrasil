<?php
/**
 * ============================================================
 *  CONFIGURAÇÃO PRINCIPAL DO SITE
 * ============================================================
 *  O banco de dados é configurado pelo INSTALADOR
 *  (seudominio.com/install.php), que grava os dados em
 *  data/config.local.php. Não precisa editar nada aqui.
 *
 *  O NOME DO SITE fica em SITE_NAME (ou em Admin > Configurações).
 * ============================================================
 */

// Dados gerados pelo instalador (banco, etc.)
if (is_file(__DIR__ . '/data/config.local.php')) require __DIR__ . '/data/config.local.php';

$__def = function (string $k, $v) { if (!defined($k)) define($k, $v); };

$__def('SITE_NAME',    'Safadeza Brasil');
$__def('SITE_TAGLINE', 'Vídeos adultos grátis em HD');
$__def('SITE_URL',     '');                 // ex.: https://seudominio.com (vazio = automático)

// ---------- Banco de dados (preenchido pelo instalador) ----------
// 'mysql' = MySQL | 'json' = arquivos na pasta /data (só para testes)
$__def('DB_DRIVER',  'json');
$__def('DB_HOST',    'localhost');
$__def('DB_PORT',    3306);
$__def('DB_NAME',    '');
$__def('DB_USER',    '');
$__def('DB_PASS',    '');
$__def('DB_CHARSET', 'utf8mb4');

// Exigir o instalador antes de abrir o site (recomendado: true)
$__def('REQUIRE_INSTALL', true);

// Usado só no modo de teste 'json' (sem instalador)
$__def('ADMIN_DEFAULT_USER', 'admin');
$__def('ADMIN_DEFAULT_PASS', 'admin123');

// ---------- Geral ----------
$__def('VIDEOS_PER_PAGE', 24);
$__def('PRETTY_URLS', false);   // true = links /video/12/titulo (Apache + mod_rewrite)
$__def('TIMEZONE', 'America/Sao_Paulo');
$__def('DEBUG', false);

unset($__def);
