<?php
require __DIR__ . '/../includes/bootstrap.php';
// Só sai com o token da sessão (impede que outro site te deslogue por um link)
$t = (string)($_GET['t'] ?? '');
if (csrf_valid($t)) admin_logout_session();
redirect(base_url(is_admin() ? 'admin/index.php' : 'admin/login.php'));
