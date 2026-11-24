<?php
require __DIR__ . '/includes/bootstrap.php';
$canonical = base_url();

$page   = current_page();
$total  = count_videos();
$sorts  = sort_options();
$latest = list_videos(['order' => 'created_at DESC', 'limit' => VIDEOS_PER_PAGE, 'offset' => ($page - 1) * VIDEOS_PER_PAGE]);
$cats   = all_categories();

// "Em alta": os mais vistos entre os vídeos mais novos (como fazem os sites grandes)
$trending = [];
$featured = [];
if ($page === 1) {
    $featured = list_videos(['where' => ['featured' => 1], 'order' => 'created_at DESC', 'limit' => 4]);
    $recentPool = list_videos(['order' => 'created_at DESC', 'limit' => 200]);
    usort($recentPool, fn($a, $b) => ((int)$b['views'] + 3 * (int)$b['likes']) <=> ((int)$a['views'] + 3 * (int)$a['likes']));
    $shown = array_flip(array_column(array_merge($featured, $latest), 'id'));
    foreach ($recentPool as $v) { if (!isset($shown[$v['id']])) $trending[] = $v; if (count($trending) >= 8) break; }
}

require __DIR__ . '/includes/header.php';
?>

<?php if (!$total): ?>
  <div class="empty">
    <h2>Nenhum vídeo ainda</h2>
    <p>Entre no <a href="<?= e(base_url('admin/index.php')) ?>" style="color:var(--primary);font-weight:700">painel admin</a> e use o Importador para adicionar vídeos.</p>
  </div>
<?php else: ?>

  <?php if ($cats): ?>
  <div class="cat-strip">
    <?php foreach ($cats as $c): ?><a class="chip" href="<?= e(category_url($c)) ?>"><?= e($c['name']) ?></a><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($featured): ?>
  <section class="section">
    <div class="section-head"><h2>Em <span class="accent">destaque</span></h2></div>
    <div class="grid"><?php foreach ($featured as $v) echo video_card($v); ?></div>
  </section>
  <?php endif; ?>

  <section class="section">
    <div class="section-head">
      <h2><?= $page > 1 ? 'Vídeos novos — página ' . $page : 'Vídeos <span class="accent">novos</span>' ?></h2>
      <div class="sortbar">
        <a href="<?= e(base_url()) ?>" class="active">Novos</a>
        <?php foreach (['vistos', 'curtidos', 'longos'] as $k): ?><a href="<?= e(base_url('videos.php?sort=' . $k)) ?>"><?= e($sorts[$k][0]) ?></a><?php endforeach; ?>
      </div>
    </div>
    <div class="grid"><?php foreach ($latest as $v) echo video_card($v); ?></div>
    <?= paginate($total, $page, VIDEOS_PER_PAGE, fn($p) => base_url('?page=' . $p)) ?>
  </section>

  <?php if ($trending): ?>
  <section class="section">
    <div class="section-head"><h2>Em <span class="accent">alta</span></h2><a class="see-all" href="<?= e(base_url('videos.php?sort=vistos')) ?>">Ver mais vistos →</a></div>
    <div class="grid"><?php foreach ($trending as $v) echo video_card($v); ?></div>
  </section>
  <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
