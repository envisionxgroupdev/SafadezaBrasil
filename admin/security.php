<?php
require __DIR__ . '/_layout.php';
require_admin();
$root = realpath(__DIR__ . '/..');
$me = db()->find('admins', $_SESSION['admin_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    switch ($_POST['do'] ?? '') {
        case 'force_https':
            $on = !empty($_POST['on']);
            if ($on && !is_https()) { flash('Abra o painel com https:// antes de ativar (senão o site pode ficar fora do ar).', 'err'); break; }
            set_setting('force_https', $on ? '1' : '');
            flash($on ? 'HTTPS obrigatório ativado.' : 'HTTPS obrigatório desativado.');
            break;
        case 'delete_installer':
            flash(@unlink($root . '/install.php') ? 'install.php apagado.' : 'Não consegui apagar. Apague pelo Gerenciador de Arquivos da hospedagem.', is_file($root . '/install.php') ? 'err' : 'ok');
            break;
        case 'delete_key':
            @unlink(install_key_file());
            flash('Chave de instalação apagada.');
            break;
        case '2fa_start':
            $_SESSION['totp_pending'] = totp_new_secret();
            redirect('security.php#2fa');
        case '2fa_confirm':
            $sec = (string)($_SESSION['totp_pending'] ?? '');
            if ($sec !== '' && ($step = totp_verify($sec, (string)($_POST['code'] ?? '')))) {
                [$plain, $hashed] = recovery_codes_new();
                db()->update('admins', $me['id'], ['totp_secret' => $sec, 'totp_recovery' => $hashed, 'totp_last' => $step]);
                unset($_SESSION['totp_pending']);
                $_SESSION['show_recovery'] = $plain;
                security_event('2fa', 'Verificação em duas etapas ATIVADA');
                flash('Verificação em duas etapas ativada! Guarde os códigos de recuperação.');
            } else flash('Código incorreto. Confira se o horário do celular está automático e tente de novo.', 'err');
            redirect('security.php#2fa');
        case '2fa_cancel':
            unset($_SESSION['totp_pending']);
            redirect('security.php#2fa');
        case '2fa_disable':
            if (!password_verify((string)($_POST['pass'] ?? ''), $me['password_hash'])) { flash('Senha incorreta.', 'err'); break; }
            db()->update('admins', $me['id'], ['totp_secret' => null, 'totp_recovery' => null, 'totp_last' => 0]);
            security_event('2fa', 'Verificação em duas etapas DESATIVADA');
            flash('Verificação em duas etapas desativada.', 'err');
            break;
        case '2fa_codes':
            if (!password_verify((string)($_POST['pass'] ?? ''), $me['password_hash'])) { flash('Senha incorreta.', 'err'); break; }
            [$plain, $hashed] = recovery_codes_new();
            db()->update('admins', $me['id'], ['totp_recovery' => $hashed]);
            $_SESSION['show_recovery'] = $plain;
            flash('Novos códigos de recuperação gerados. Os antigos não valem mais.');
            redirect('security.php#2fa');
        case 'blocklist':
            $list = array_filter(array_map('trim', preg_split('/[\s,]+/', (string)($_POST['blocked_ips'] ?? ''))), function ($x) {
                if ($x === '') return false;
                [$ip] = explode('/', $x . '/');
                return (bool)filter_var($ip, FILTER_VALIDATE_IP) && $x !== client_ip() && !ip_in_cidr(client_ip(), $x);
            });
            set_setting('blocked_ips', implode("\n", array_unique($list)));
            flash('Lista de IPs bloqueados salva (' . count($list) . ').' . (str_contains((string)($_POST['blocked_ips'] ?? ''), client_ip()) ? ' Seu próprio IP foi ignorado.' : ''));
            break;
        case 'player_sandbox':
            set_setting('player_sandbox', !empty($_POST['on']) ? '1' : '');
            flash('Configuração dos players salva.');
            break;
        case 'unban':
            ip_unban((string)($_POST['ip'] ?? ''));
            flash('IP desbloqueado.');
            break;
        case 'revoke':
            admin_revoke_all_sessions();
            admin_login_session(db()->find('admins', $_SESSION['admin_id']));
            flash('Todos os outros dispositivos foram desconectados.');
            break;
    }
    redirect('security.php');
}

$checks = security_checks();
$score = count(array_filter($checks, fn($c) => $c[1] === 'ok'));
$logins = db()->query('logins', ['order' => 'id DESC', 'limit' => 30]);
$has2fa = !empty($me['totp_secret']);
$pendingSecret = $_SESSION['totp_pending'] ?? null;
$showCodes = $_SESSION['show_recovery'] ?? null; unset($_SESSION['show_recovery']);
$bans = ip_bans_active();
$events = security_events(40);
$icon = ['ok' => '✓', 'warn' => '!', 'bad' => '✗'];
admin_header('Segurança', 'security');
?>
<div class="card sec-score">
  <div class="sec-ring" style="--p:<?= round($score / max(1, count($checks)) * 100) ?>"><b><?= $score ?>/<?= count($checks) ?></b></div>
  <div>
    <h2 style="margin:0 0 4px">Verificações de segurança</h2>
    <p class="muted" style="margin:0">Itens em vermelho precisam de atenção. Os amarelos são recomendações.</p>
  </div>
</div>

<div class="card">
  <?php foreach ($checks as $c): [$title, $st, $detail] = $c; $act = $c[3] ?? null; ?>
    <div class="sec-row">
      <span class="sec-ic <?= $st ?>"><?= $icon[$st] ?></span>
      <div class="sec-txt"><b><?= e($title) ?></b><small><?= e($detail) ?></small></div>
      <?php if ($act): ?>
        <form method="post"><?= csrf_field() ?><button class="btn btn-sm" name="do" value="<?= e($act) ?>" data-confirm="Confirmar?"><?= $act === 'delete_installer' ? 'Apagar install.php' : 'Apagar' ?></button></form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="card" id="2fa">
  <h2>🔐 Verificação em duas etapas <?= $has2fa ? '<span class="tag published">Ativada</span>' : '<span class="tag open">Desativada</span>' ?></h2>
  <?php if ($showCodes): ?>
    <div class="alert ok" style="font-weight:500">
      <b>Seus códigos de recuperação</b> — anote ou tire print e guarde fora do celular. Cada um funciona uma vez, se você perder o celular.
      <div class="codes"><?php foreach ($showCodes as $c): ?><code><?= e($c) ?></code><?php endforeach; ?></div>
      <button type="button" class="btn btn-sm" onclick="navigator.clipboard.writeText(<?= e(json_encode(implode("\n", $showCodes))) ?>).then(()=>this.textContent='Copiado ✓')">Copiar códigos</button>
    </div>
  <?php endif; ?>
  <?php if ($has2fa): ?>
    <p class="muted" style="margin-top:-4px">Além da senha, o painel pede o código do aplicativo no celular. Mesmo que alguém descubra sua senha, não consegue entrar.</p>
    <p class="muted">Códigos de recuperação restantes: <b><?= count(json_decode((string)$me['totp_recovery'], true) ?: []) ?></b></p>
    <form method="post" class="input-row" style="max-width:640px">
      <?= csrf_field() ?>
      <input type="password" name="pass" placeholder="Sua senha para confirmar" required>
      <button class="btn" name="do" value="2fa_codes">Gerar novos códigos</button>
      <button class="btn btn-danger" name="do" value="2fa_disable" data-confirm="Desativar a verificação em duas etapas?">Desativar</button>
    </form>
  <?php elseif ($pendingSecret): ?>
    <ol class="steps2fa">
      <li>Instale um aplicativo autenticador no celular: <b>Google Authenticator</b>, <b>Microsoft Authenticator</b> ou <b>2FAS</b>.</li>
      <li>No aplicativo, toque em <b>+</b> e escaneie o QR code abaixo.<br>
        <div id="qr" class="qrbox" data-uri="<?= e(totp_uri($pendingSecret, $me['username'])) ?>"></div>
        <small class="muted">Não consegue escanear? Digite a chave: <code class="secret"><?= e(trim(chunk_split($pendingSecret, 4, ' '))) ?></code></small></li>
      <li>Digite o código de 6 dígitos que apareceu no aplicativo:</li>
    </ol>
    <form method="post" class="input-row" style="max-width:420px">
      <?= csrf_field() ?>
      <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="000000" required style="font-size:20px;letter-spacing:4px;text-align:center">
      <button class="btn btn-primary" name="do" value="2fa_confirm">Ativar</button>
      <button class="btn" name="do" value="2fa_cancel" formnovalidate>Cancelar</button>
    </form>
    <script src="<?= e(base_url('admin/qrcode.js')) ?>?v=1"></script>
    <script>(function(){var el=document.getElementById('qr');try{renderQR(el,el.dataset.uri)}catch(e){el.textContent='Use a chave abaixo.'}})();</script>
  <?php else: ?>
    <p class="muted" style="margin-top:-4px"><b>Recomendado.</b> Além da senha, o painel passa a pedir um código que muda a cada 30 segundos no seu celular.
      Mesmo que alguém descubra sua senha, não consegue entrar.</p>
    <form method="post"><?= csrf_field() ?><button class="btn btn-primary" name="do" value="2fa_start">Ativar verificação em duas etapas</button></form>
  <?php endif; ?>
</div>

<div class="grid2">
  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="do" value="blocklist">
    <h2>🚫 Bloquear IPs</h2>
    <p class="muted" style="margin-top:-6px">Um IP (ou faixa, ex.: <code>203.0.113.0/24</code>) por linha. Quem estiver na lista não abre nenhuma página do site.</p>
    <div class="field"><textarea name="blocked_ips" rows="5" style="font-family:ui-monospace,Consolas,monospace" placeholder="203.0.113.10"><?= e((string)setting('blocked_ips', '')) ?></textarea>
      <small>Seu IP atual: <code><?= e(client_ip()) ?></code> (nunca é bloqueado por aqui).</small></div>
    <button class="btn btn-primary">Salvar</button>
  </form>
  <div class="card">
    <h2>🛡️ Bloqueios automáticos</h2>
    <p class="muted" style="margin-top:-6px">O firewall bloqueia sozinho IPs que tentam ataques (SQL injection, XSS, invasão de pastas), que erram a senha 20 vezes em 24h ou que fazem acessos demais por minuto.</p>
    <?php if (!$bans): ?><p class="muted">Nenhum IP bloqueado agora.</p><?php endif; ?>
    <?php foreach ($bans as $b): ?>
      <div class="sec-row">
        <div class="sec-txt"><b><code><?= e($b['ip']) ?></code></b><small><?= e($b['reason'] ?? '') ?> · até <?= date('d/m H:i', (int)$b['until']) ?></small></div>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="ip" value="<?= e($b['ip']) ?>"><button class="btn btn-sm" name="do" value="unban">Desbloquear</button></form>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="grid2">
  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="do" value="force_https">
    <h2>Forçar HTTPS</h2>
    <p class="muted" style="margin-top:-6px">Todo acesso por http:// é levado para https:// (cadeado no navegador). Ative só depois que o SSL estiver funcionando.</p>
    <label style="display:block;margin-bottom:12px"><input type="checkbox" name="on" value="1" <?= setting('force_https', '') === '1' ? 'checked' : '' ?>> Exigir HTTPS em todo o site</label>
    <button class="btn btn-primary">Salvar</button>
  </form>
  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="do" value="player_sandbox">
    <h2>Proteger visitantes dos players</h2>
    <p class="muted" style="margin-top:-6px">Impede que o player de outro site abra pop-ups ou leve o visitante para páginas de golpe. Alguns sites podem recusar tocar o vídeo com essa proteção: se algum player parar de funcionar, desligue.</p>
    <label style="display:block;margin-bottom:12px"><input type="checkbox" name="on" value="1" <?= setting('player_sandbox', '') === '1' ? 'checked' : '' ?>> Bloquear pop-ups e redirecionamentos dos players</label>
    <button class="btn btn-primary">Salvar</button>
  </form>
  <form method="post" class="card">
    <?= csrf_field() ?>
    <h2>Sessões</h2>
    <p class="muted" style="margin-top:-6px">Perdeu o celular ou entrou num computador de outra pessoa? Desconecte todos os outros acessos ao painel.
       A sessão também expira sozinha depois de 4 horas sem uso.</p>
    <button class="btn btn-danger" name="do" value="revoke" data-confirm="Desconectar todos os outros dispositivos?">Desconectar todos os dispositivos</button>
  </form>
</div>

<div class="card">
  <h2>Eventos de segurança</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Data</th><th>Tipo</th><th>IP</th><th>O que aconteceu</th></tr></thead>
    <tbody>
    <?php $types = ['attack' => ['Ataque bloqueado', 'open'], 'ban' => ['IP bloqueado', 'open'], 'login' => ['Login', 'review'], '2fa' => ['2 etapas', 'published']];
      foreach ($events as $ev): [$tl, $tc] = $types[$ev['type']] ?? [$ev['type'], 'draft']; ?>
      <tr><td style="white-space:nowrap"><?= date('d/m H:i', (int)$ev['t']) ?></td><td><span class="tag <?= $tc ?>"><?= e($tl) ?></span></td>
          <td><code><?= e($ev['ip']) ?></code></td><td style="max-width:460px;overflow-wrap:anywhere"><small><?= e($ev['msg']) ?></small></td></tr>
    <?php endforeach; if (!$events): ?><tr><td colspan="4" class="muted">Nenhum evento. Tudo tranquilo.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<div class="card">
  <h2>Últimos acessos ao painel</h2>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Data</th><th>Usuário</th><th>IP</th><th class="hide-m">Navegador</th><th>Resultado</th></tr></thead>
    <tbody>
    <?php foreach ($logins as $l): ?>
      <tr>
        <td style="white-space:nowrap"><?= date('d/m/Y H:i', strtotime($l['created_at'])) ?></td>
        <td><?= e($l['username'] ?: '—') ?></td>
        <td><code><?= e($l['ip']) ?></code><?= $l['ip'] === client_ip() ? ' <small class="muted">(você)</small>' : '' ?></td>
        <td class="hide-m" style="max-width:320px"><small class="muted"><?= e(mb_strimwidth((string)$l['user_agent'], 0, 70, '…')) ?></small></td>
        <td><span class="tag <?= $l['success'] ? 'published' : 'open' ?>"><?= $l['success'] ? 'Entrou' : 'Senha errada' ?></span></td>
      </tr>
    <?php endforeach; if (!$logins): ?><tr><td colspan="5" class="muted">Nenhum acesso registrado ainda.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php admin_footer(); ?>
