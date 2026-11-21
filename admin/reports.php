<?php
require __DIR__ . '/_layout.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $r = db()->find('reports', (int)($_POST['id'] ?? 0));
    if ($r) {
        switch ($_POST['do'] ?? '') {
            case 'close':     db()->update('reports', $r['id'], ['status' => 'closed']); flash('Denúncia arquivada.'); break;
            case 'restore':   db()->update('videos', $r['video_id'], ['status' => 'published']); db()->update('reports', $r['id'], ['status' => 'closed']); flash('Vídeo republicado e denúncia arquivada.'); break;
            case 'unpublish': db()->update('videos', $r['video_id'], ['status' => 'review']); flash('Vídeo retirado do ar.'); break;
            case 'delvideo':  db()->delete('videos', $r['video_id']); db()->update('reports', $r['id'], ['status' => 'closed']); flash('Vídeo excluído.'); break;
        }
        clear_public_cache();
    }
    redirect($_SERVER['REQUEST_URI']);
}

$status = ($_GET['status'] ?? 'open') === 'closed' ? 'closed' : 'open';
$reports = db()->query('reports', ['where' => ['status' => $status], 'order' => 'id DESC', 'limit' => 200]);
$reasons = ['menor' => '🚨 Possível menor', 'sem_consentimento' => '🚨 Sem consentimento', 'direitos' => 'Direitos autorais', 'quebrado' => 'Não funciona', 'outro' => 'Outro'];
admin_header('Denúncias', 'reports');
?>
<div class="alert info">Denúncias de <b>possível menor</b> ou <b>sem consentimento</b> tiram o vídeo do ar automaticamente até você revisar.
  Se confirmar, exclua o vídeo e denuncie também no site de origem (e à <a href="https://new.safernet.org.br/denuncie" target="_blank" rel="noreferrer">SaferNet</a> se envolver menor).</div>
<div class="toolbar">
  <a class="btn <?= $status === 'open' ? 'btn-primary' : '' ?>" href="?status=open">Abertas</a>
  <a class="btn <?= $status === 'closed' ? 'btn-primary' : '' ?>" href="?status=closed">Arquivadas</a>
</div>
<div class="card" style="overflow-x:auto">
<table>
  <thead><tr><th>Data</th><th>Vídeo</th><th>Motivo</th><th>Detalhes</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($reports as $r): $v = db()->find('videos', $r['video_id']); ?>
    <tr>
      <td style="white-space:nowrap"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
      <td><?php if ($v): ?><a class="ttitle" href="video-edit.php?id=<?= $v['id'] ?>"><?= e($v['title']) ?></a><span class="tag <?= e($v['status']) ?>"><?= e($v['status']) ?></span>
          <?php else: ?><span class="muted">(vídeo excluído)</span><?php endif; ?></td>
      <td><b><?= e($reasons[$r['reason']] ?? $r['reason']) ?></b></td>
      <td style="max-width:280px"><small><?= e($r['details']) ?: '—' ?></small></td>
      <td style="white-space:nowrap">
        <?php if ($status === 'open'): ?>
        <form method="post" style="display:flex;gap:6px;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
          <?php if ($v && $v['status'] !== 'published'): ?><button class="btn btn-sm" name="do" value="restore" data-confirm="Republicar o vídeo? Só faça isso se tiver certeza de que a denúncia é falsa.">Republicar</button>
          <?php elseif ($v): ?><button class="btn btn-sm" name="do" value="unpublish">Tirar do ar</button><?php endif; ?>
          <?php if ($v): ?><button class="btn btn-sm btn-danger" name="do" value="delvideo" data-confirm="Excluir o vídeo?">Excluir vídeo</button><?php endif; ?>
          <button class="btn btn-sm" name="do" value="close">Arquivar</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$reports): ?><tr><td colspan="5" class="muted" style="text-align:center;padding:30px">Nenhuma denúncia.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php admin_footer(); ?>
