<?php
/**
 * ============================================================
 *  INSTALADOR — conecta o MySQL, cria as tabelas e o admin.
 *  Acesse: seudominio.com/install.php
 *  Depois de instalado ele se bloqueia sozinho
 *  (para reinstalar, apague data/installed.lock).
 * ============================================================
 */
define('IN_INSTALLER', true);
require __DIR__ . '/includes/bootstrap.php';

$dataDir = DATA_DIR;
$localCfg = $dataDir . '/config.local.php';
$step = $_GET['step'] ?? '1';
$errors = [];
$inst = &$_SESSION['installer'];
$inst = is_array($inst) ? $inst : [];
$hasJsonData = is_file($dataDir . '/videos.json');

/* ---------------- Funções do instalador ---------------- */
function inst_pdo(array $c, bool $withDb = true): PDO {
    $dsn = 'mysql:host=' . $c['host'] . ';port=' . (int)$c['port'] . ($withDb ? ';dbname=' . $c['name'] : '') . ';charset=utf8mb4';
    return new PDO($dsn, $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
}
function inst_run_schema(PDO $pdo, bool $seedCategories): int {
    $sql = file_get_contents(__DIR__ . '/install/schema.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $n = 0;
    foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        if (!$seedCategories && stripos($stmt, 'INSERT IGNORE INTO `categories`') === 0) continue;
        $pdo->exec($stmt);
        $n++;
    }
    return $n;
}
function inst_write_config(string $file, array $c): bool {
    $out = "<?php\n// Gerado pelo instalador em " . date('d/m/Y H:i') . ". Não compartilhe este arquivo.\n";
    foreach (['DB_DRIVER' => 'mysql', 'DB_HOST' => $c['host'], 'DB_PORT' => (int)$c['port'], 'DB_NAME' => $c['name'],
              'DB_USER' => $c['user'], 'DB_PASS' => $c['pass'], 'DB_CHARSET' => 'utf8mb4'] as $k => $v) {
        $out .= "define('$k', " . var_export($v, true) . ");\n";
    }
    return file_put_contents($file, $out, LOCK_EX) !== false;
}
function inst_mysql_error(Throwable $e): string {
    $m = $e->getMessage();
    if (str_contains($m, '1045')) return 'Usuário ou senha do banco incorretos.';
    if (str_contains($m, '1049')) return 'O banco informado não existe. Crie-o no painel da hospedagem (ou confira o nome).';
    if (str_contains($m, '1044')) return 'Esse usuário não tem permissão nesse banco. No painel da hospedagem, adicione o usuário ao banco com TODOS os privilégios.';
    if (str_contains($m, '2002') || str_contains($m, '2005')) return 'Não foi possível conectar ao servidor do banco. Confira o "Servidor" (normalmente localhost).';
    return 'Erro do MySQL: ' . $m;
}

/* ---------------- Já instalado ---------------- */
if (is_installed()) $step = 'done';

/* ---------------- Etapa 1: requisitos ---------------- */
$checks = [
    ['PHP 8.0 ou superior', version_compare(PHP_VERSION, '8.0', '>='), 'Versão atual: ' . PHP_VERSION . '. Mude a versão do PHP no painel da hospedagem.'],
    ['Extensão PDO MySQL', extension_loaded('pdo_mysql'), 'Ative "pdo_mysql" nas extensões do PHP da hospedagem.'],
    ['Extensão cURL (importador)', extension_loaded('curl'), 'Ative "curl" nas extensões do PHP.'],
    ['Extensão mbstring', extension_loaded('mbstring'), 'Ative "mbstring" nas extensões do PHP.'],
    ['Pasta /data com permissão de escrita', is_dir($dataDir) && is_writable($dataDir), 'Dê permissão 755 (ou 775) à pasta "data" pelo Gerenciador de Arquivos.'],
    ['Arquivo install/schema.sql', is_file(__DIR__ . '/install/schema.sql'), 'Envie a pasta "install" completa.'],
];
$checksOk = !in_array(false, array_column($checks, 1), true);

/* ---------------- Etapa 2: banco ---------------- */
if ($step === '2' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $c = ['host' => trim($_POST['host'] ?: 'localhost'), 'port' => (int)($_POST['port'] ?: 3306),
          'name' => trim($_POST['name']), 'user' => trim($_POST['user']), 'pass' => (string)$_POST['pass']];
    $inst['db_form'] = array_diff_key($c, ['pass' => 1]);
    if ($c['name'] === '' || $c['user'] === '') $errors[] = 'Preencha o nome do banco e o usuário.';
    else {
        try {
            try { $pdo = inst_pdo($c); }
            catch (PDOException $e) {
                // Banco não existe: tenta criar (funciona em VPS; em hospedagem compartilhada crie pelo painel)
                if (!str_contains($e->getMessage(), '1049')) throw $e;
                inst_pdo($c, false)->exec('CREATE DATABASE `' . str_replace('`', '', $c['name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $pdo = inst_pdo($c);
            }
            inst_run_schema($pdo, !$hasJsonData);
            if (!inst_write_config($localCfg, $c)) throw new RuntimeException('Não consegui gravar data/config.local.php. Confira a permissão da pasta data.');
            $inst['db_ok'] = true;
            redirect('install.php?step=3');
        } catch (PDOException $e) {
            $errors[] = inst_mysql_error($e);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

/* ---------------- Etapa 3: site e admin ---------------- */
if ($step === '3' && (empty($inst['db_ok']) || !is_file($localCfg))) redirect('install.php?step=2');
if ($step === '3' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $siteName = trim($_POST['site_name'] ?? '');
    $user = trim($_POST['admin_user'] ?? '');
    $pass = (string)($_POST['admin_pass'] ?? '');
    $inst['site_form'] = ['site_name' => $siteName, 'site_tagline' => trim($_POST['site_tagline'] ?? ''), 'admin_user' => $user, 'contact_email' => trim($_POST['contact_email'] ?? '')];
    if ($siteName === '') $errors[] = 'Informe o nome do site.';
    if (!preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $user)) $errors[] = 'Usuário do admin: 3 a 40 caracteres (letras, números, ponto, traço).';
    if (strlen($pass) < 8) $errors[] = 'A senha do admin precisa ter pelo menos 8 caracteres.';
    if ($pass !== ($_POST['admin_pass2'] ?? '')) $errors[] = 'As senhas não conferem.';
    if (!$errors) {
        try {
            $mysql = new MysqlDriver();
            $log = [];
            // Migra o que já existia no modo de teste (arquivos JSON)
            if ($hasJsonData && !empty($_POST['migrate'])) {
                $json = new JsonDriver($dataDir);
                foreach (['categories', 'videos', 'reports', 'settings'] as $t) {
                    $n = 0;
                    foreach ($json->query($t) as $row) { try { $mysql->insert($t, $row); $n++; } catch (Throwable $e) {} }
                    $log[] = "$t: $n";
                }
            }
            if ($mysql->count('categories') === 0) {
                foreach (['Amadores','Brasileiras','Casais','HD','Latinas','Loiras','Morenas','Ruivas','Lésbicas','Maduras','POV','Asiáticas'] as $i => $cn)
                    $mysql->insert('categories', ['name' => $cn, 'slug' => slugify($cn), 'description' => '', 'thumb' => '', 'sort' => $i + 1]);
            }
            // Admin
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            if ($a = $mysql->findBy('admins', 'username', $user)) $mysql->update('admins', $a['id'], ['password_hash' => $hash]);
            else $mysql->insert('admins', ['username' => $user, 'password_hash' => $hash, 'created_at' => now()]);
            // Configurações do site
            foreach (['site_name' => $siteName, 'site_tagline' => $inst['site_form']['site_tagline'], 'contact_email' => $inst['site_form']['contact_email']] as $k => $v) {
                $row = $mysql->findBy('settings', 'skey', $k);
                $row ? $mysql->update('settings', $row['id'], ['svalue' => $v]) : $mysql->insert('settings', ['skey' => $k, 'svalue' => $v]);
            }
            file_put_contents($dataDir . '/installed.lock', 'Instalado em ' . date('c'));
            // Já entra logado no painel
            session_regenerate_id(true);
            $a = $mysql->findBy('admins', 'username', $user);
            $_SESSION['admin_id'] = $a['id'];
            $_SESSION['admin_user'] = $user;
            $inst = ['done_log' => $log];
            redirect('install.php?step=done');
        } catch (Throwable $e) {
            $errors[] = $e instanceof PDOException ? inst_mysql_error($e) : $e->getMessage();
        }
    }
}

$db = $inst['db_form'] ?? ['host' => 'localhost', 'port' => 3306, 'name' => '', 'user' => ''];
$sf = $inst['site_form'] ?? ['site_name' => site_name(), 'site_tagline' => SITE_TAGLINE, 'admin_user' => 'admin', 'contact_email' => ''];
$stepNum = ['1' => 1, '2' => 2, '3' => 3, 'done' => 4][$step] ?? 1;
?><!DOCTYPE html>
<html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Instalação · <?= e(site_name()) ?></title>
<link rel="icon" href="<?= e(base_url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(base_url('assets/css/style.css')) ?>?v=2">
<style>
  body{background:radial-gradient(circle at 20% 0%,#2a1800 0,#000 55%);min-height:100vh}
  .inst{max-width:640px;margin:0 auto;padding:40px 18px 60px}
  .inst-head{text-align:center;margin-bottom:28px}
  .steps{display:flex;gap:8px;margin:26px 0 22px}
  .steps div{flex:1;text-align:center;font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px}
  .steps div::before{content:"";display:block;height:4px;border-radius:2px;background:#2a2a2a;margin-bottom:8px}
  .steps .on{color:var(--primary)} .steps .on::before{background:var(--primary)}
  .box{background:var(--bg2);border:1px solid var(--line);border-radius:12px;padding:28px}
  .box h2{margin:0 0 6px;font-size:21px} .box > p{color:var(--text2);margin:0 0 20px}
  .chk{display:flex;gap:12px;padding:11px 0;border-bottom:1px solid var(--line);align-items:flex-start}
  .chk b{font-size:15px;width:22px;flex:none}.chk .ok{color:#3ddc84}.chk .no{color:#ff5c5c}
  .chk small{display:block;color:#ff9b9b;margin-top:2px}
  .grid2{display:grid;grid-template-columns:2fr 1fr;gap:12px}
  .err{background:#3a0f0f;border:1px solid #6b1d1d;color:#ffb3b3;padding:12px 14px;border-radius:8px;margin-bottom:16px;font-weight:600}
  .tip{background:#1e1606;border:1px solid #4a3410;color:#ffd79a;padding:12px 14px;border-radius:8px;font-size:13.5px;margin-bottom:18px}
  .foot{display:flex;justify-content:space-between;align-items:center;margin-top:22px;gap:10px}
  .btn-lg{padding:13px 22px;font-size:15px}
  .field small{color:var(--muted);display:block;margin-top:4px;font-size:12.5px}
  .done-ic{width:64px;height:64px;border-radius:50%;background:var(--primary);color:#000;font-size:34px;font-weight:900;display:flex;align-items:center;justify-content:center;margin:0 auto 16px}
  @media(max-width:520px){.grid2{grid-template-columns:1fr}.box{padding:20px}}
</style>
</head><body>
<div class="inst">
  <div class="inst-head"><?= logo_html('lg') ?></div>
  <div class="steps">
    <?php foreach (['Requisitos', 'Banco de dados', 'Site e admin', 'Pronto'] as $i => $lbl): ?>
      <div class="<?= $i + 1 <= $stepNum ? 'on' : '' ?>"><?= $lbl ?></div>
    <?php endforeach; ?>
  </div>

  <div class="box">
  <?php foreach ($errors as $er): ?><div class="err"><?= e($er) ?></div><?php endforeach; ?>

  <?php if ($step === '1'): ?>
    <h2>Bem-vindo à instalação</h2>
    <p>Em 3 passos o site fica conectado ao banco MySQL e pronto para usar. Primeiro, conferindo o servidor:</p>
    <?php foreach ($checks as [$lbl, $ok, $help]): ?>
      <div class="chk"><b class="<?= $ok ? 'ok' : 'no' ?>"><?= $ok ? '✓' : '✗' ?></b><div><?= e($lbl) ?><?php if (!$ok): ?><small><?= e($help) ?></small><?php endif; ?></div></div>
    <?php endforeach; ?>
    <div class="foot">
      <span class="muted small">PHP <?= e(PHP_VERSION) ?></span>
      <?php if ($checksOk): ?><a class="btn btn-primary btn-lg" href="install.php?step=2">Continuar →</a>
      <?php else: ?><a class="btn btn-lg" href="install.php">↻ Verificar de novo</a><?php endif; ?>
    </div>

  <?php elseif ($step === '2'): ?>
    <h2>Banco de dados MySQL</h2>
    <p>Use os dados do banco que você criou no painel da hospedagem (cPanel → “Bancos de dados MySQL”, hPanel, etc.).</p>
    <div class="tip">Ainda não criou? No painel da hospedagem: crie um <b>banco</b>, crie um <b>usuário</b> com senha e <b>adicione o usuário ao banco com todos os privilégios</b>. Em hospedagens com cPanel o nome costuma vir com prefixo, ex.: <code>conta_safadeza</code>.</div>
    <form method="post" action="install.php?step=2" autocomplete="off">
      <?= csrf_field() ?>
      <div class="grid2">
        <div class="field"><label>Servidor (host)</label><input type="text" name="host" value="<?= e($db['host']) ?>" placeholder="localhost"><small>Quase sempre <b>localhost</b>.</small></div>
        <div class="field"><label>Porta</label><input type="text" name="port" value="<?= e($db['port']) ?>"></div>
      </div>
      <div class="field"><label>Nome do banco</label><input type="text" name="name" value="<?= e($db['name']) ?>" required></div>
      <div class="field"><label>Usuário do banco</label><input type="text" name="user" value="<?= e($db['user']) ?>" required></div>
      <div class="field"><label>Senha do banco</label><input type="password" name="pass"></div>
      <div class="foot">
        <a class="btn btn-ghost" href="install.php">← Voltar</a>
        <button class="btn btn-primary btn-lg">Conectar e criar tabelas →</button>
      </div>
    </form>

  <?php elseif ($step === '3'): ?>
    <h2>Seu site e o acesso ao painel</h2>
    <p>✓ Banco conectado e tabelas criadas. Agora defina o nome do site e o login do administrador.</p>
    <form method="post" action="install.php?step=3" autocomplete="off">
      <?= csrf_field() ?>
      <div class="field"><label>Nome do site</label><input type="text" name="site_name" value="<?= e($sf['site_name']) ?>" required><small>A 1ª palavra fica branca e o resto laranja na logo. Dá para mudar depois no painel.</small></div>
      <div class="field"><label>Slogan</label><input type="text" name="site_tagline" value="<?= e($sf['site_tagline']) ?>"></div>
      <div class="field"><label>E-mail de contato</label><input type="email" name="contact_email" value="<?= e($sf['contact_email']) ?>"><small>Aparece nas páginas de Contato e DMCA.</small></div>
      <hr style="border:0;border-top:1px solid var(--line);margin:18px 0">
      <div class="field"><label>Usuário do admin</label><input type="text" name="admin_user" value="<?= e($sf['admin_user']) ?>" required></div>
      <div class="grid2" style="grid-template-columns:1fr 1fr">
        <div class="field"><label>Senha do admin</label><input type="password" name="admin_pass" minlength="8" required></div>
        <div class="field"><label>Repetir senha</label><input type="password" name="admin_pass2" minlength="8" required></div>
      </div>
      <?php if ($hasJsonData): ?>
        <label style="display:flex;gap:8px;align-items:flex-start;margin:6px 0 4px;color:var(--text2)"><input type="checkbox" name="migrate" value="1" checked style="margin-top:3px">
          <span>Levar para o banco os vídeos, categorias e configurações que já existem no modo de teste</span></label>
      <?php endif; ?>
      <div class="foot">
        <a class="btn btn-ghost" href="install.php?step=2">← Voltar</a>
        <button class="btn btn-primary btn-lg">Concluir instalação ✓</button>
      </div>
    </form>

  <?php else: ?>
    <div style="text-align:center">
      <div class="done-ic">✓</div>
      <h2>Tudo pronto!</h2>
      <p style="margin:6px 0 22px">O site está conectado ao MySQL e pronto para usar.
        <?php if (!empty($inst['done_log'])): ?><br><small class="muted">Migrado: <?= e(implode(' · ', $inst['done_log'])) ?></small><?php endif; ?></p>
      <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
        <a class="btn btn-primary btn-lg" href="<?= e(base_url('admin/import.php')) ?>">Ir ao painel e importar vídeos</a>
        <a class="btn btn-lg" href="<?= e(base_url()) ?>">Ver o site</a>
      </div>
      <p class="muted small" style="margin-top:22px">O instalador agora está bloqueado. Para reinstalar, apague o arquivo <code>data/installed.lock</code>.</p>
    </div>
  <?php endif; ?>
  </div>
</div>
</body></html>
