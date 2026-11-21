<?php
/** @var string $pageTitle  @var string $pageDesc */
$pageTitle = isset($pageTitle) ? $pageTitle . ' - ' . site_name() : site_name() . ' - ' . site_tagline();
$pageDesc  = $pageDesc ?? site_tagline();
$q = trim($_GET['q'] ?? '');
$primary = setting('color_primary', '') ?: '#6a2fa0';
$navCats = array_slice(all_categories(), 0, 10);
$cur = basename($_SERVER['SCRIPT_NAME']);
$sortNow = $_GET['sort'] ?? '';
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e($pageDesc) ?>">
<meta name="rating" content="adult">
<meta name="RATING" content="RTA-5042-1996-1400-1577-RTA">
<meta name="theme-color" content="<?= e($primary) ?>">
<?php if (!empty($canonical)): ?><link rel="canonical" href="<?= e($canonical) ?>"><?php endif; ?>
<?php if (!empty($ogImage)): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<meta property="og:title" content="<?= e($pageTitle) ?>">
<meta property="og:site_name" content="<?= e(site_name()) ?>">
<link rel="icon" href="<?= e(base_url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(base_url('assets/css/style.css')) ?>?v=1">
<style>:root{--primary:<?= e($primary) ?>}</style>
<?= setting('head_code', '') ?>
</head>
<body>

<?php if (empty($_COOKIE['age_ok'])): ?>
<div class="agegate" id="agegate">
  <div class="agegate-box">
    <div class="logo big"><?= e(site_name()) ?></div>
    <h2>Conteúdo adulto</h2>
    <p>Este site contém material sexualmente explícito, destinado apenas a maiores de 18 anos
       (ou da maioridade legal no seu país). Ao entrar, você confirma que tem idade legal e concorda com os
       <a href="<?= e(base_url('page.php?p=termos')) ?>">Termos de Uso</a>.</p>
    <div class="agegate-actions">
      <button class="btn btn-primary" id="age-yes">Tenho 18 anos ou mais — Entrar</button>
      <a class="btn btn-ghost" href="https://www.google.com">Sair</a>
    </div>
    <small>Pais: protejam seus filhos com ferramentas de controle parental. Este site está rotulado com RTA.</small>
  </div>
</div>
<?php endif; ?>

<header class="topbar">
  <div class="wrap topbar-inner">
    <button class="menu-btn" id="menu-btn" aria-label="Menu">☰</button>
    <a href="<?= e(base_url()) ?>" class="logo"><?= e(site_name()) ?></a>
    <form class="search" action="<?= e(base_url('search.php')) ?>" method="get">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Pesquisar vídeos..." aria-label="Pesquisar">
      <button type="submit" aria-label="Buscar">🔍</button>
    </form>
  </div>
  <nav class="mainnav" id="mainnav">
    <div class="wrap">
      <a href="<?= e(base_url()) ?>" class="<?= $cur === 'index.php' && !$sortNow ? 'active' : '' ?>">Início</a>
      <a href="<?= e(base_url('videos.php?sort=recentes')) ?>" class="<?= $sortNow === 'recentes' ? 'active' : '' ?>">Novos</a>
      <a href="<?= e(base_url('videos.php?sort=vistos')) ?>" class="<?= $sortNow === 'vistos' ? 'active' : '' ?>">Mais vistos</a>
      <a href="<?= e(base_url('videos.php?sort=curtidos')) ?>" class="<?= $sortNow === 'curtidos' ? 'active' : '' ?>">Mais curtidos</a>
      <a href="<?= e(base_url('categories.php')) ?>" class="<?= $cur === 'categories.php' ? 'active' : '' ?>">Categorias</a>
      <a href="<?= e(base_url('tags.php')) ?>" class="<?= $cur === 'tags.php' ? 'active' : '' ?>">Tags</a>
      <span class="sep"></span>
      <?php foreach ($navCats as $c): ?>
        <a href="<?= e(category_url($c)) ?>" class="cat-link"><?= e($c['name']) ?></a>
      <?php endforeach; ?>
    </div>
  </nav>
</header>

<?php if ($ad = setting('ad_header', '')): ?><div class="ad ad-header wrap"><?= $ad ?></div><?php endif; ?>

<main class="wrap main">
