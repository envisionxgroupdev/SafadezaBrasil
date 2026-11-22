<?php
require __DIR__ . '/_layout.php';
require_admin();
$root = realpath(__DIR__ . '/..');

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

<div class="grid2">
  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="do" value="force_https">
    <h2>Forçar HTTPS</h2>
    <p class="muted" style="margin-top:-6px">Todo acesso por http:// é levado para https:// (cadeado no navegador). Ative só depois que o SSL estiver funcionando.</p>
    <label style="display:block;margin-bottom:12px"><input type="checkbox" name="on" value="1" <?= setting('force_https', '') === '1' ? 'checked' : '' ?>> Exigir HTTPS em todo o site</label>
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
