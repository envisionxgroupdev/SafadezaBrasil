<?php
require __DIR__ . '/includes/bootstrap.php';
// Conta as tags dos vídeos publicados (cache de 1 hora)
$cache = __DIR__ . '/data/cache_tags.json';
if (is_file($cache) && filemtime($cache) > time() - 3600) $counts = json_decode(file_get_contents($cache), true) ?: [];
else {
    $counts = [];
    $offset = 0;
    while ($chunk = list_videos(['limit' => 1000, 'offset' => $offset])) {
        foreach ($chunk as $v) foreach (split_list($v['tags']) as $t) { $k = mb_strtolower($t); $counts[$k] = ($counts[$k] ?? 0) + 1; }
        $offset += 1000;
    }
    arsort($counts);
    $counts = array_slice($counts, 0, 300, true);
    @file_put_contents($cache, json_encode($counts, JSON_UNESCAPED_UNICODE));
}
$pageTitle = 'Tags';
require __DIR__ . '/includes/header.php';
?>
<div class="section-head"><h1>Tags populares</h1></div>
<?php if ($counts): ?>
<div class="chips">
  <?php foreach ($counts as $t => $n): ?><a class="chip" href="<?= e(tag_url($t)) ?>"><?= e($t) ?><span class="n"><?= $n ?></span></a><?php endforeach; ?>
</div>
<?php else: ?><div class="empty">Nenhuma tag ainda.</div><?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
