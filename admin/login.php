<?php
require __DIR__ . '/../includes/bootstrap.php';
db_upgrade();
private_page_headers();
if (is_admin()) redirect(base_url('admin/'));

$err = '';
$user = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $user = trim((string)($_POST['user'] ?? ''));
    if (!empty($_POST['website'])) {                       // campo invisível: só robô preenche
        $err = 'Usuário ou senha incorretos.';
    } elseif ($wait = login_blocked($user)) {
        $err = "Muitas tentativas erradas. Por segurança, aguarde $wait minutos.";
    } else {
        $u = $user !== '' ? db()->findBy('admins', 'username', $user) : null;
        // sempre roda o password_verify (mesmo tempo de resposta com ou sem usuário válido)
        $ok = password_verify((string)($_POST['pass'] ?? ''), $u['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
        if ($u && $ok) {
            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) db()->update('admins', $u['id'], ['password_hash' => password_hash($_POST['pass'], PASSWORD_DEFAULT)]);
            login_log($user, true);
            admin_login_session($u);
            redirect(base_url('admin/'));
        }
        login_log($user, false);
        usleep(random_int(400000, 900000));
        $err = 'Usuário ou senha incorretos.';
    }
}
$primary = primary_color();
?><!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>Entrar · <?= e(site_name()) ?></title>
<link rel="icon" href="<?= e(base_url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(base_url('admin/admin.css')) ?>?v=3"><style>:root{--primary:<?= e($primary) ?>}</style></head>
<body><div class="login-wrap"><form class="login" method="post" autocomplete="on">
  <div style="margin-bottom:6px"><?= logo_html('lg') ?></div><p class="muted" style="margin:0 0 22px">Painel administrativo</p>
  <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
  <?= csrf_field() ?>
  <div style="position:absolute;left:-9999px" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
  <div class="field"><label>Usuário</label><input type="text" name="user" value="<?= e($user) ?>" required autofocus autocomplete="username"></div>
  <div class="field"><label>Senha</label><input type="password" name="pass" required autocomplete="current-password"></div>
  <button class="btn btn-primary" style="width:100%;justify-content:center;padding:12px">Entrar</button>
  <p class="muted" style="font-size:12px;margin:16px 0 0;text-align:center">🔒 Acesso protegido · tentativas registradas</p>
</form></div></body></html>
