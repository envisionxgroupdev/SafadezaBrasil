<?php
require __DIR__ . '/_layout.php';
require_admin();
$cats = all_categories();
$provs = importer_providers();

function cat_checks(array $cats, string $name = 'categories[]'): string {
    $h = '<div class="checks">';
    foreach ($cats as $c) $h .= '<label><input type="checkbox" name="' . $name . '" value="' . $c['id'] . '"><span>' . e($c['name']) . '</span></label>';
    return $h . '</div>';
}
$commonOpts = function (array $cats) { ?>
  <div class="row">
    <div class="field"><label>Status ao importar</label>
      <select name="status"><option value="published">Publicado (aparece no site)</option><option value="draft">Rascunho (revisar antes)</option></select></div>
    <div class="field"><label>&nbsp;</label>
      <label style="font-weight:500"><input type="checkbox" name="auto_categories" value="1" checked> Categorizar automaticamente pelo título/tags</label></div>
  </div>
  <div class="field"><label>Adicionar nestas categorias</label><?= cat_checks($cats) ?></div>
<?php };

admin_header('Importador de vídeos', 'import');
?>
<div class="card">
  <strong>Sites suportados:</strong>
  <div class="providers"><?php foreach ($provs as $p): ?><span class="tag"><?= e($p['name']) ?></span><?php endforeach; ?><span class="tag">+ qualquer &lt;iframe&gt;</span></div>
  <p class="muted" style="margin:10px 0 0">O vídeo continua hospedado no site de origem. A <b>thumb é baixada para o seu servidor</b> (junto com quadros para a prévia animada),
     então ela sempre aparece. Vídeos repetidos são ignorados automaticamente.</p>
</div>

<div class="tabs">
  <button class="active" data-tab="single">🔗 Link único</button>
  <button data-tab="bulk">📋 Em massa (vários links)</button>
  <button data-tab="search">🔎 Buscar e importar</button>
</div>

<!-- ============ LINK ÚNICO ============ -->
<div class="tab active" id="tab-single">
  <div class="card">
    <div class="field"><label>Link do vídeo ou código de embed</label>
      <div class="input-row">
        <input type="text" id="single-input" placeholder="https://www.xvideos.com/video.abc123/...   ou   <iframe src=...>">
        <button class="btn btn-primary" id="single-fetch">Buscar dados</button>
      </div>
      <small>Cole o link da página do vídeo (XVideos, Pornhub, xHamster, etc.) ou o código &lt;iframe&gt; que o site fornece.</small>
    </div>
    <div id="single-msg"></div>

    <form id="single-form" style="display:none">
      <div class="preview">
        <div>
          <div class="player"><iframe id="single-player" allowfullscreen scrolling="no"></iframe></div>
          <img id="single-thumbprev" class="tthumb" style="width:100%;margin-top:10px" alt="">
          <div class="frames" id="single-frames"></div>
          <p class="muted" id="single-src" style="word-break:break-all;font-size:12px"></p>
        </div>
        <div>
          <input type="hidden" name="provider"><input type="hidden" name="source_id"><input type="hidden" name="source_url"><input type="hidden" name="thumbs">
          <div class="field"><label>Título</label><input type="text" name="title" required></div>
          <div class="row">
            <div class="field"><label>Duração</label><input type="text" name="duration" placeholder="12:34"></div>
            <div class="field"><label>Thumbnail (URL)</label><input type="url" name="thumb"></div>
          </div>
          <div class="field"><label>URL do embed</label><input type="url" name="embed_url" required></div>
          <div class="field"><label>Tags <small style="display:inline">(separadas por vírgula)</small></label><input type="text" name="tags"></div>
          <div class="field"><label>Modelos <small style="display:inline">(separados por vírgula)</small></label><input type="text" name="models"></div>
          <div class="field"><label>Descrição</label><textarea name="description" rows="2"></textarea></div>
          <?php $commonOpts($cats); ?>
          <label style="display:block;margin-bottom:14px"><input type="checkbox" name="featured" value="1"> Marcar como destaque</label>
          <button class="btn btn-primary">✓ Importar vídeo</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ============ EM MASSA ============ -->
<div class="tab" id="tab-bulk">
  <form class="card" id="bulk-form">
    <div class="field"><label>Links (um por linha) ou códigos &lt;iframe&gt;</label>
      <textarea name="list" rows="9" placeholder="https://www.xvideos.com/video.abc/...&#10;https://www.pornhub.com/view_video.php?viewkey=...&#10;https://xhamster.com/videos/...-xyz"></textarea></div>
    <?php $commonOpts($cats); ?>
    <div class="row">
      <label><input type="checkbox" name="require_title" value="1"> Pular vídeos sem título (quando o site não responder)</label>
      <label><input type="checkbox" name="no_meta" value="1"> Modo rápido (não buscar título/thumb — só o embed)</label>
    </div>
    <div style="margin-top:14px;display:flex;gap:10px">
      <button class="btn btn-primary" id="bulk-start">▶ Iniciar importação</button>
      <button type="button" class="btn btn-danger" id="bulk-stop" disabled>■ Parar</button>
    </div>
    <div class="progress"><span id="bulk-bar"></span></div>
    <div class="muted" id="bulk-count" style="margin-top:6px"></div>
    <div class="log" id="bulk-log" style="display:none"></div>
  </form>
</div>

<!-- ============ BUSCAR ============ -->
<div class="tab" id="tab-search">
  <form class="card" id="search-form">
    <div class="row">
      <div class="field"><label>Site</label><select name="provider">
        <?php foreach (importer_search_providers() as $k => $n): ?><option value="<?= $k ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Palavra-chave</label><input type="text" name="q" placeholder="ex.: brasileira" required></div>
      <div class="field"><label>Ordenar</label><select name="order"><option value="recentes">Mais recentes</option><option value="vistos">Mais vistos</option><option value="curtidos">Mais bem avaliados</option></select></div>
      <div class="field"><label>Página</label><input type="number" name="page" value="1" min="1"></div>
    </div>
    <button class="btn btn-primary">🔎 Buscar</button>
    <small class="muted" style="margin-left:10px">Usa a API pública de cada site. Clique nos vídeos para selecionar.</small>
  </form>

  <div class="card" id="search-results" style="display:none">
    <div class="toolbar">
      <strong id="search-info"></strong>
      <button type="button" class="btn btn-sm" id="search-all">Selecionar todos</button>
      <button type="button" class="btn btn-sm" id="search-none">Limpar</button>
      <button type="button" class="btn btn-sm" id="search-next">Próxima página →</button>
    </div>
    <form id="search-opts"><?php $commonOpts($cats); ?></form>
    <button type="button" class="btn btn-primary" id="search-import">⬇️ Importar selecionados (<span id="sel-count">0</span>)</button>
    <div class="progress" id="search-progress" style="display:none"><span id="search-bar"></span></div>
    <div class="muted" id="search-status" style="margin-top:6px"></div>
    <div class="rgrid" id="search-grid"></div>
  </div>
</div>
<?php admin_footer(); ?>
