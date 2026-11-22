<?php
require __DIR__ . '/includes/bootstrap.php';
$v = db()->find('videos', (int)($_GET['id'] ?? 0));
if (!$v || ($v['status'] !== 'published' && !is_admin())) {
    http_response_code(404);
    $pageTitle = 'Vídeo não encontrado';
    require __DIR__ . '/includes/header.php';
    echo '<div class="empty"><h2>Vídeo não encontrado</h2><p>Ele pode ter sido removido.</p></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

// Conta 1 visualização por sessão
if (empty($_SESSION['viewed'][$v['id']])) {
    db()->increment('videos', $v['id'], 'views');
    $_SESSION['viewed'][$v['id']] = 1;
    $v['views']++;
}

$catMap = category_map();
$vCats = array_filter(array_map(fn($id) => $catMap[$id] ?? null, split_list($v['categories'])));
$tags = split_list($v['tags']);
$models = split_list($v['models'] ?? '');

// Relacionados: mesma categoria, depois busca pelo 1º tag, depois aleatórios
$related = [];
$seen = [$v['id'] => true];
$add = function (array $list) use (&$related, &$seen) {
    foreach ($list as $r) if (!isset($seen[$r['id']]) && count($related) < 16) { $related[] = $r; $seen[$r['id']] = true; }
};
if ($vCats) $add(list_videos(['in_set' => ['categories', reset($vCats)['id']], 'order' => 'RAND()', 'limit' => 20]));
if ($tags) $add(list_videos(['search' => [['title', 'tags'], $tags[0]], 'order' => 'views DESC', 'limit' => 20]));
$add(list_videos(['order' => 'RAND()', 'limit' => 20]));

$pct = rating_pct($v);
$voted = $_SESSION['votes'][$v['id']] ?? '';
$pageTitle = $v['title'];
$pageDesc = $v['description'] ?: $v['title'] . ' - assista grátis em ' . site_name();
$canonical = video_url($v);
$ogImage = $v['thumb'];
$ogType = 'video.other';
require __DIR__ . '/includes/header.php';
?>
<div class="watch">
  <div>
    <div class="player">
      <iframe src="<?= e($v['embed_url']) ?>" allowfullscreen allow="autoplay; fullscreen" scrolling="no" referrerpolicy="origin" loading="eager"></iframe>
    </div>
    <?php if ($ad = setting('ad_player', '')): ?><div class="ad"><?= $ad ?></div><?php endif; ?>

    <div class="vbox">
      <?php if ($v['status'] !== 'published'): ?><p style="color:#c0392b;font-weight:700">⚠ Este vídeo não está público (status: <?= e($v['status']) ?>). Só admins veem.</p><?php endif; ?>
      <h1><?= e($v['title']) ?></h1>
      <div class="vstats">
        <div>
          <strong><?= number_format((int)$v['views'], 0, ',', '.') ?></strong> <span class="muted">visualizações</span>
          <span class="muted"> · <?= time_ago($v['created_at']) ?></span>
          <div class="ratingbar"><span id="rating-fill" style="width:<?= $pct ?>%"></span></div>
          <small class="muted"><span id="rating-pct"><?= $pct ?>%</span> gostaram</small>
        </div>
        <div class="actions">
          <button class="btn <?= $voted === 'up' ? 'voted' : '' ?>" data-vote="up" data-id="<?= $v['id'] ?>">👍 <span id="likes"><?= (int)$v['likes'] ?></span></button>
          <button class="btn <?= $voted === 'down' ? 'voted' : '' ?>" data-vote="down" data-id="<?= $v['id'] ?>">👎 <span id="dislikes"><?= (int)$v['dislikes'] ?></span></button>
          <button class="btn" id="share-btn">↗ Compartilhar</button>
          <button class="btn" id="report-btn">⚑ Denunciar</button>
          <?php if (is_admin()): ?><a class="btn" href="<?= e(base_url('admin/video-edit.php?id=' . $v['id'])) ?>">✎ Editar</a><?php endif; ?>
        </div>
      </div>

      <div class="vinfo">
        <?php if ($v['description']): ?><p style="margin:0"><?= nl2br(e($v['description'])) ?></p><?php endif; ?>
        <?php if ($vCats): ?>
        <div class="row"><span class="lbl">Categorias</span><div class="chips">
          <?php foreach ($vCats as $c): ?><a class="chip" href="<?= e(category_url($c)) ?>"><?= e($c['name']) ?></a><?php endforeach; ?>
        </div></div>
        <?php endif; ?>
        <?php if ($models): ?>
        <div class="row"><span class="lbl">Modelos</span><div class="chips">
          <?php foreach ($models as $m): ?><a class="chip" href="<?= e(tag_url($m)) ?>"><?= e($m) ?></a><?php endforeach; ?>
        </div></div>
        <?php endif; ?>
        <?php if ($tags): ?>
        <div class="row"><span class="lbl">Tags</span><div class="chips">
          <?php foreach ($tags as $t): ?><a class="chip" href="<?= e(tag_url($t)) ?>"><?= e($t) ?></a><?php endforeach; ?>
        </div></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <aside class="side">
    <?php if ($ad = setting('ad_sidebar', '')): ?><div class="ad ad-side"><?= $ad ?></div><?php endif; ?>
    <h3>Vídeos relacionados</h3>
    <div class="side-list">
      <?php foreach ($related as $r): $th = $r['thumb'] ?: base_url('assets/img/no-thumb.svg'); ?>
      <a class="side-item" href="<?= e(video_url($r)) ?>">
        <span class="thumb"><img src="<?= e($th) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.src='<?= e(base_url('assets/img/no-thumb.svg')) ?>'">
          <?php if ($d = fmt_duration((int)$r['duration'])): ?><span class="dur"><?= $d ?></span><?php endif; ?></span>
        <span><span class="t"><?= e($r['title']) ?></span><span class="meta"><span><?= fmt_views((int)$r['views']) ?> views</span></span></span>
      </a>
      <?php endforeach; ?>
    </div>
  </aside>
</div>

<div class="modal" id="report-modal">
  <form class="modal-box" id="report-form">
    <h3>Denunciar vídeo</h3>
    <input type="hidden" name="id" value="<?= $v['id'] ?>">
    <div class="field"><label>Motivo</label>
      <select name="reason">
        <option value="menor">Possível menor de idade</option>
        <option value="sem_consentimento">Conteúdo sem consentimento</option>
        <option value="direitos">Direitos autorais</option>
        <option value="quebrado">Vídeo não funciona</option>
        <option value="outro">Outro</option>
      </select></div>
    <div class="field"><label>Detalhes (opcional)</label><textarea name="details" rows="3"></textarea></div>
    <div class="actions" style="justify-content:flex-end"><button type="button" class="btn" data-close>Cancelar</button><button class="btn btn-primary">Enviar</button></div>
  </form>
</div>

<script type="application/ld+json"><?= json_encode([
  '@context' => 'https://schema.org', '@type' => 'VideoObject', 'name' => $v['title'],
  'description' => $pageDesc, 'thumbnailUrl' => $v['thumb'], 'uploadDate' => date('c', strtotime($v['created_at'])),
  'duration' => 'PT' . (int)$v['duration'] . 'S', 'embedUrl' => $v['embed_url'], 'isFamilyFriendly' => false,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
