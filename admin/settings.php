<?php
require __DIR__ . '/_layout.php';
require_admin();

$keys = ['site_name', 'site_tagline', 'color_primary', 'contact_email', 'ad_header', 'ad_player', 'ad_sidebar', 'ad_footer', 'head_code', 'footer_code'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['form'] ?? '') === 'clean_thumbs') {
        @set_time_limit(300);
        [$n, $b] = thumbs_clean_orphans();
        flash($n ? "$n arquivo(s) sem uso apagados — " . fmt_bytes($b) . ' liberados.' : 'Nenhuma thumb sem uso encontrada.');
        redirect('settings.php#thumbs');
    }
    if (($_POST['form'] ?? '') === 'optimize_thumbs') {
        @set_time_limit(300);
        $done = 0; $saved = 0; $offset = 0; $start = time();
        while (time() - $start < 240 && ($chunk = db()->query('videos', ['order' => 'id ASC', 'limit' => 200, 'offset' => $offset]))) {
            foreach ($chunk as $v) {
                if (time() - $start >= 240) break;
                $before = array_sum(array_map(fn($f) => @filesize(__DIR__ . '/../' . $f) ?: 0, thumbs_files($v)));
                if ($new = thumbs_optimize_local($v)) {
                    db()->update('videos', $v['id'], $new);
                    $after = array_sum(array_map(fn($f) => @filesize(__DIR__ . '/../' . $f) ?: 0, thumbs_files(array_merge($v, $new))));
                    $done++; $saved += max(0, $before - $after);
                }
            }
            $offset += 200;
        }
        flash($done ? "$done vídeo(s) convertidos para o formato leve — " . fmt_bytes($saved) . ' economizados.' . (time() - $start >= 240 ? ' Clique de novo para continuar.' : '') : 'Todas as thumbs já estão no formato leve.');
        redirect('settings.php#thumbs');
    }
    if (($_POST['form'] ?? '') === 'password') {
        $me = db()->find('admins', $_SESSION['admin_id']);
        $new = $_POST['new'] ?? '';
        if (!$me || !password_verify($_POST['current'] ?? '', $me['password_hash'])) flash('Senha atual incorreta.', 'err');
        elseif (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) flash('A nova senha precisa ter pelo menos 8 caracteres, com letras e números.', 'err');
        elseif (password_verify($new, $me['password_hash'])) flash('A nova senha precisa ser diferente da atual.', 'err');
        elseif ($new !== ($_POST['confirm'] ?? '')) flash('As senhas não conferem.', 'err');
        else {
            $upd = ['password_hash' => password_hash($new, PASSWORD_DEFAULT)];
            $user = trim($_POST['username'] ?? '');
            if ($user !== '' && $user !== $me['username']) { $upd['username'] = $user; $_SESSION['admin_user'] = $user; }
            if (isset($upd['username']) && db()->findBy('admins', 'username', $upd['username'])) { flash('Esse nome de usuário já existe.', 'err'); redirect('settings.php#senha'); }
            db()->update('admins', $me['id'], $upd);
            // desconecta os outros dispositivos e mantém este logado
            admin_revoke_all_sessions();
            admin_login_session(db()->find('admins', $me['id']));
            flash('Login atualizado. Outros dispositivos conectados foram desconectados.');
        }
    } else {
        foreach ($keys as $k) set_setting($k, trim($_POST[$k] ?? ''));
        set_setting('thumbs_local', !empty($_POST['thumbs_local']) ? '1' : '0');
        set_setting('thumb_frames', (string)max(0, min(6, (int)($_POST['thumb_frames'] ?? 6))));
        set_setting('thumb_width', in_array((int)($_POST['thumb_width'] ?? 480), [400, 480, 640], true) ? (string)(int)$_POST['thumb_width'] : '480');
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

  <h2 style="margin-top:10px" id="thumbs">Thumbs</h2>
  <div class="row">
    <div class="field"><label style="font-weight:500"><input type="checkbox" name="thumbs_local" value="1" <?= setting_on('thumbs_local') ? 'checked' : '' ?>> <b>Salvar as thumbs no meu servidor</b> (recomendado)</label>
      <small>Garante que as thumbs sempre aparecem. Desligado, o site usa o link do site de origem, que pode quebrar ou expirar.</small></div>
    <div class="field"><label>Quadros da prévia animada</label>
      <select name="thumb_frames"><?php foreach ([0, 3, 4, 5, 6] as $n): ?><option value="<?= $n ?>" <?= (int)setting('thumb_frames', 6) === $n ? 'selected' : '' ?>><?= $n ? "$n quadros" : 'Desligada' ?></option><?php endforeach; ?></select>
      <small>Ficam todos juntos numa imagem só (~25 KB). Desligada economiza ainda mais.</small></div>
    <div class="field"><label>Tamanho da thumb</label>
      <select name="thumb_width"><?php foreach ([400 => 'Econômica (400px · ~12 KB)', 480 => 'Padrão (480px · ~18 KB)', 640 => 'Alta (640px · ~30 KB)'] as $w => $l): ?><option value="<?= $w ?>" <?= thumb_width() === $w ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
      <small>Vale para as próximas importações. Formato WebP (leve e aceito por todos os navegadores).</small></div>
  </div>
  <div class="storage-box">
    <?php $st = thumbs_storage(); $nv = max(1, db()->count('videos')); ?>
    <div><b><?= fmt_bytes($st['bytes']) ?></b><span>espaço usado pelas thumbs</span></div>
    <div><b><?= number_format($st['files'], 0, ',', '.') ?></b><span>arquivos</span></div>
    <div><b><?= $st['files'] ? fmt_bytes((int)($st['bytes'] / $nv)) : '—' ?></b><span>média por vídeo</span></div>
    <div><b><?= fmt_bytes((int)(($st['files'] ? $st['bytes'] / $nv : 43 * 1024) * 10000)) ?></b><span>estimativa para 10 mil vídeos</span></div>
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

<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="form" value="clean_thumbs">
  <h2>Limpar thumbs sem uso</h2>
  <p class="muted" style="margin-top:-6px">Apaga do servidor as imagens que não pertencem a nenhum vídeo (sobras de vídeos excluídos ou importações que deram erro).</p>
  <button class="btn" data-confirm="Apagar as thumbs que nenhum vídeo usa?">🧹 Limpar agora</button>
</form>

<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="form" value="optimize_thumbs">
  <h2>Converter thumbs antigas</h2>
  <p class="muted" style="margin-top:-6px">Vídeos importados antes desta versão usam JPG grande e vários arquivos. Isso converte para WebP + imagem única de prévia, sem baixar nada de novo.</p>
  <button class="btn" data-confirm="Converter as thumbs antigas agora?">⚡ Converter</button>
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
