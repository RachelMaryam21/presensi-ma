<?php
/* (a) Login mobile - KF-02: login dengan pengikatan satu akun ke satu perangkat */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

if (user_fresh() && perangkat_sesi()) redirect('m/presensi.php');

$err = ''; $info = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $nip = trim($_POST['nip'] ?? '');
    $pw  = (string)($_POST['sandi'] ?? '');
    $dev = strtolower(trim($_POST['device'] ?? ''));

    // Pembatasan percobaan login sederhana
    $_SESSION['gagal'] = $_SESSION['gagal'] ?? ['n' => 0, 't' => time()];
    if ($_SESSION['gagal']['n'] >= 5 && time() - $_SESSION['gagal']['t'] < 60) {
        $err = 'Terlalu banyak percobaan. Tunggu 1 menit lalu coba lagi.';
    } elseif (!preg_match('/^[a-f0-9\-]{32,64}$/', $dev)) {
        $err = 'Identitas perangkat tidak terbaca. Aktifkan JavaScript lalu muat ulang halaman.';
    } else {
        $st = db()->prepare('SELECT * FROM pengguna WHERE nip = ?');
        $st->execute([$nip]);
        $u = $st->fetch();

        if (!$u || !$u['aktif'] || !password_verify($pw, $u['kata_sandi_hash'])) {
            $_SESSION['gagal']['n']++; $_SESSION['gagal']['t'] = time();
            $err = 'NIP atau kata sandi salah.';
        } else {
            $_SESSION['gagal'] = ['n' => 0, 't' => time()];
            $pdo = db();
            $st = $pdo->prepare('SELECT * FROM perangkat WHERE id_pengguna = ? AND identitas_perangkat = ? ORDER BY id_perangkat DESC LIMIT 1');
            $st->execute([$u['id_pengguna'], $dev]);
            $p = $st->fetch();
            $st = $pdo->prepare("SELECT COUNT(*) FROM perangkat WHERE id_pengguna = ? AND status_persetujuan = 'aktif'");
            $st->execute([$u['id_pengguna']]);
            $punyaAktif = (int)$st->fetchColumn() > 0;
            $nama = nama_perangkat_dari_ua($_SERVER['HTTP_USER_AGENT'] ?? '');

            if ($p && $p['status_persetujuan'] === 'aktif') {
                login_session($u, $dev);                        // perangkat terikat -> masuk
                redirect('m/presensi.php');
            } elseif (!$punyaAktif) {
                // Belum ada perangkat terikat (login pertama / setelah direset Admin):
                // perangkat ini didaftarkan & diikat ke akun tanpa persetujuan.
                if ($p) {
                    $pdo->prepare("UPDATE perangkat SET status_persetujuan='aktif', credential_id=NULL, public_key=NULL, nama_perangkat=? WHERE id_perangkat=?")
                        ->execute([$nama, $p['id_perangkat']]);
                } else {
                    $pdo->prepare("INSERT INTO perangkat (id_pengguna, identitas_perangkat, nama_perangkat, status_persetujuan) VALUES (?,?,?, 'aktif')")
                        ->execute([$u['id_pengguna'], $dev, $nama]);
                }
                login_session($u, $dev);
                redirect('m/presensi.php');
            } elseif ($p && $p['status_persetujuan'] === 'menunggu') {
                $info = 'Permintaan pergantian perangkat masih menunggu persetujuan Admin.';
            } elseif ($p && $p['status_persetujuan'] === 'ditolak') {
                $err = 'Pergantian ke perangkat ini ditolak Admin. Hubungi Admin Tata Usaha.';
            } else {
                // Perangkat baru / pernah dinonaktifkan -> ajukan pergantian perangkat (ditolak sampai disetujui)
                $pdo->prepare("INSERT INTO perangkat (id_pengguna, identitas_perangkat, nama_perangkat, status_persetujuan) VALUES (?,?,?, 'menunggu')")
                    ->execute([$u['id_pengguna'], $dev, $nama]);
                $info = 'Akun ini terikat pada perangkat lain. Permintaan pergantian perangkat telah dikirim ke Admin; presensi dapat dilakukan setelah disetujui.';
            }
        }
    }
}

m_head('Masuk');
?>
<div class="login-hero">
  <div class="logo-ring"><span>MA</span></div>
  <h1>Presensi Pegawai</h1>
  <p><?= e(APP_SCHOOL) ?></p>
</div>
<div class="login-sheet">
  <h2>Selamat datang</h2>
  <p class="muted small" style="margin-bottom:6px">Masuk dengan NIP dan kata sandi Anda.</p>

  <?php if ($err): ?><div class="alert alert-err"><?= e($err) ?></div><?php endif; ?>
  <?php if ($info): ?><div class="alert alert-warn"><?= e($info) ?></div><?php endif; ?>

  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="device" id="device">
    <label for="nip">NIP</label>
    <input class="input" id="nip" name="nip" inputmode="numeric" placeholder="Masukkan NIP" required value="<?= e($_POST['nip'] ?? '') ?>">
    <label for="sandi">Kata sandi</label>
    <div class="pw">
      <input class="input" id="sandi" name="sandi" type="password" placeholder="Masukkan kata sandi" required>
      <button type="button" class="pw-toggle" onclick="var i=document.getElementById('sandi');i.type=i.type==='password'?'text':'password'">Lihat</button>
    </div>
    <button class="btn btn-primary btn-xl btn-block" style="margin-top:20px"><?= icon('login', 20) ?> Masuk</button>
  </form>

  <div class="note-box"><?= icon('device', 20) ?><div><b>Akun terikat pada satu perangkat.</b><br>Pergantian perangkat perlu persetujuan Admin.</div></div>
  <p class="center" style="margin-top:16px"><a class="small-link" href="<?= e(url('admin/login.php')) ?>">Masuk Web Panel →</a></p>
</div>
<script>
// Identitas perangkat: UUID acak disimpan di perangkat ini
(function () {
  var k = 'presensi_device', v = null;
  try { v = localStorage.getItem(k); } catch (e) {}
  if (!v) {
    var a = new Uint8Array(16); (window.crypto || window.msCrypto).getRandomValues(a);
    v = Array.prototype.map.call(a, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
    try { localStorage.setItem(k, v); } catch (e) {}
  }
  document.getElementById('device').value = v;
})();
</script>
<?php m_foot(); ?>
