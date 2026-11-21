<?php
require __DIR__ . '/includes/bootstrap.php';
$q = trim($_GET['q'] ?? '');
$sorts = sort_options();
$sort = isset($sorts[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'vistos';
$page = current_page();
$opts = ['search' => [['title', 'tags', 'models', 'description'], $q]];
$total = $q !== '' ? count_videos($opts) : 0;
$videos = $q !== '' ? list_videos($opts + ['order' => $sorts[$sort][1], 'limit' => VIDEOS_PER_PAGE, 'offset' => ($page - 1) * VIDEOS_PER_PAGE]) : [];
$pageTitle = $q !== '' ? "Vídeos de “{$q}”" : 'Pesquisar';
require __DIR__ . '/includes/header.php';
$qs = fn($extra) => '?' . http_build_query(['q' => $q, 'sort' => $sort] + $extra);
?>
<div class="section-head">
  <h1><?= $q !== '' ? 'Resultados para “' . e($q) . '”' : 'Pesquisar' ?> <small class="muted" style="font-size:14px;font-weight:500">(<?= number_format($total, 0, ',', '.') ?>)</small></h1>
  <?php if ($q !== ''): ?><div class="sortbar">
    <?php foreach ($sorts as $k => $s): ?><a href="?<?= e(http_build_query(['q' => $q, 'sort' => $k])) ?>" class="<?= $k === $sort ? 'active' : '' ?>"><?= e($s[0]) ?></a><?php endforeach; ?>
  </div><?php endif; ?>
</div>
<?php if ($videos): ?>
  <div class="grid"><?php foreach ($videos as $v) echo video_card($v); ?></div>
  <?= paginate($total, $page, VIDEOS_PER_PAGE, fn($p) => $qs(['page' => $p])) ?>
<?php else: ?><div class="empty"><?= $q !== '' ? 'Nenhum vídeo encontrado. Tente outra palavra.' : 'Digite algo na busca acima.' ?></div><?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
