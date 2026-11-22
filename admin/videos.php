<?php
require __DIR__ . '/_layout.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ids = array_map('intval', (array)($_POST['ids'] ?? []));
    $act = $_POST['bulk'] ?? '';
    @set_time_limit(300);
    $catId = (int)($_POST['bulk_cat'] ?? 0);
    $n = 0;
    foreach ($ids as $id) {
        $v = db()->find('videos', $id);
        if (!$v) continue;
        switch ($act) {
            case 'publish':   db()->update('videos', $id, ['status' => 'published']); break;
            case 'draft':     db()->update('videos', $id, ['status' => 'draft']); break;
            case 'feature':   db()->update('videos', $id, ['featured' => 1]); break;
            case 'unfeature': db()->update('videos', $id, ['featured' => 0]); break;
            case 'delete':    thumbs_delete($v); db()->delete('videos', $id); break;
            case 'fixthumbs':
                if (thumb_is_local($v['thumb'])) {
                    if ($new = thumbs_optimize_local($v)) db()->update('videos', $id, $new);
                } else {
                    $meta = ($p = importer_parse($v['source_url'] ?: $v['embed_url'])) ? importer_fetch_meta($p) : [];
                    $th = thumbs_store($v['provider'], (string)$v['source_id'], ($meta['thumb'] ?? '') ?: (string)$v['thumb'], $meta['thumbs'] ?? []);
                    if ($th['thumb'] !== '') db()->update('videos', $id, $th);
                }
                break;
            case 'addcat':
                if ($catId) { $c = split_list($v['categories']); $c[] = (string)$catId; db()->update('videos', $id, ['categories' => implode(',', array_unique($c))]); }
                break;
            case 'delcat':
                if ($catId) db()->update('videos', $id, ['categories' => implode(',', array_diff(split_list($v['categories']), [(string)$catId]))]);
                break;
            default: continue 2;
        }
        $n++;
    }
    clear_public_cache();
    flash($n ? "$n vídeo(s) atualizados." : 'Nenhum vídeo selecionado.', $n ? 'ok' : 'err');
    redirect($_SERVER['REQUEST_URI']);
}

$q = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$provider = $_GET['provider'] ?? '';
$cat = (int)($_GET['cat'] ?? 0);
$sort = $_GET['sort'] ?? 'id DESC';
if (!in_array($sort, ['id DESC', 'id ASC', 'views DESC', 'likes DESC', 'title ASC'], true)) $sort = 'id DESC';
$opts = ['where' => array_filter(['status' => $status, 'provider' => $provider])];
if ($q !== '') $opts['search'] = [['title', 'tags', 'source_id'], $q];
if ($cat) $opts['in_set'] = ['categories', $cat];
$page = current_page(); $per = 50;
$total = db()->count('videos', $opts);
$videos = db()->query('videos', $opts + ['order' => $sort, 'limit' => $per, 'offset' => ($page - 1) * $per]);
$cats = all_categories(); $catMap = category_map(); $provs = importer_providers();

admin_header('Vídeos', 'videos');
?>
<?php $tp = setting_on('thumbs_local') ? thumbs_pending_count() : 0; $tprob = thumbs_problem(); if ($tp || $tprob): ?>
  <div class="alert <?= $tprob ? 'err' : 'info' ?>" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <span style="flex:1;min-width:220px">🖼️ <?= $tprob ? e($tprob) : '<b>' . $tp . '</b> vídeo(s) com thumb ainda não salva no servidor.' ?> <span id="tq-status"></span></span>
    <?php if (!$tprob): ?><button class="btn btn-sm btn-primary" data-thumb-queue="#tq-status">Baixar thumbs agora</button><?php endif; ?>
  </div>
<?php endif; ?>
<form class="toolbar" method="get">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar título, tag, ID...">
  <select name="status"><option value="">Todos status</option>
    <?php foreach (['published' => 'Publicados', 'draft' => 'Rascunhos', 'review' => 'Em revisão'] as $k => $l): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <select name="provider"><option value="">Todos sites</option>
    <?php foreach ($provs + ['custom' => ['name' => 'Outros']] as $k => $p): ?><option value="<?= $k ?>" <?= $provider === $k ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
  <select name="cat"><option value="0">Todas categorias</option>
    <?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>" <?= $cat === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
  <select name="sort">
    <?php foreach (['id DESC' => 'Mais novos', 'id ASC' => 'Mais antigos', 'views DESC' => 'Mais vistos', 'likes DESC' => 'Mais curtidos', 'title ASC' => 'Título A-Z'] as $k => $l): ?><option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <button class="btn">Filtrar</button>
  <?php if (setting_on('thumbs_local')): ?><button type="button" class="btn" data-thumb-queue="#tq-vid" data-requeue="1" style="margin-left:auto">🖼️ Baixar thumbs que faltam</button><?php endif; ?>
  <button type="button" class="btn" id="check-thumbs">🔍 Verificar thumbs quebradas</button>
  <a class="btn btn-primary" href="video-edit.php">＋ Adicionar</a>
  <div id="check-out" style="flex-basis:100%"></div>
  <span id="tq-vid" class="muted" style="flex-basis:100%"></span>
</form>

<form method="post" class="card">
  <?= csrf_field() ?>
  <div class="toolbar">
    <strong><?= number_format($total, 0, ',', '.') ?> vídeos</strong>
    <select name="bulk">
      <option value="">Ação em massa...</option>
      <option value="publish">Publicar</option><option value="draft">Mover p/ rascunho</option>
      <option value="feature">Marcar destaque</option><option value="unfeature">Tirar destaque</option>
      <option value="addcat">Adicionar à categoria →</option><option value="delcat">Remover da categoria →</option>
      <option value="fixthumbs">Baixar/otimizar thumbs</option>
      <option value="delete">Excluir</option>
    </select>
    <select name="bulk_cat"><option value="0">(categoria)</option><?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select>
    <button class="btn" data-confirm="Aplicar esta ação aos vídeos selecionados?">Aplicar</button>
  </div>
  <div class="table-wrap">
  <table class="vtable">
    <thead><tr><th style="width:30px"><input type="checkbox" id="check-all"></th><th>Vídeo</th><th></th><th class="hide-m">Site</th><th class="hide-m">Categorias</th><th>Views</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($videos as $v): ?>
      <tr>
        <td class="c-check"><input type="checkbox" class="row-check" name="ids[]" value="<?= $v['id'] ?>"></td>
        <td class="c-thumb" style="width:120px"><img class="tthumb" src="<?= e(video_thumb_url($v)) ?>" referrerpolicy="no-referrer" loading="lazy" alt=""></td>
        <td class="c-title"><a class="ttitle" href="video-edit.php?id=<?= $v['id'] ?>"><?= !empty($v['featured']) ? '⭐ ' : '' ?><?= e($v['title']) ?></a>
            <small class="muted"><?= fmt_duration((int)$v['duration']) ?> · #<?= $v['id'] ?> · <?= date('d/m/Y', strtotime($v['created_at'])) ?></small></td>
        <td class="hide-m"><span class="tag"><?= e($provs[$v['provider']]['name'] ?? $v['provider']) ?></span></td>
        <td class="hide-m" style="max-width:200px"><small><?= e(implode(', ', array_map(fn($id) => $catMap[$id]['name'] ?? '', split_list($v['categories'])))) ?></small></td>
        <td class="c-views"><?= fmt_views((int)$v['views']) ?> <span class="hide-d muted">views</span></td>
        <td class="c-status"><span class="tag <?= e($v['status']) ?>"><?= e(['published' => 'Publicado', 'draft' => 'Rascunho', 'review' => 'Revisão'][$v['status']] ?? $v['status']) ?></span></td>
        <td class="c-act" style="white-space:nowrap"><a class="btn btn-sm" href="video-edit.php?id=<?= $v['id'] ?>">Editar</a> <a class="btn btn-sm" href="<?= e(video_url($v)) ?>" target="_blank">↗</a></td>
      </tr>
    <?php endforeach; if (!$videos): ?><tr><td colspan="8" class="muted" style="text-align:center;padding:30px">Nenhum vídeo. <a href="import.php">Importar agora</a></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</form>
<?php
$qs = $_GET; unset($qs['page']);
echo str_replace('class="pagination"', 'class="pagination toolbar"', paginate($total, $page, $per, fn($p) => '?' . http_build_query($qs + ['page' => $p])));
admin_footer();
