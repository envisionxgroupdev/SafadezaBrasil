<?php
require_once __DIR__ . '/../config.php';
date_default_timezone_set(TIMEZONE);
error_reporting(DEBUG ? E_ALL : 0);
ini_set('display_errors', DEBUG ? '1' : '0');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
    session_start();
}

define('DATA_DIR', realpath(__DIR__ . '/../data') ?: __DIR__ . '/../data');
function is_installed(): bool { return is_file(DATA_DIR . '/installed.lock'); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/importer.php';

// Primeiro acesso: manda para o instalador
if (REQUIRE_INSTALL && !is_installed() && !defined('IN_INSTALLER')) {
    header('Location: ' . base_url('install.php'));
    exit;
}

if (!defined('IN_INSTALLER')) seed_if_empty();
