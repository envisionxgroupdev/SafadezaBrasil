<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/pages.php';
$key = $_GET['p'] ?? '';
$pages = static_pages();
if (!isset($pages[$key])) { http_response_code(404); redirect(base_url()); }
$content = setting('page_' . $key, '') ?: $pages[$key]['default'];
$content = str_replace(['{SITE}', '{EMAIL}'], [e(site_name()), e(setting('contact_email', '') ?: 'contato@seudominio.com')], $content);
$pageTitle = $pages[$key]['title'];
require __DIR__ . '/includes/header.php';
?>
<div class="page-content">
  <h1><?= e($pages[$key]['title']) ?></h1>
  <?= $content ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
