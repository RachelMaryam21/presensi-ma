<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$u = user_fresh();
if (!$u) redirect('admin/login.php');
redirect($u['peran'] === 'admin' ? 'admin/pengguna.php' : 'admin/rekapitulasi.php');
