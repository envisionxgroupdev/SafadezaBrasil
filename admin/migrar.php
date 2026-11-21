<?php
/**
 * Copia tudo que está nos arquivos JSON (/data) para o MySQL.
 * Use depois de importar install/schema.sql e preencher os dados do banco no config.php.
 * Acesse: /admin/migrar.php (logado)
 */
require __DIR__ . '/_layout.php';
require_admin();

$log = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $json = new JsonDriver(__DIR__ . '/../data');
        $mysql = new MysqlDriver();
        foreach (['categories', 'videos', 'reports', 'settings', 'admins'] as $t) {
            $n = 0; $skip = 0;
            foreach ($json->query($t) as $row) {
                try { $mysql->insert($t, $row); $n++; } catch (Throwable $e) { $skip++; }
            }
            $log[] = "$t: $n copiados" . ($skip ? ", $skip ignorados (já existiam)" : '');
        }
        $log[] = 'Pronto! Agora mude DB_DRIVER para \'mysql\' no config.php.';
    } catch (Throwable $e) {
        $log[] = 'Erro ao conectar no MySQL: ' . $e->getMessage();
    }
}
admin_header('Migrar para MySQL', 'settings');
?>
<div class="card">
  <h2>Migrar dados JSON → MySQL</h2>
  <ol>
    <li>Crie o banco na hospedagem e importe <code>install/schema.sql</code> pelo phpMyAdmin.</li>
    <li>Preencha <code>DB_HOST</code>, <code>DB_NAME</code>, <code>DB_USER</code> e <code>DB_PASS</code> no <code>config.php</code> (deixe <code>DB_DRIVER</code> em <code>'json'</code> por enquanto).</li>
    <li>Clique no botão abaixo.</li>
    <li>Mude <code>DB_DRIVER</code> para <code>'mysql'</code>.</li>
  </ol>
  <form method="post"><?= csrf_field() ?><button class="btn btn-primary">Copiar dados para o MySQL</button></form>
  <?php if ($log): ?><div class="log"><?= e(implode("\n", $log)) ?></div><?php endif; ?>
</div>
<?php admin_footer(); ?>
