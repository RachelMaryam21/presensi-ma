<?php
/* Kerangka halaman mobile & web panel */

function head_common(string $title, string $theme = '#0a3b2e'): void { ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= e($theme) ?>">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=Figtree:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=3">
<?php }

function m_head(string $title): void { ?>
<!doctype html>
<html lang="id">
<head>
<?php head_common($title); ?>
</head>
<body class="mobile">
<div class="phone">
<?php }

function m_nav(string $aktif): void { ?>
  <nav class="m-nav">
    <a href="<?= e(url('m/presensi.php')) ?>" class="<?= $aktif === 'presensi' ? 'on' : '' ?>"><span class="pill"><?= icon('fingerprint', 22) ?></span>Presensi</a>
    <a href="<?= e(url('m/riwayat.php')) ?>"  class="<?= $aktif === 'riwayat'  ? 'on' : '' ?>"><span class="pill"><?= icon('history', 22) ?></span>Riwayat</a>
  </nav>
<?php }

function m_foot(string $script = ''): void { ?>
</div>
<?php if ($script): ?><script src="<?= e(url($script)) ?>"></script><?php endif; ?>
</body>
</html>
<?php }

/* ---------- Web panel ---------- */
function p_head(string $title, string $aktif, array $u): void {
    $menu = [
        'pengguna' => ['Pengguna & Perangkat', 'admin/pengguna.php',      ['admin'],           'users'],
        'lokasi'   => ['Lokasi Presensi',      'admin/lokasi.php',        ['admin'],           'pin'],
        'koreksi'  => ['Koreksi Presensi',     'admin/koreksi.php',       ['admin'],           'edit'],
        'rekap'    => ['Rekapitulasi',         'admin/rekapitulasi.php',  ['admin', 'kepala'], 'chart'],
    ]; ?>
<!doctype html>
<html lang="id">
<head>
<?php head_common($title); ?>
</head>
<body class="panel">
<aside class="side">
  <div class="brand"><span class="logo">MA</span><div><b><?= e(APP_NAME) ?></b><small><?= e(APP_SCHOOL) ?></small></div></div>
  <nav>
    <?php foreach ($menu as $k => [$label, $path, $roles, $ic]): if (!in_array($u['peran'], $roles, true)) continue; ?>
      <a href="<?= e(url($path)) ?>" class="<?= $aktif === $k ? 'on' : '' ?>"><?= icon($ic, 20) ?><span><?= e($label) ?></span></a>
    <?php endforeach; ?>
  </nav>
  <?php if (!empty($_SESSION['device_id'])): ?>
  <div class="side-bottom">
    <a href="<?= e(url('m/presensi.php')) ?>"><?= icon('fingerprint', 18) ?> Presensi pribadi</a>
  </div>
  <?php endif; ?>
</aside>
<main class="main">
  <header class="topbar">
    <h1><?= e($title) ?></h1>
    <div class="who">
      <span class="av av-0"><?= e(mb_strtoupper(mb_substr($u['nama'], 0, 1))) ?></span>
      <div class="who-t"><b><?= e($u['nama']) ?></b><small><?= e(NAMA_PERAN[$u['peran']]) ?></small></div>
      <form method="post" action="<?= e(url('admin/logout.php')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-line" title="Keluar"><?= icon('logout', 16) ?> Keluar</button></form>
    </div>
  </header>
  <section class="content">
    <?= flash_show() ?>
<?php }

function p_foot(string $script = ''): void { ?>
  </section>
</main>
<?php if ($script): ?><script src="<?= e(url($script)) ?>"></script><?php endif; ?>
</body>
</html>
<?php }
