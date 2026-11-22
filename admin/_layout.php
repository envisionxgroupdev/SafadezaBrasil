<?php
require_once __DIR__ . '/../includes/bootstrap.php';
db_upgrade();
private_page_headers();

function admin_header(string $title, string $active = ''): void {
    $menu = [
        'index'      => ['📊', 'Painel', 'index.php'],
        'import'     => ['⬇️', 'Importador', 'import.php'],
        'videos'     => ['🎬', 'Vídeos', 'videos.php'],
        'categories' => ['🗂️', 'Categorias', 'categories.php'],
        'reports'    => ['⚑', 'Denúncias', 'reports.php'],
        'seo'        => ['🔍', 'SEO', 'seo.php'],
        'pages'      => ['📄', 'Páginas', 'pages.php'],
        'security'   => ['🛡️', 'Segurança', 'security.php'],
        'settings'   => ['⚙️', 'Configurações', 'settings.php'],
    ];
    $openReports = db()->count('reports', ['where' => ['status' => 'open']]);
    $primary = primary_color();
    ?><!DOCTYPE html>
<html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?> · Admin <?= e(site_name()) ?></title>
<link rel="icon" href="<?= e(base_url('assets/img/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= e(base_url('admin/admin.css')) ?>?v=3">
<style>:root{--primary:<?= e($primary) ?>}</style>
</head><body>
<aside class="sidebar" id="sidebar">
  <a class="side-brand" href="<?= e(base_url('admin/')) ?>"><?= logo_html() ?><small>PAINEL ADMIN</small></a>
  <nav>
    <?php foreach ($menu as $k => [$ic, $lbl, $href]): ?>
      <a href="<?= e(base_url('admin/' . $href)) ?>" class="<?= $k === $active ? 'active' : '' ?>">
        <span class="ic"><?= $ic ?></span><?= e($lbl) ?>
        <?php if ($k === 'reports' && $openReports): ?><span class="pill"><?= $openReports ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-foot">
    <a href="<?= e(base_url()) ?>" target="_blank">↗ Ver site</a>
    <a href="<?= e(base_url('admin/logout.php?t=' . csrf_token())) ?>">⏻ Sair</a>
  </div>
</aside>
<div class="content" onclick="if(document.body.classList.contains('menu-open')&&!event.target.closest('.burger')){document.body.classList.remove('menu-open');document.getElementById('sidebar').classList.remove('open')}">
  <header class="bar">
    <button class="burger" aria-label="Menu" onclick="document.getElementById('sidebar').classList.toggle('open');document.body.classList.toggle('menu-open')">☰</button>
    <h1><?= e($title) ?></h1>
    <span class="who">👤 <?= e($_SESSION['admin_user'] ?? '') ?></span>
  </header>
  <div class="inner">
  <?php if (DB_DRIVER === 'json'): ?>
    <div class="alert info">Modo de teste sem banco (arquivos JSON). Para usar o MySQL, rode o instalador: <a href="<?= e(base_url('install.php')) ?>">install.php</a>.</div>
  <?php endif; ?>
  <?php foreach (flash() as [$t, $m]): ?><div class="alert <?= e($t) ?>"><?= e($m) ?></div><?php endforeach;
}

function admin_footer(): void { ?>
  </div>
</div>
<script>window.ADMIN = { base: <?= json_encode(base_url()) ?>, csrf: <?= json_encode(csrf_token()) ?> };</script>
<script src="<?= e(base_url('admin/admin.js')) ?>?v=3"></script>
</body></html>
<?php }

function clear_public_cache(): void { @unlink(__DIR__ . '/../data/cache_tags.json'); }
