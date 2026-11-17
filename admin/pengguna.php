<?php
/* (a) Pengguna & Perangkat - KF-01, KF-10, KF-13 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

$admin = require_role(['admin']);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'setujui' || $aksi === 'tolak') {            // KF-10
        $st = $pdo->prepare("SELECT * FROM perangkat WHERE id_perangkat = ? AND status_persetujuan = 'menunggu'");
        $st->execute([(int)($_POST['id_perangkat'] ?? 0)]);
        if ($p = $st->fetch()) {
            $pdo->beginTransaction();
            if ($aksi === 'setujui') {
                $pdo->prepare("UPDATE perangkat SET status_persetujuan='nonaktif' WHERE id_pengguna=? AND status_persetujuan='aktif'")->execute([$p['id_pengguna']]);
                $pdo->prepare("UPDATE perangkat SET status_persetujuan='aktif', credential_id=NULL, public_key=NULL WHERE id_perangkat=?")->execute([$p['id_perangkat']]);
                flash('ok', 'Pergantian perangkat disetujui. Pengguna perlu mendaftarkan ulang sidik jari pada perangkat baru.');
            } else {
                $pdo->prepare("UPDATE perangkat SET status_persetujuan='ditolak' WHERE id_perangkat=?")->execute([$p['id_perangkat']]);
                flash('ok', 'Pergantian perangkat ditolak.');
            }
            $pdo->commit();
        }
        redirect('admin/pengguna.php');
    }

    if ($aksi === 'simpan') {                                   // KF-01 (tambah) / ubah
        $id    = (int)($_POST['id'] ?? 0);
        $nip   = trim($_POST['nip'] ?? '');
        $nama  = trim($_POST['nama'] ?? '');
        $peran = $_POST['peran'] ?? '';
        $pw    = (string)($_POST['sandi'] ?? '');
        $aktif = $id ? (isset($_POST['aktif']) ? 1 : 0) : 1;
        $err   = null;

        if ($nama === '' || mb_strlen($nama) > 100)             $err = 'Nama lengkap wajib diisi (maks. 100 karakter).';
        elseif (!isset(NAMA_PERAN[$peran]))                     $err = 'Pilih peran pengguna.';
        elseif (!$id && !preg_match('/^\d{5,30}$/', $nip))      $err = 'NIP harus berupa angka (5–30 digit).';
        elseif (!$id && strlen($pw) < MIN_PASSWORD)             $err = 'Kata sandi awal minimal ' . MIN_PASSWORD . ' karakter.';
        elseif ($id && $pw !== '' && strlen($pw) < MIN_PASSWORD) $err = 'Kata sandi baru minimal ' . MIN_PASSWORD . ' karakter.';

        if (!$err && $id) {
            $cur = $pdo->prepare('SELECT * FROM pengguna WHERE id_pengguna = ?'); $cur->execute([$id]); $cur = $cur->fetch();
            if (!$cur) $err = 'Pengguna tidak ditemukan.';
            elseif ($id === $admin['id'] && ($peran !== 'admin' || !$aktif)) $err = 'Anda tidak dapat mengubah peran atau menonaktifkan akun Anda sendiri.';
            elseif ($cur['peran'] === 'admin' && ($peran !== 'admin' || !$aktif)) {
                $n = $pdo->prepare("SELECT COUNT(*) FROM pengguna WHERE peran='admin' AND aktif=1 AND id_pengguna<>?"); $n->execute([$id]);
                if ((int)$n->fetchColumn() < 1) $err = 'Harus ada minimal satu Admin aktif.';
            }
        }
        if (!$err && !$id) {
            $d = $pdo->prepare('SELECT 1 FROM pengguna WHERE nip = ?'); $d->execute([$nip]);
            if ($d->fetch()) $err = 'NIP sudah terdaftar.';
        }

        if ($err) {
            flash('err', $err);
            redirect('admin/pengguna.php' . ($id ? '?edit=' . $id : ''));
        }
        if ($id) {
            $pdo->prepare('UPDATE pengguna SET nama=?, peran=?, aktif=? WHERE id_pengguna=?')->execute([$nama, $peran, $aktif, $id]);
            if ($pw !== '') $pdo->prepare('UPDATE pengguna SET kata_sandi_hash=? WHERE id_pengguna=?')->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
            if (isset($_POST['reset_perangkat'])) {
                $pdo->prepare("UPDATE perangkat SET status_persetujuan='nonaktif' WHERE id_pengguna=? AND status_persetujuan IN ('aktif','menunggu')")->execute([$id]);
            }
            flash('ok', 'Data pengguna diperbarui.');
        } else {
            $pdo->prepare('INSERT INTO pengguna (nip,nama,kata_sandi_hash,peran) VALUES (?,?,?,?)')
                ->execute([$nip, $nama, password_hash($pw, PASSWORD_DEFAULT), $peran]);
            flash('ok', 'Pengguna ditambahkan. Perangkat akan terikat otomatis saat login pertama di aplikasi mobile.');
        }
        redirect('admin/pengguna.php');
    }
    redirect('admin/pengguna.php');
}

/* ---- Data tampilan ---- */
$q = trim($_GET['q'] ?? ''); $fPeran = $_GET['peran'] ?? '';
$sql = 'SELECT * FROM pengguna WHERE 1'; $par = [];
if ($q !== '') { $sql .= ' AND (nip LIKE ? OR nama LIKE ?)'; $par[] = "%$q%"; $par[] = "%$q%"; }
if (isset(NAMA_PERAN[$fPeran])) { $sql .= ' AND peran = ?'; $par[] = $fPeran; }
$st = $pdo->prepare($sql . ' ORDER BY nama'); $st->execute($par);
$users = $st->fetchAll();

$devs = [];
foreach ($pdo->query('SELECT * FROM perangkat ORDER BY id_perangkat') as $d) $devs[$d['id_pengguna']][] = $d;
function perangkat_tampil(array $list): ?array {
    $best = null; $rank = ['menunggu' => 3, 'aktif' => 2, 'ditolak' => 1, 'nonaktif' => 0];
    foreach ($list as $d) if (!$best || $rank[$d['status_persetujuan']] >= $rank[$best['status_persetujuan']]) $best = $d;
    return $best;
}

$edit = null;
if (isset($_GET['edit'])) { $s = $pdo->prepare('SELECT * FROM pengguna WHERE id_pengguna = ?'); $s->execute([(int)$_GET['edit']]); $edit = $s->fetch() ?: null; }

p_head('Pengguna & Perangkat', 'pengguna', $admin);
?>
<form method="get" class="filters">
  <div class="grow"><label>Cari pengguna</label><input class="input" name="q" value="<?= e($q) ?>" placeholder="NIP atau nama"></div>
  <div><label>Peran</label><select class="input" name="peran" onchange="this.form.submit()">
    <option value="">Semua</option>
    <?php foreach (NAMA_PERAN as $k => $v): ?><option value="<?= $k ?>" <?= $fPeran === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
  </select></div>
  <button class="btn btn-primary">Cari</button>
</form>

<div class="split">
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Pengguna</th><th>Peran</th><th>Perangkat</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($users as $r): $d = perangkat_tampil($devs[$r['id_pengguna']] ?? []); ?>
        <tr class="<?= $r['aktif'] ? '' : 'nonaktif' ?>">
          <td><div class="usr"><span class="av av-<?= crc32($r['nip']) % 5 ?>"><?= e(mb_strtoupper(mb_substr($r['nama'], 0, 1))) ?></span><div><b><?= e($r['nama']) ?></b><div class="muted small"><?= e($r['nip']) ?><?= $r['aktif'] ? '' : ' · nonaktif' ?></div></div></div></td>
          <td><?= e(NAMA_PERAN[$r['peran']]) ?></td>
          <td>
            <?php if (!$d): ?><span class="muted">Belum login</span>
            <?php else: ?>
              <?= e($d['nama_perangkat']) ?><br>
              <?php if ($d['status_persetujuan'] === 'aktif'): ?><span class="badge badge-ok">Terikat</span><?= (BIOMETRIK_MODE === 'webauthn') ? ($d['credential_id'] ? ' <span class="badge badge-ok">Sidik jari ✓</span>' : ' <span class="badge badge-muted">Sidik jari belum</span>') : '' ?>
              <?php elseif ($d['status_persetujuan'] === 'menunggu'): ?><span class="badge badge-warn">Menunggu persetujuan</span>
              <?php elseif ($d['status_persetujuan'] === 'ditolak'): ?><span class="badge badge-err">Ditolak</span>
              <?php else: ?><span class="badge badge-muted">Nonaktif</span><?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="aksi">
            <?php if ($d && $d['status_persetujuan'] === 'menunggu'): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="id_perangkat" value="<?= (int)$d['id_perangkat'] ?>">
                <button class="btn btn-sm btn-primary" name="aksi" value="setujui">Setujui</button>
                <button class="btn btn-sm btn-danger" name="aksi" value="tolak">Tolak</button></form>
            <?php else: ?>
              <a class="btn btn-sm btn-line" href="?edit=<?= (int)$r['id_pengguna'] ?>">Ubah</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$users): ?><tr><td colspan="4" class="muted center">Tidak ada pengguna yang cocok.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <form method="post" class="card side-form" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="simpan"><input type="hidden" name="id" value="<?= $edit ? (int)$edit['id_pengguna'] : 0 ?>">
    <h3><?= $edit ? 'Ubah pengguna' : 'Tambah pengguna' ?></h3>
    <label>NIP</label>
    <input class="input" name="nip" inputmode="numeric" placeholder="Masukkan NIP" value="<?= e($edit['nip'] ?? '') ?>" <?= $edit ? 'readonly' : 'required' ?>>
    <label>Nama lengkap</label>
    <input class="input" name="nama" placeholder="Masukkan nama" value="<?= e($edit['nama'] ?? '') ?>" required>
    <label>Peran</label>
    <select class="input" name="peran" required>
      <option value="">Pilih peran</option>
      <?php foreach (NAMA_PERAN as $k => $v): ?><option value="<?= $k ?>" <?= ($edit['peran'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
    </select>
    <label><?= $edit ? 'Kata sandi baru (opsional)' : 'Kata sandi awal' ?></label>
    <input class="input" name="sandi" type="password" <?= $edit ? '' : 'required' ?> minlength="<?= MIN_PASSWORD ?>" placeholder="Min. <?= MIN_PASSWORD ?> karakter">
    <?php if ($edit): ?>
      <label class="check"><input type="checkbox" name="aktif" <?= $edit['aktif'] ? 'checked' : '' ?>> Akun aktif</label>
      <label class="check"><input type="checkbox" name="reset_perangkat"> Lepas perangkat terikat (login berikutnya mendaftarkan perangkat baru)</label>
    <?php endif; ?>
    <div class="btn-row">
      <button class="btn btn-primary">Simpan</button>
      <a class="btn btn-line" href="<?= e(url('admin/pengguna.php')) ?>">Batal</a>
    </div>
  </form>
</div>
<?php p_foot(); ?>
