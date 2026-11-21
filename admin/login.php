<?php
require __DIR__ . '/../includes/bootstrap.php';
if (is_admin()) redirect(base_url('admin/'));
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Proteção simples contra força bruta
    $_SESSION['login_fail'] = array_filter($_SESSION['login_fail'] ?? [], fn($t) => $t > time() - 900);
    if (count($_SESSION['login_fail']) >= 5) $err = 'Muitas tentativas. Aguarde 15 minutos.';
    else {
        $u = db()->findBy('admins', 'username', trim($_POST['user'] ?? ''));
        if ($u && password_verify($_POST['pass'] ?? '', $u['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $u['id'];
            $_SESSION['admin_user'] = $u['username'];
            unset($_SESSION['login_fail']);
            redirect(base_url('admin/'));
        }
        $_SESSION['login_fail'][] = time();
        $err = 'Usuário ou senha incorretos.';
    }
}
$primary = setting('color_primary', '') ?: '#6a2fa0';
?><!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>Entrar · <?= e(site_name()) ?></title>
<link rel="stylesheet" href="<?= e(base_url('admin/admin.css')) ?>"><style>:root{--primary:<?= e($primary) ?>}</style></head>
<body><div class="login-wrap"><form class="login" method="post">
  <h1><?= e(site_name()) ?></h1><p class="muted" style="margin:0 0 22px">Painel administrativo</p>
  <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
  <?= csrf_field() ?>
  <div class="field"><label>Usuário</label><input type="text" name="user" required autofocus></div>
  <div class="field"><label>Senha</label><input type="password" name="pass" required></div>
  <button class="btn btn-primary" style="width:100%;justify-content:center;padding:12px">Entrar</button>
</form></div></body></html>
