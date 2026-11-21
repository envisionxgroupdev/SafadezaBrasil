<?php
require __DIR__ . '/includes/bootstrap.php';
$c = db()->findBy('categories', 'slug', $_GET['c'] ?? '');
if (!$c) { http_response_code(404); redirect(base_url('categories.php')); }
$sorts = sort_options();
$sort = isset($sorts[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'recentes';
$page = current_page();
$opts = ['in_set' => ['categories', $c['id']]];
$total = count_videos($opts);
$videos = list_videos($opts + ['order' => $sorts[$sort][1], 'limit' => VIDEOS_PER_PAGE, 'offset' => ($page - 1) * VIDEOS_PER_PAGE]);
$pageTitle = 'Vídeos de ' . $c['name'];
$pageDesc = $c['description'] ?: "Os melhores vídeos de {$c['name']} grátis em " . site_name();
$canonical = category_url($c);
require __DIR__ . '/includes/header.php';
$sep = PRETTY_URLS ? '?' : '&';
?>
<div class="section-head">
  <div>
    <h1><?= e($c['name']) ?> <small class="muted" style="font-size:14px;font-weight:500">(<?= number_format($total, 0, ',', '.') ?> vídeos)</small></h1>
    <?php if ($c['description']): ?><p class="muted" style="margin:4px 0 0"><?= e($c['description']) ?></p><?php endif; ?>
  </div>
  <div class="sortbar">
    <?php foreach ($sorts as $k => $s): ?><a href="<?= e(category_url($c) . $sep . 'sort=' . $k) ?>" class="<?= $k === $sort ? 'active' : '' ?>"><?= e($s[0]) ?></a><?php endforeach; ?>
  </div>
</div>
<?php if ($videos): ?>
  <div class="grid"><?php foreach ($videos as $v) echo video_card($v); ?></div>
  <?= paginate($total, $page, VIDEOS_PER_PAGE, fn($p) => category_url($c) . $sep . "sort=$sort&page=$p") ?>
<?php else: ?><div class="empty">Ainda não há vídeos nesta categoria.</div><?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
