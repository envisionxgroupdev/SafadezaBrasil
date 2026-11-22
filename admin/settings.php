<?php
require __DIR__ . '/_layout.php';
require_admin();

$keys = ['site_name', 'site_tagline', 'color_primary', 'contact_email', 'ad_header', 'ad_player', 'ad_sidebar', 'ad_footer', 'head_code', 'footer_code'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['form'] ?? '') === 'password') {
        $me = db()->find('admins', $_SESSION['admin_id']);
        $new = $_POST['new'] ?? '';
        if (!$me || !password_verify($_POST['current'] ?? '', $me['password_hash'])) flash('Senha atual incorreta.', 'err');
        elseif (strlen($new) < 8) flash('A nova senha precisa ter pelo menos 8 caracteres.', 'err');
        elseif ($new !== ($_POST['confirm'] ?? '')) flash('As senhas não conferem.', 'err');
        else {
            $upd = ['password_hash' => password_hash($new, PASSWORD_DEFAULT)];
            $user = trim($_POST['username'] ?? '');
            if ($user !== '' && $user !== $me['username']) { $upd['username'] = $user; $_SESSION['admin_user'] = $user; }
            db()->update('admins', $me['id'], $upd);
            flash('Login atualizado.');
        }
    } else {
        foreach ($keys as $k) set_setting($k, trim($_POST[$k] ?? ''));
        flash('Configurações salvas.');
    }
    redirect('settings.php');
}
$s = []; foreach ($keys as $k) $s[$k] = setting($k, '');
admin_header('Configurações', 'settings');
?>
<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="form" value="general">
  <h2>Geral</h2>
  <div class="row">
    <div class="field"><label>Nome do site</label><input type="text" name="site_name" value="<?= e($s['site_name']) ?>" placeholder="<?= e(SITE_NAME) ?>">
      <small>Vazio = usa o nome do config.php (<?= e(SITE_NAME) ?>).</small></div>
    <div class="field"><label>Slogan</label><input type="text" name="site_tagline" value="<?= e($s['site_tagline']) ?>" placeholder="<?= e(SITE_TAGLINE) ?>"></div>
  </div>
  <div class="row">
    <div class="field"><label>Cor principal</label>
      <div style="display:flex;gap:8px;align-items:center">
        <input type="color" value="<?= e(primary_color()) ?>" oninput="this.nextElementSibling.value=this.value" style="width:50px;height:40px;border:0;background:none">
        <input type="text" name="color_primary" value="<?= e($s['color_primary']) ?>" placeholder="#ff9000" style="flex:1;padding:10px;border:1px solid var(--line);border-radius:8px">
      </div><small>Padrão: laranja #ff9000. Vazio = padrão.</small></div>
    <div class="field"><label>E-mail de contato</label><input type="email" name="contact_email" value="<?= e($s['contact_email']) ?>"><small>Aparece nas páginas de Contato, DMCA e Termos.</small></div>
  </div>

  <h2 style="margin-top:10px">Anúncios (cole o código HTML da sua rede)</h2>
  <div class="row">
    <div class="field"><label>Topo (todas as páginas)</label><textarea name="ad_header" rows="4"><?= e($s['ad_header']) ?></textarea></div>
    <div class="field"><label>Abaixo do player</label><textarea name="ad_player" rows="4"><?= e($s['ad_player']) ?></textarea></div>
    <div class="field"><label>Lateral (página do vídeo)</label><textarea name="ad_sidebar" rows="4"><?= e($s['ad_sidebar']) ?></textarea></div>
    <div class="field"><label>Rodapé</label><textarea name="ad_footer" rows="4"><?= e($s['ad_footer']) ?></textarea></div>
  </div>
  <h2 style="margin-top:10px">Códigos extras</h2>
  <div class="row">
    <div class="field"><label>Dentro do &lt;head&gt; (Analytics, verificação, pop-under...)</label><textarea name="head_code" rows="4"><?= e($s['head_code']) ?></textarea></div>
    <div class="field"><label>Antes do &lt;/body&gt;</label><textarea name="footer_code" rows="4"><?= e($s['footer_code']) ?></textarea></div>
  </div>
  <button class="btn btn-primary">💾 Salvar configurações</button>
</form>

<form method="post" class="card" id="senha">
  <?= csrf_field() ?><input type="hidden" name="form" value="password">
  <h2>Login do admin</h2>
  <div class="row">
    <div class="field"><label>Usuário</label><input type="text" name="username" value="<?= e($_SESSION['admin_user'] ?? '') ?>"></div>
    <div class="field"><label>Senha atual</label><input type="password" name="current" required></div>
    <div class="field"><label>Nova senha</label><input type="password" name="new" required minlength="8"></div>
    <div class="field"><label>Confirmar</label><input type="password" name="confirm" required minlength="8"></div>
  </div>
  <button class="btn btn-primary">Atualizar login</button>
</form>
<?php admin_footer(); ?>
