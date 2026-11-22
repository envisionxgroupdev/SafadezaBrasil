<?php
require __DIR__ . '/includes/bootstrap.php';
$cats = all_categories();
$pageTitle = 'Categorias';
require __DIR__ . '/includes/header.php';
?>
<div class="section-head"><h1>Todas as categorias</h1></div>
<div class="cat-grid">
<?php foreach ($cats as $c):
    $n = count_videos(['in_set' => ['categories', $c['id']]]);
    $thumb = $c['thumb'];
    $thumbUrl = $thumb ? thumb_url($thumb) : '';
    if (!$thumb && $n) { $last = list_videos(['in_set' => ['categories', $c['id']], 'order' => 'views DESC', 'limit' => 1]); if ($last) { $thumb = 'x'; $thumbUrl = video_thumb_url($last[0]); } }
?>
  <a class="cat-card" href="<?= e(category_url($c)) ?>">
    <?php if (!$thumb): ?><span class="cat-empty"><?= e(mb_substr($c['name'], 0, 1)) ?></span><?php endif; ?>
    <?php if ($thumb): ?><img src="<?= e($thumbUrl) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php endif; ?>
    <span class="cat-name"><?= e($c['name']) ?><small><?= number_format($n, 0, ',', '.') ?> vídeos</small></span>
  </a>
<?php endforeach; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
