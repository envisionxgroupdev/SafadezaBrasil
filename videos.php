<?php
require __DIR__ . '/includes/bootstrap.php';
$sorts = sort_options();
$sort = isset($sorts[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'recentes';
$page = current_page();
$total = count_videos();
$videos = list_videos(['order' => $sorts[$sort][1], 'limit' => VIDEOS_PER_PAGE, 'offset' => ($page - 1) * VIDEOS_PER_PAGE]);
$pageTitle = $sorts[$sort][0];
require __DIR__ . '/includes/header.php';
?>
<div class="section-head">
  <h1><?= e($sorts[$sort][0]) ?> <small class="muted" style="font-size:14px;font-weight:500">(<?= number_format($total, 0, ',', '.') ?>)</small></h1>
  <div class="sortbar">
    <?php foreach ($sorts as $k => $s): ?><a href="?sort=<?= $k ?>" class="<?= $k === $sort ? 'active' : '' ?>"><?= e($s[0]) ?></a><?php endforeach; ?>
  </div>
</div>
<?php if ($videos): ?>
  <div class="grid"><?php foreach ($videos as $v) echo video_card($v); ?></div>
  <?= paginate($total, $page, VIDEOS_PER_PAGE, fn($p) => "?sort=$sort&page=$p") ?>
<?php else: ?><div class="empty">Nenhum vídeo encontrado.</div><?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
