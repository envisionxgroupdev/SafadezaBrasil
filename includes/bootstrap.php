<?php
require_once __DIR__ . '/../config.php';
date_default_timezone_set(TIMEZONE);
error_reporting(DEBUG ? E_ALL : 0);
ini_set('display_errors', DEBUG ? '1' : '0');
ini_set('expose_php', '0');

define('DATA_DIR', realpath(__DIR__ . '/../data') ?: __DIR__ . '/../data');
function is_installed(): bool { return is_file(DATA_DIR . '/installed.lock'); }
function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || strtolower($_SERVER['HTTP_CF_VISITOR'] ?? '') === '{"scheme":"https"}';
}

// ---------- Sessão segura ----------
if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    session_name('sbsid');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => is_https()]);
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/thumbs.php';
require_once __DIR__ . '/importer.php';
require_once __DIR__ . '/security.php';

// Primeiro acesso: manda para o instalador
if (REQUIRE_INSTALL && !is_installed() && !defined('IN_INSTALLER')) {
    header('Location: ' . base_url('install.php'));
    exit;
}

if (!defined('IN_INSTALLER')) {
    seed_if_empty();
    security_boot();
}
