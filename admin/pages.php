<?php
require __DIR__ . '/_layout.php';
require __DIR__ . '/../includes/pages.php';
require_admin();
$pages = static_pages();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $k = $_POST['key'] ?? '';
    if (isset($pages[$k])) {
        set_setting('page_' . $k, isset($_POST['reset']) ? '' : trim($_POST['content'] ?? ''));
        flash(isset($_POST['reset']) ? 'Texto padrão restaurado.' : 'Página salva.');
    }
    redirect('pages.php?p=' . urlencode($k));
}
$cur = isset($pages[$_GET['p'] ?? '']) ? $_GET['p'] : 'termos';
$content = setting('page_' . $cur, '') ?: trim($pages[$cur]['default']);
admin_header('Páginas', 'pages');
?>
<div class="toolbar">
  <?php foreach ($pages as $k => $p): ?><a class="btn <?= $k === $cur ? 'btn-primary' : '' ?>" href="?p=<?= $k ?>"><?= e($p['title']) ?></a><?php endforeach; ?>
</div>
<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="key" value="<?= e($cur) ?>">
  <h2><?= e($pages[$cur]['title']) ?></h2>
  <div class="field"><textarea name="content" rows="18" style="font-family:ui-monospace,Consolas,monospace;font-size:13px"><?= e($content) ?></textarea>
    <small>Aceita HTML. <code>{SITE}</code> vira o nome do site e <code>{EMAIL}</code> o e-mail de contato.</small></div>
  <button class="btn btn-primary">💾 Salvar</button>
  <button class="btn" name="reset" value="1" data-confirm="Voltar ao texto padrão?">Restaurar padrão</button>
  <a class="btn" href="<?= e(base_url('page.php?p=' . $cur)) ?>" target="_blank">↗ Ver</a>
</form>
<?php admin_footer(); ?>
