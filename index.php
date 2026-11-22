<?php
require __DIR__ . '/includes/bootstrap.php';
$canonical = base_url();

$featured = list_videos(['where' => ['featured' => 1], 'order' => 'created_at DESC', 'limit' => 8]);
$popular  = list_videos(['order' => 'views DESC', 'limit' => 12]);
$page     = current_page();
$total    = count_videos();
$latest   = list_videos(['order' => 'created_at DESC', 'limit' => VIDEOS_PER_PAGE, 'offset' => ($page - 1) * VIDEOS_PER_PAGE]);
$cats     = array_slice(all_categories(), 0, 12);

require __DIR__ . '/includes/header.php';
?>

<?php if (!$total): ?>
  <div class="empty">
    <h2>Nenhum vídeo ainda</h2>
    <p>Entre no <a href="<?= e(base_url('admin/index.php')) ?>" style="color:var(--primary);font-weight:700">painel admin</a> e use o Importador para adicionar vídeos.</p>
  </div>
<?php else: ?>

  <?php if ($featured && $page === 1): ?>
  <section class="section">
    <div class="section-head"><h2>Em destaque</h2></div>
    <div class="grid"><?php foreach ($featured as $v) echo video_card($v); ?></div>
  </section>
  <?php endif; ?>

  <?php if ($page === 1): ?>
  <section class="section">
    <div class="section-head"><h2>Mais vistos</h2><a class="see-all" href="<?= e(base_url('videos.php?sort=vistos')) ?>">Ver todos →</a></div>
    <div class="grid"><?php foreach ($popular as $v) echo video_card($v); ?></div>
  </section>

  <section class="section">
    <div class="section-head"><h2>Categorias</h2><a class="see-all" href="<?= e(base_url('categories.php')) ?>">Ver todas →</a></div>
    <div class="chips">
      <?php foreach ($cats as $c): ?><a class="chip" href="<?= e(category_url($c)) ?>"><?= e($c['name']) ?></a><?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="section">
    <div class="section-head"><h2>Vídeos recentes</h2></div>
    <div class="grid"><?php foreach ($latest as $v) echo video_card($v); ?></div>
    <?= paginate($total, $page, VIDEOS_PER_PAGE, fn($p) => base_url('?page=' . $p)) ?>
  </section>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
