<?php
/* Login web panel (Admin & Kepala Madrasah) */
require_once __DIR__ . '/../includes/bootstrap.php';

$u = user_fresh();
if ($u && is_panel_role($u['peran'])) redirect('admin/');

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $_SESSION['gagal'] = $_SESSION['gagal'] ?? ['n' => 0, 't' => time()];
    if ($_SESSION['gagal']['n'] >= 5 && time() - $_SESSION['gagal']['t'] < 60) {
        $err = 'Terlalu banyak percobaan. Tunggu 1 menit lalu coba lagi.';
    } else {
        $st = db()->prepare('SELECT * FROM pengguna WHERE nip = ?');
        $st->execute([trim($_POST['nip'] ?? '')]);
        $row = $st->fetch();
        if (!$row || !$row['aktif'] || !password_verify((string)($_POST['sandi'] ?? ''), $row['kata_sandi_hash'])) {
            $_SESSION['gagal']['n']++; $_SESSION['gagal']['t'] = time();
            $err = 'NIP atau kata sandi salah.';
        } elseif (!is_panel_role($row['peran'])) {
            $err = 'Web panel hanya untuk Admin Tata Usaha dan Kepala Madrasah. Gunakan aplikasi mobile untuk presensi.';
        } else {
            $_SESSION['gagal'] = ['n' => 0, 't' => time()];
            login_session($row, null);
            redirect('admin/');
        }
    }
}
?>
<!doctype html>
<html lang="id"><head>
<?php require_once __DIR__ . '/../includes/layout.php'; head_common('Masuk Web Panel'); ?>
</head>
<body class="panel-login">
<div class="pl-hero">
  <div class="brand"><span class="logo">MA</span><div><b><?= e(APP_NAME) ?></b><small><?= e(APP_SCHOOL) ?></small></div></div>
  <div class="pl-copy">
    <h2>Presensi pegawai yang jujur dan rapi.</h2>
    <p>Terverifikasi lokasi dan sidik jari. Rekap kehadiran bulanan tersusun otomatis untuk Kepala Madrasah dan Tata Usaha.</p>
    <ul><li><?= icon('pin', 18) ?> Lokasi dicek di server</li><li><?= icon('fingerprint', 18) ?> Satu akun, satu perangkat</li><li><?= icon('chart', 18) ?> Rekap & koreksi terdokumentasi</li></ul>
  </div>
</div>
<div class="pl-form">
<form method="post" class="login-card" autocomplete="off">
  <h1>Masuk Web Panel</h1>
  <p class="muted">Admin Tata Usaha &amp; Kepala Madrasah</p>
  <?php if ($err): ?><div class="alert alert-err"><?= e($err) ?></div><?php endif; ?>
  <?= csrf_field() ?>
  <label for="nip">NIP</label><input class="input" id="nip" name="nip" inputmode="numeric" required>
  <label for="sandi">Kata sandi</label><input class="input" id="sandi" name="sandi" type="password" required>
  <button class="btn btn-primary btn-xl btn-block" style="margin-top:20px"><?= icon('login', 20) ?> Masuk</button>
  <p class="center" style="margin-top:16px"><a class="small-link" href="<?= e(url('index.php')) ?>">← Presensi mobile</a></p>
</form>
</div>
</body></html>
