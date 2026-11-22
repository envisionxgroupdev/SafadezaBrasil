<?php
require __DIR__ . '/_layout.php';
require_admin();

$total = db()->count('videos');
$published = db()->count('videos', ['where' => ['status' => 'published']]);
$review = db()->count('videos', ['where' => ['status' => 'review']]);
$open = db()->count('reports', ['where' => ['status' => 'open']]);
$top = db()->query('videos', ['order' => 'views DESC', 'limit' => 8]);
$latest = db()->query('videos', ['order' => 'id DESC', 'limit' => 8]);
$views = 0; $byProv = []; $offset = 0;
while ($chunk = db()->query('videos', ['limit' => 2000, 'offset' => $offset])) {
    foreach ($chunk as $v) { $views += (int)$v['views']; $byProv[$v['provider']] = ($byProv[$v['provider']] ?? 0) + 1; }
    $offset += 2000;
}
arsort($byProv);
$me = db()->find('admins', $_SESSION['admin_id']);
$defaultPass = $me && password_verify(ADMIN_DEFAULT_PASS, $me['password_hash']);
$prov = importer_providers();

admin_header('Painel', 'index');
?>
<?php $tp = thumbs_pending_count(); $tprob = thumbs_problem(); if ($tp || $tprob): ?>
  <div class="alert <?= $tprob ? 'err' : 'info' ?>" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <span style="flex:1;min-width:220px">🖼️ <?= $tprob ? e($tprob) : '<b>' . $tp . '</b> vídeo(s) com thumb ainda não salva no servidor.' ?> <span id="tq-status"></span></span>
    <?php if (!$tprob): ?><button class="btn btn-sm btn-primary" data-thumb-queue="#tq-status">Baixar thumbs agora</button><?php endif; ?>
  </div>
<?php endif; ?>
<?php if ($defaultPass): ?><div class="alert err">⚠ Você ainda está usando a senha padrão. <a href="settings.php#senha">Troque agora</a>.</div><?php endif; ?>
<?php if ($review): ?><div class="alert err">⚑ <?= $review ?> vídeo(s) foram retirados do ar por denúncia grave e aguardam revisão. <a href="videos.php?status=review">Revisar</a></div><?php endif; ?>

<div class="stats">
  <div class="stat"><span>Vídeos no site</span><b><?= number_format($published, 0, ',', '.') ?></b></div>
  <div class="stat"><span>Total (inclui rascunhos)</span><b><?= number_format($total, 0, ',', '.') ?></b></div>
  <div class="stat"><span>Visualizações</span><b><?= number_format($views, 0, ',', '.') ?></b></div>
  <div class="stat"><span>Categorias</span><b><?= db()->count('categories') ?></b></div>
  <div class="stat" style="border-color:#ff4d6d"><span>Denúncias abertas</span><b><?= $open ?></b></div>
</div>

<div class="card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
  <strong style="margin-right:8px">Atalhos:</strong>
  <a class="btn btn-primary" href="import.php">⬇️ Importar vídeos</a>
  <a class="btn" href="video-edit.php">＋ Adicionar manualmente</a>
  <a class="btn" href="categories.php">🗂️ Categorias</a>
  <a class="btn" href="settings.php">⚙️ Configurações</a>
</div>

<div class="grid2">
  <div class="card"><h2>Mais vistos</h2>
    <table><?php foreach ($top as $v): ?>
      <tr><td style="width:90px"><img class="tthumb" style="width:80px" src="<?= e(video_thumb_url($v)) ?>" referrerpolicy="no-referrer" alt=""></td>
          <td><a class="ttitle" href="video-edit.php?id=<?= $v['id'] ?>"><?= e($v['title']) ?></a></td>
          <td style="text-align:right;white-space:nowrap"><b><?= fmt_views((int)$v['views']) ?></b></td></tr>
    <?php endforeach; if (!$top): ?><tr><td class="muted">Nenhum vídeo ainda.</td></tr><?php endif; ?></table>
  </div>
  <div class="card"><h2>Importados recentemente</h2>
    <table><?php foreach ($latest as $v): ?>
      <tr><td style="width:90px"><img class="tthumb" style="width:80px" src="<?= e(video_thumb_url($v)) ?>" referrerpolicy="no-referrer" alt=""></td>
          <td><a class="ttitle" href="video-edit.php?id=<?= $v['id'] ?>"><?= e($v['title']) ?></a><small class="muted"><?= e($prov[$v['provider']]['name'] ?? $v['provider']) ?> · <?= time_ago($v['created_at']) ?></small></td></tr>
    <?php endforeach; if (!$latest): ?><tr><td class="muted">Nenhum vídeo ainda.</td></tr><?php endif; ?></table>
  </div>
</div>

<?php if ($byProv): ?>
<div class="card"><h2>Vídeos por site de origem</h2>
  <div class="providers"><?php foreach ($byProv as $p => $n): ?><span class="tag"><?= e($prov[$p]['name'] ?? ucfirst($p)) ?>: <?= $n ?></span><?php endforeach; ?></div>
</div>
<?php endif; ?>
<?php admin_footer(); ?>
