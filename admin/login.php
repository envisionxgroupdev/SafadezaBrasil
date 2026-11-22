<?php
require __DIR__ . '/../includes/bootstrap.php';
db_upgrade();
private_page_headers();
if (is_admin()) redirect(base_url('admin/index.php'));

$err = '';
$user = '';
$pending = $_SESSION['pending_2fa'] ?? null;
// a etapa do código vale por 5 minutos e só no mesmo navegador
if ($pending && (time() - $pending['t'] > 300 || !hash_equals($pending['ua'], ua_hash()))) { unset($_SESSION['pending_2fa']); $pending = null; }
if (isset($_GET['cancel'])) { unset($_SESSION['pending_2fa']); redirect(base_url('admin/login.php')); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ($pending && isset($_POST['code'])) {
        /* ---------- Etapa 2: código do aplicativo ---------- */
        $u = db()->find('admins', $pending['id']);
        $code = trim((string)$_POST['code']);
        if (!rl_hit('2fa:' . client_ip(), 8, 900) || ++$_SESSION['pending_2fa']['tries'] > 5) {
            unset($_SESSION['pending_2fa']);
            login_log($pending['user'], false);
            security_event('login', 'Código de 2 etapas errado várias vezes para "' . $pending['user'] . '"');
            $err = 'Código errado muitas vezes. Entre de novo com usuário e senha.';
            $pending = null;
        } elseif ($u && ($step = totp_verify((string)$u['totp_secret'], $code, (int)$u['totp_last']))) {
            db()->update('admins', $u['id'], ['totp_last' => $step]);
            unset($_SESSION['pending_2fa']);
            login_log($u['username'], true);
            admin_login_session($u);
            redirect(base_url('admin/index.php'));
        } elseif ($u && strlen(preg_replace('/[^A-Za-z0-9]/', '', $code)) === 12 && ($left = recovery_code_use($u['totp_recovery'], $code)) !== null) {
            db()->update('admins', $u['id'], ['totp_recovery' => $left]);
            unset($_SESSION['pending_2fa']);
            login_log($u['username'], true);
            security_event('login', 'Entrou com código de recuperação ("' . $u['username'] . '")');
            admin_login_session($u);
            flash('Você entrou com um código de recuperação. Restam ' . count(json_decode($left, true)) . '. Se perdeu o celular, desative e ative de novo a verificação em duas etapas em Segurança.', 'err');
            redirect(base_url('admin/security.php'));
        } else {
            usleep(random_int(300000, 700000));
            $err = 'Código incorreto. Confira o aplicativo e tente de novo.';
        }
    } else {
        /* ---------- Etapa 1: usuário e senha ---------- */
        $user = trim((string)($_POST['user'] ?? ''));
        if (!empty($_POST['website'])) {                       // campo invisível: só robô preenche
            $err = 'Usuário ou senha incorretos.';
        } elseif ($wait = login_blocked($user)) {
            $err = $wait >= 60 ? 'Seu IP foi bloqueado por excesso de tentativas. Tente amanhã.' : "Muitas tentativas erradas. Por segurança, aguarde $wait minutos.";
        } else {
            $u = $user !== '' ? db()->findBy('admins', 'username', $user) : null;
            // sempre roda o password_verify (mesmo tempo de resposta com ou sem usuário válido)
            $ok = password_verify((string)($_POST['pass'] ?? ''), $u['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            if ($u && $ok) {
                if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) db()->update('admins', $u['id'], ['password_hash' => password_hash($_POST['pass'], PASSWORD_DEFAULT)]);
                if (!empty($u['totp_secret'])) {
                    session_regenerate_id(true);
                    $_SESSION['pending_2fa'] = ['id' => (int)$u['id'], 'user' => $u['username'], 't' => time(), 'ua' => ua_hash(), 'tries' => 0];
                    redirect(base_url('admin/login.php'));
                }
                login_log($user, true);
                admin_login_session($u);
                redirect(base_url('admin/index.php'));
            }
            login_log($user, false);
            usleep(random_int(400000, 900000));
            $err = 'Usuário ou senha incorretos.';
        }
    }
}
$primary = primary_color();
?><!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>Entrar · <?= e(site_name()) ?></title>
<link rel="icon" href="<?= e(base_url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset_url('admin/admin.css')) ?>"><style>:root{--primary:<?= e($primary) ?>}</style></head>
<body><div class="login-wrap"><form class="login" method="post" autocomplete="<?= $pending ? 'off' : 'on' ?>">
  <div style="margin-bottom:6px"><?= logo_html('lg') ?></div>
  <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
  <?= csrf_field() ?>
  <?php if ($pending): ?>
    <p class="muted" style="margin:0 0 18px">🔐 <b>Verificação em duas etapas</b><br>Abra o aplicativo autenticador no celular e digite o código de 6 dígitos.</p>
    <div class="field"><label>Código</label>
      <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="14" required autofocus placeholder="000 000"
             style="font-size:24px;letter-spacing:6px;text-align:center;font-weight:700"></div>
    <button class="btn btn-primary" style="width:100%;justify-content:center;padding:12px">Confirmar</button>
    <p class="muted" style="font-size:12.5px;margin:14px 0 0;text-align:center">Perdeu o celular? Digite um dos <b>códigos de recuperação</b> no lugar.<br>
      <a href="?cancel=1">Voltar</a></p>
  <?php else: ?>
    <p class="muted" style="margin:0 0 22px">Painel administrativo</p>
    <div style="position:absolute;left:-9999px" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
    <div class="field"><label>Usuário</label><input type="text" name="user" value="<?= e($user) ?>" required autofocus autocomplete="username" maxlength="60"></div>
    <div class="field"><label>Senha</label><input type="password" name="pass" required autocomplete="current-password" maxlength="200"></div>
    <button class="btn btn-primary" style="width:100%;justify-content:center;padding:12px">Entrar</button>
    <p class="muted" style="font-size:12px;margin:16px 0 0;text-align:center">🔒 Acesso protegido · tentativas registradas</p>
  <?php endif; ?>
</form></div></body></html>
