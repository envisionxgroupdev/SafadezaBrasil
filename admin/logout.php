<?php
require __DIR__ . '/../includes/bootstrap.php';
unset($_SESSION['admin_id'], $_SESSION['admin_user']);
session_regenerate_id(true);
redirect(base_url('admin/login.php'));
