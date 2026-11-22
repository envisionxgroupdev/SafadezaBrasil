<?php
require __DIR__ . '/_layout.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);
$v = $id ? db()->find('videos', $id) : null;
if ($id && !$v) { flash('Vídeo não encontrado.', 'err'); redirect('videos.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['delete']) && $v) {
        thumbs_delete($v); db()->delete('videos', $v['id']); clear_public_cache();
        flash('Vídeo excluído.'); redirect('videos.php');
    }
    $embed = trim($_POST['embed_url'] ?? '');
    // Se colou um link/iframe no campo embed, converte automaticamente
    if ($embed && ($p = importer_parse($embed))) $parsed = $p; else $parsed = null;
    $embedUrl = $parsed['embed_url'] ?? $embed;
    $title = trim($_POST['title'] ?? '');
    if ($title === '' || !is_http_url($embedUrl)) {
        flash('Título e URL de embed válida são obrigatórios.', 'err');
    } else {
        $data = [
            'title' => mb_substr($title, 0, 250),
            'slug' => unique_slug('videos', trim($_POST['slug'] ?? '') ?: $title, $v['id'] ?? null),
            'description' => trim($_POST['description'] ?? ''),
            'embed_url' => $embedUrl,
            'thumb' => $newThumb = (function ($t) { $t = trim((string)$t);
                return (is_http_url($t) || ($t !== '' && str_starts_with($t, THUMB_DIR . '/') && !str_contains($t, '..'))) ? $t : ''; })($_POST['thumb'] ?? ''),
            'duration' => parse_duration($_POST['duration'] ?? ''),
            'tags' => implode(', ', split_list($_POST['tags'] ?? '')),
            'models' => implode(', ', split_list($_POST['models'] ?? '')),
            'categories' => implode(',', array_map('intval', (array)($_POST['categories'] ?? []))),
            'featured' => !empty($_POST['featured']) ? 1 : 0,
            'status' => in_array($_POST['status'] ?? '', ['published', 'draft', 'review'], true) ? $_POST['status'] : 'published',
            'views' => max(0, (int)($_POST['views'] ?? 0)),
            'likes' => max(0, (int)($_POST['likes'] ?? 0)),
            'dislikes' => max(0, (int)($_POST['dislikes'] ?? 0)),
        ];
        // Thumb nova colada como link: baixa para o servidor
        if ($newThumb !== '' && !thumb_is_local($newThumb) && $newThumb !== ($v['thumb'] ?? null)) {
            $th = thumbs_store($parsed['provider'] ?? ($v['provider'] ?? 'custom'), (string)($v['source_id'] ?? 'manual'), $newThumb);
            $data['thumb'] = $th['thumb'];
        }
        if ($v) {
            if ($parsed) $data += ['provider' => $parsed['provider'], 'source_id' => $parsed['source_id'], 'source_url' => $parsed['source_url']];
            db()->update('videos', $v['id'], $data);
            flash('Vídeo salvo.');
            $newId = $v['id'];
        } else {
            $data += ['provider' => $parsed['provider'] ?? 'custom', 'source_id' => $parsed['source_id'] ?? substr(md5($embedUrl), 0, 16),
                      'source_url' => $parsed['source_url'] ?? '', 'created_at' => now()];
            if (importer_exists($data['provider'], $data['source_id'])) { flash('Esse vídeo já existe no site.', 'err'); redirect('video-edit.php'); }
            $newId = db()->insert('videos', $data);
            flash('Vídeo adicionado.');
        }
        clear_public_cache();
        redirect('video-edit.php?id=' . $newId);
    }
}

$v = $v ?: ['title' => '', 'slug' => '', 'description' => '', 'embed_url' => '', 'thumb' => '', 'duration' => 0, 'tags' => '', 'models' => '',
            'categories' => '', 'featured' => 0, 'status' => 'published', 'views' => 0, 'likes' => 0, 'dislikes' => 0, 'provider' => '', 'source_url' => ''];
$vCats = split_list($v['categories']);
admin_header(isset($v['id']) ? 'Editar vídeo' : 'Adicionar vídeo', 'videos');
?>
<form method="post" class="grid2 grid-wide">
  <?= csrf_field() ?>
  <div class="card">
    <div class="field"><label>Título</label><input type="text" name="title" value="<?= e($v['title']) ?>" required></div>
    <div class="field"><label>Slug (URL)</label><input type="text" name="slug" value="<?= e($v['slug']) ?>" placeholder="gerado automaticamente"></div>
    <div class="field"><label>URL do embed</label><input type="text" name="embed_url" value="<?= e($v['embed_url']) ?>" required>
      <small>Pode colar o link da página do vídeo ou o &lt;iframe&gt; — é convertido automaticamente.</small></div>
    <div class="row">
      <div class="field"><label>Thumbnail</label><input type="text" name="thumb" value="<?= e($v['thumb']) ?>"><small>Cole o link de uma imagem: ela é baixada para o servidor.</small></div>
      <div class="field"><label>Duração</label><input type="text" name="duration" value="<?= e(fmt_duration((int)$v['duration'])) ?>" placeholder="12:34"></div>
    </div>
    <div class="field"><label>Tags (vírgula)</label><input type="text" name="tags" value="<?= e($v['tags']) ?>"></div>
    <div class="field"><label>Modelos (vírgula)</label><input type="text" name="models" value="<?= e($v['models'] ?? '') ?>"></div>
    <div class="field"><label>Descrição</label><textarea name="description" rows="4"><?= e($v['description']) ?></textarea></div>
    <div class="field"><label>Categorias</label><div class="checks">
      <?php foreach (all_categories() as $c): ?><label><input type="checkbox" name="categories[]" value="<?= $c['id'] ?>" <?= in_array((string)$c['id'], $vCats, true) ? 'checked' : '' ?>><span><?= e($c['name']) ?></span></label><?php endforeach; ?>
    </div></div>
  </div>
  <div>
    <div class="card">
      <?php if ($v['thumb'] || !empty($v['thumbs'])): ?>
        <img class="tthumb" style="width:100%;margin-bottom:6px" src="<?= e(thumb_url($v['thumb'])) ?>" alt="">
        <?php $pv = thumb_preview($v['thumbs'] ?? ''); if (isset($pv['sprite'])): ?>
          <img src="<?= e($pv['sprite']) ?>" alt="" style="width:100%;border-radius:4px;margin-bottom:12px" title="Quadros da prévia animada (<?= (int)$pv['n'] ?>)">
        <?php elseif (!empty($pv['frames'])): ?><div class="frames" style="margin-bottom:12px"><?php foreach ($pv['frames'] as $f): ?><img src="<?= e($f) ?>" alt=""><?php endforeach; ?></div><?php endif; ?>
        <?php if (!thumb_is_local($v['thumb'])): ?><p class="alert err" style="font-size:12.5px">Thumb ainda não está salva no servidor. Use “Baixar/reparar thumbs” na lista de vídeos.</p><?php endif; ?>
      <?php endif; ?>
      <?php if ($v['embed_url']): ?><div class="preview" style="display:block;margin:0 0 14px"><div class="player"><iframe src="<?= e($v['embed_url']) ?>" allowfullscreen scrolling="no"></iframe></div></div><?php endif; ?>
      <div class="field"><label>Status</label><select name="status">
        <?php foreach (['published' => 'Publicado', 'draft' => 'Rascunho', 'review' => 'Em revisão (fora do ar)'] as $k => $l): ?><option value="<?= $k ?>" <?= $v['status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
      </select></div>
      <label style="display:block;margin-bottom:14px"><input type="checkbox" name="featured" value="1" <?= !empty($v['featured']) ? 'checked' : '' ?>> ⭐ Destaque na página inicial</label>
      <div class="row">
        <div class="field"><label>Views</label><input type="number" name="views" value="<?= (int)$v['views'] ?>"></div>
        <div class="field"><label>Likes</label><input type="number" name="likes" value="<?= (int)$v['likes'] ?>"></div>
        <div class="field"><label>Dislikes</label><input type="number" name="dislikes" value="<?= (int)$v['dislikes'] ?>"></div>
      </div>
      <?php if (!empty($v['source_url']) && is_http_url($v['source_url'])): ?><p class="muted" style="word-break:break-all;font-size:12px">Origem: <a href="<?= e($v['source_url']) ?>" target="_blank" rel="noreferrer"><?= e($v['source_url']) ?></a></p><?php endif; ?>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-primary">💾 Salvar</button>
        <?php if (isset($v['id'])): ?>
          <a class="btn" href="<?= e(video_url($v)) ?>" target="_blank">↗ Ver</a>
          <button class="btn btn-danger" name="delete" value="1" data-confirm="Excluir este vídeo?">Excluir</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</form>
<?php admin_footer(); ?>
