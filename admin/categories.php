<?php
require __DIR__ . '/_layout.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    if (isset($_POST['delete']) && $id) {
        // remove a categoria dos vídeos também
        $off = 0;
        while ($chunk = db()->query('videos', ['in_set' => ['categories', $id], 'limit' => 500])) {
            foreach ($chunk as $v) db()->update('videos', $v['id'], ['categories' => implode(',', array_diff(split_list($v['categories']), [(string)$id]))]);
        }
        db()->delete('categories', $id);
        flash('Categoria excluída.');
    } else {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') flash('Informe o nome.', 'err');
        else {
            $data = ['name' => $name, 'slug' => unique_slug('categories', trim($_POST['slug'] ?? '') ?: $name, $id ?: null),
                     'description' => trim($_POST['description'] ?? ''), 'thumb' => trim($_POST['thumb'] ?? ''), 'sort' => (int)($_POST['sort'] ?? 0)];
            if ($id) { db()->update('categories', $id, $data); flash('Categoria salva.'); }
            else { db()->insert('categories', $data); flash('Categoria criada.'); }
        }
    }
    redirect('categories.php');
}

$edit = isset($_GET['edit']) ? db()->find('categories', (int)$_GET['edit']) : null;
$cats = all_categories();
admin_header('Categorias', 'categories');
?>
<div class="grid2 grid-wide">
  <div class="card">
    <h2><?= count($cats) ?> categorias</h2>
    <div class="table-wrap"><table>
      <thead><tr><th class="hide-m">Ordem</th><th>Nome</th><th>Vídeos</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($cats as $c): ?>
        <tr>
          <td class="hide-m"><?= (int)$c['sort'] ?></td>
          <td><strong><?= e($c['name']) ?></strong><br><small class="muted">/<?= e($c['slug']) ?></small></td>
          <td><?= db()->count('videos', ['in_set' => ['categories', $c['id']]]) ?></td>
          <td style="white-space:nowrap;text-align:right">
            <a class="btn btn-sm" href="?edit=<?= $c['id'] ?>">Editar</a>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $c['id'] ?>">
              <button class="btn btn-sm btn-danger" name="delete" value="1" data-confirm="Excluir a categoria “<?= e($c['name']) ?>”? Os vídeos não serão apagados.">Excluir</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <form method="post" class="card">
    <?= csrf_field() ?>
    <h2><?= $edit ? 'Editar categoria' : 'Nova categoria' ?></h2>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="field"><label>Nome</label><input type="text" name="name" value="<?= e($edit['name'] ?? '') ?>" required></div>
    <div class="field"><label>Slug (URL)</label><input type="text" name="slug" value="<?= e($edit['slug'] ?? '') ?>" placeholder="automático"></div>
    <div class="field"><label>Descrição (SEO)</label><textarea name="description" rows="3"><?= e($edit['description'] ?? '') ?></textarea></div>
    <div class="field"><label>Imagem (URL)</label><input type="url" name="thumb" value="<?= e($edit['thumb'] ?? '') ?>"><small>Vazio = usa a thumb do vídeo mais visto da categoria.</small></div>
    <div class="field"><label>Ordem</label><input type="number" name="sort" value="<?= (int)($edit['sort'] ?? count($cats) + 1) ?>"></div>
    <button class="btn btn-primary">💾 Salvar</button>
    <?php if ($edit): ?><a class="btn" href="categories.php">Cancelar</a><?php endif; ?>
  </form>
</div>
<?php admin_footer(); ?>
