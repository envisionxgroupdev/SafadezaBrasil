<?php
require __DIR__ . '/_layout.php';
require_admin();

$keys = ['site_host', 'seo_google_verify', 'seo_bing_verify', 'seo_yandex_verify', 'seo_pinterest_verify', 'seo_ga_id', 'seo_gtm_id',
         'seo_home_title', 'seo_home_description', 'seo_keywords', 'seo_og_image', 'seo_robots'];
$robotsFile = __DIR__ . '/../robots.txt';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($keys as $k) set_setting($k, trim($_POST[$k] ?? ''));
    $h = strtolower(preg_replace('~^https?://|/.*$~i', '', trim($_POST['site_host'] ?? '')));
    set_setting('site_host', preg_match('/^[a-z0-9.-]+(:\d+)?$/', $h) ? $h : '');
    set_setting('seo_noindex', !empty($_POST['seo_noindex']) ? '1' : '');
    // Grava o robots.txt de verdade na raiz (se a hospedagem permitir). Se não, o robots.php responde no lugar.
    $written = @file_put_contents($robotsFile, robots_content()) !== false;
    flash('SEO salvo.' . ($written ? '' : ' (robots.txt servido pelo robots.php — a pasta raiz não permite gravar)'));
    redirect('seo.php');
}
$s = []; foreach ($keys as $k) $s[$k] = setting($k, '');
$noindex = (bool)setting('seo_noindex', '');
$sitemap = absolute_url('sitemap.php');
$robotsUrl = absolute_url('robots.txt');
$domain = parse_url(absolute_url(), PHP_URL_HOST);

admin_header('SEO e buscadores', 'seo');
?>
<?php if ($noindex): ?><div class="alert err">⚠ O site está BLOQUEADO para buscadores (Google/Bing não vão indexar). Desmarque a opção abaixo quando quiser aparecer.</div><?php endif; ?>

<div class="card">
  <h2>Links para enviar aos buscadores</h2>
  <div class="row">
    <div class="field"><label>Sitemap</label>
      <div style="display:flex;gap:8px"><input type="text" readonly value="<?= e($sitemap) ?>" onclick="this.select()"><a class="btn" href="<?= e($sitemap) ?>" target="_blank">Abrir</a></div>
      <small>Cole este link em Google Search Console → Sitemaps e em Bing Webmaster → Sitemaps. Ele se atualiza sozinho a cada vídeo importado.</small></div>
    <div class="field"><label>robots.txt</label>
      <div style="display:flex;gap:8px"><input type="text" readonly value="<?= e($robotsUrl) ?>" onclick="this.select()"><a class="btn" href="<?= e($robotsUrl) ?>" target="_blank">Abrir</a></div></div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn" href="https://search.google.com/search-console" target="_blank" rel="noreferrer">Google Search Console ↗</a>
    <a class="btn" href="https://www.bing.com/webmasters" target="_blank" rel="noreferrer">Bing Webmaster ↗</a>
    <a class="btn" href="https://webmaster.yandex.com" target="_blank" rel="noreferrer">Yandex Webmaster ↗</a>
    <a class="btn" href="https://analytics.google.com" target="_blank" rel="noreferrer">Google Analytics ↗</a>
  </div>
</div>

<form method="post">
  <?= csrf_field() ?>
  <div class="card">
    <h2>Domínio do site</h2>
    <div class="field"><label>Domínio principal</label><input type="text" name="site_host" value="<?= e($s['site_host']) ?>" placeholder="<?= e($_SERVER['HTTP_HOST'] ?? 'seudominio.com') ?>">
      <small>Usado no sitemap e nos links de compartilhamento. Preenchido na instalação; mude só se trocar de domínio.</small></div>
  </div>

  <div class="card">
    <h2>Verificação de propriedade</h2>
    <p class="muted" style="margin-top:-6px">Na hora de adicionar o site, escolha o método <b>“Tag HTML”</b> / <b>“Meta tag”</b>. Pode colar a tag inteira ou só o código.</p>
    <div class="row">
      <div class="field"><label>Google Search Console</label><input type="text" name="seo_google_verify" value="<?= e($s['seo_google_verify']) ?>" placeholder='<meta name="google-site-verification" content="..." />'></div>
      <div class="field"><label>Bing Webmaster Tools</label><input type="text" name="seo_bing_verify" value="<?= e($s['seo_bing_verify']) ?>" placeholder='<meta name="msvalidate.01" content="..." />'></div>
      <div class="field"><label>Yandex</label><input type="text" name="seo_yandex_verify" value="<?= e($s['seo_yandex_verify']) ?>" placeholder='<meta name="yandex-verification" content="..." />'></div>
      <div class="field"><label>Pinterest</label><input type="text" name="seo_pinterest_verify" value="<?= e($s['seo_pinterest_verify']) ?>" placeholder='<meta name="p:domain_verify" content="..." />'></div>
    </div>
  </div>

  <div class="card">
    <h2>Estatísticas de visitas</h2>
    <div class="row">
      <div class="field"><label>Google Analytics 4 (ID de medição)</label><input type="text" name="seo_ga_id" value="<?= e($s['seo_ga_id']) ?>" placeholder="G-XXXXXXXXXX"><small>Analytics → Administrador → Fluxos de dados → ID da métrica.</small></div>
      <div class="field"><label>Google Tag Manager (opcional)</label><input type="text" name="seo_gtm_id" value="<?= e($s['seo_gtm_id']) ?>" placeholder="GTM-XXXXXXX"></div>
    </div>
  </div>

  <div class="card">
    <h2>Página inicial no Google</h2>
    <div class="field"><label>Título (aparece em azul no Google)</label>
      <input type="text" name="seo_home_title" value="<?= e($s['seo_home_title']) ?>" maxlength="70" placeholder="<?= e(site_name() . ' - ' . site_tagline()) ?>" data-count>
      <small><span></span> Ideal: até 60 caracteres.</small></div>
    <div class="field"><label>Descrição</label>
      <textarea name="seo_home_description" rows="2" maxlength="200" placeholder="<?= e(site_tagline()) ?>" data-count><?= e($s['seo_home_description']) ?></textarea>
      <small><span></span> Ideal: entre 120 e 160 caracteres.</small></div>
    <div class="field"><label>Palavras-chave (vírgula)</label><input type="text" name="seo_keywords" value="<?= e($s['seo_keywords']) ?>"></div>
    <div class="field"><label>Imagem ao compartilhar (URL, 1200×630)</label><input type="url" name="seo_og_image" value="<?= e($s['seo_og_image']) ?>"></div>

    <div style="background:#fff;border:1px solid var(--line);border-radius:10px;padding:14px 16px;max-width:620px;font-family:Arial,sans-serif">
      <div style="font-size:12px;color:#202124"><?= e($domain) ?></div>
      <div id="g-title" style="color:#1a0dab;font-size:19px;margin:3px 0"></div>
      <div id="g-desc" style="color:#4d5156;font-size:13.5px;line-height:1.5"></div>
    </div>
    <small class="muted">Prévia de como aparece no Google.</small>
  </div>

  <div class="card">
    <h2>Indexação</h2>
    <label style="display:block;margin-bottom:14px"><input type="checkbox" name="seo_noindex" value="1" <?= $noindex ? 'checked' : '' ?>> <b>Bloquear buscadores</b> (use só enquanto estiver montando o site)</label>
    <div class="field"><label>robots.txt</label>
      <textarea name="seo_robots" rows="7" style="font-family:ui-monospace,Consolas,monospace;font-size:13px" placeholder="<?= e(default_robots()) ?>"><?= e($s['seo_robots']) ?></textarea>
      <small>Vazio = padrão (bloqueia só o painel). Use <code>{SITEMAP}</code> para o link do sitemap.</small></div>
  </div>
  <button class="btn btn-primary">💾 Salvar SEO</button>
</form>

<script>
(function(){
  const t=document.querySelector('[name=seo_home_title]'), d=document.querySelector('[name=seo_home_description]');
  const upd=()=>{
    document.getElementById('g-title').textContent=(t.value||t.placeholder).slice(0,65);
    document.getElementById('g-desc').textContent=(d.value||d.placeholder).slice(0,165);
    document.querySelectorAll('[data-count]').forEach(el=>{const sp=el.parentNode.querySelector('small span');if(sp)sp.textContent=el.value.length+' caracteres.';});
  };
  [t,d].forEach(el=>el.addEventListener('input',upd)); upd();
})();
</script>
<?php admin_footer(); ?>
