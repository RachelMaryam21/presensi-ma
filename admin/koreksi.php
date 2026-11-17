<?php
/* (c) Koreksi data presensi - KF-14 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

$admin = require_role(['admin']);
$pdo = db();

function valid_jam(?string $j): ?string {            // "06:35" -> "06:35:00"; '' -> null
    $j = trim((string)$j);
    if ($j === '') return null;
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $j)) return false;
    return strlen($j) === 5 ? $j . ':00' : $j;
}
function ringkas(array $r): string {
    return 'masuk ' . fmt_jam($r['jam_masuk']) . ', pulang ' . fmt_jam($r['jam_pulang']) . ', status ' . (NAMA_STATUS[$r['status']] ?? $r['status']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $id     = (int)($_POST['id_presensi'] ?? 0);
    $alasan = trim($_POST['alasan'] ?? '');
    $masuk  = valid_jam($_POST['jam_masuk'] ?? '');
    $pulang = valid_jam($_POST['jam_pulang'] ?? '');
    $statusIn = $_POST['status'] ?? 'auto';
    $back = 'admin/koreksi.php?' . http_build_query(['dari' => $_POST['dari'] ?? '', 'sampai' => $_POST['sampai'] ?? '', 'pegawai' => $_POST['pegawai'] ?? 0]);
    $err = null;

    if ($alasan === '' || mb_strlen($alasan) > 255) $err = 'Alasan koreksi wajib diisi (maks. 255 karakter).';
    elseif ($masuk === false || $pulang === false)   $err = 'Format jam tidak valid.';
    elseif ($masuk === null)                         $err = 'Jam masuk wajib diisi.';
    elseif ($pulang !== null && $pulang <= $masuk)   $err = 'Jam pulang harus setelah jam masuk.';
    elseif ($statusIn !== 'auto' && !isset(NAMA_STATUS[$statusIn])) $err = 'Status tidak valid.';

    if (!$err) {
        $status = $statusIn !== 'auto' ? $statusIn
                : ($pulang ? status_setelah_pulang(status_masuk($masuk), $pulang) : status_masuk($masuk));
        $pdo->beginTransaction();
        if ($id) {
            $s = $pdo->prepare('SELECT * FROM presensi WHERE id_presensi = ? FOR UPDATE'); $s->execute([$id]); $old = $s->fetch();
            if (!$old) { $pdo->rollBack(); flash('err', 'Data presensi tidak ditemukan.'); redirect($back); }
            $pdo->prepare('UPDATE presensi SET jam_masuk=?, jam_pulang=?, status=? WHERE id_presensi=?')->execute([$masuk, $pulang, $status, $id]);
            $sebelum = ringkas($old);
        } else {                                                    // tambah presensi manual
            $idp = (int)($_POST['id_pengguna'] ?? 0);
            $tgl = $_POST['tanggal'] ?? '';
            $lok = lokasi_aktif();
            $cek = $pdo->prepare('SELECT 1 FROM pengguna WHERE id_pengguna = ?'); $cek->execute([$idp]);
            $dt  = DateTime::createFromFormat('Y-m-d', $tgl);
            if (!$cek->fetch() || !$dt || $dt->format('Y-m-d') !== $tgl || !$lok) { $pdo->rollBack(); flash('err', 'Pegawai, tanggal, atau lokasi tidak valid.'); redirect($back); }
            $ada = $pdo->prepare('SELECT 1 FROM presensi WHERE id_pengguna=? AND tanggal=?'); $ada->execute([$idp, $tgl]);
            if ($ada->fetch()) { $pdo->rollBack(); flash('err', 'Pegawai sudah memiliki presensi pada tanggal tersebut. Gunakan tombol Koreksi.'); redirect($back); }
            $pdo->prepare('INSERT INTO presensi (id_pengguna,id_lokasi,tanggal,jam_masuk,jam_pulang,status) VALUES (?,?,?,?,?,?)')
                ->execute([$idp, $lok['id_lokasi'], $tgl, $masuk, $pulang, $status]);
            $id = (int)$pdo->lastInsertId();
            $sebelum = 'Tidak ada data (ditambahkan manual)';
        }
        $sesudah = ringkas(['jam_masuk' => $masuk, 'jam_pulang' => $pulang, 'status' => $status]);
        $pdo->prepare('INSERT INTO koreksi_presensi (id_presensi,id_admin,alasan,data_sebelum,data_sesudah) VALUES (?,?,?,?,?)')
            ->execute([$id, $admin['id'], $alasan, $sebelum, $sesudah]);
        $pdo->commit();
        flash('ok', 'Koreksi presensi disimpan dan dicatat pada jejak audit.');
    } else flash('err', $err);
    redirect($back);
}

/* ---- Filter ---- */
$tgl = fn($k, $d) => (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET[$k] ?? '') ? $_GET[$k] : $d);
$dari   = $tgl('dari',   date('Y-m-d', strtotime('monday this week')));
$sampai = $tgl('sampai', date('Y-m-d'));
$fPeg   = (int)($_GET['pegawai'] ?? 0);

$sql = 'SELECT pr.*, pg.nama FROM presensi pr JOIN pengguna pg ON pg.id_pengguna = pr.id_pengguna WHERE pr.tanggal BETWEEN ? AND ?';
$par = [$dari, $sampai];
if ($fPeg) { $sql .= ' AND pr.id_pengguna = ?'; $par[] = $fPeg; }
$st = $pdo->prepare($sql . ' ORDER BY pr.tanggal, pg.nama LIMIT 500'); $st->execute($par);
$rows = $st->fetchAll();
$pegawai = $pdo->query('SELECT id_pengguna, nama FROM pengguna WHERE aktif = 1 ORDER BY nama')->fetchAll();

p_head('Koreksi Data Presensi', 'koreksi', $admin);
?>
<form method="get" class="filters">
  <div><label>Dari</label><input class="input" type="date" name="dari" value="<?= e($dari) ?>"></div>
  <div><label>Sampai</label><input class="input" type="date" name="sampai" value="<?= e($sampai) ?>"></div>
  <div class="grow"><label>Pegawai</label><select class="input" name="pegawai">
    <option value="0">Semua pegawai</option>
    <?php foreach ($pegawai as $p): ?><option value="<?= (int)$p['id_pengguna'] ?>" <?= $fPeg === (int)$p['id_pengguna'] ? 'selected' : '' ?>><?= e($p['nama']) ?></option><?php endforeach; ?>
  </select></div>
  <button class="btn btn-primary">Cari</button>
  <button type="button" class="btn btn-line" id="btnTambah">+ Presensi manual</button>
</form>

<div class="table-wrap">
<table class="tbl">
  <thead><tr><th>Tanggal</th><th>Pegawai</th><th>Masuk</th><th>Pulang</th><th>Status</th><th>Aksi</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= e(fmt_tgl($r['tanggal'])) ?></td><td><?= e($r['nama']) ?></td>
      <td><?= e(fmt_jam($r['jam_masuk'])) ?></td><td><?= e(fmt_jam($r['jam_pulang'])) ?></td>
      <td><?= badge_status($r['status']) ?></td>
      <td><button class="btn btn-sm btn-line btn-koreksi"
            data-id="<?= (int)$r['id_presensi'] ?>" data-nama="<?= e($r['nama']) ?>" data-tgl="<?= e(fmt_tgl($r['tanggal'], false)) ?>"
            data-masuk="<?= e(substr((string)$r['jam_masuk'], 0, 5)) ?>" data-pulang="<?= e(substr((string)$r['jam_pulang'], 0, 5)) ?>"
            data-status="<?= e($r['status']) ?>">Koreksi</button></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted center">Tidak ada data presensi pada periode ini.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<dialog id="dlg" class="modal">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="id_presensi" id="f_id" value="0">
    <input type="hidden" name="dari" value="<?= e($dari) ?>"><input type="hidden" name="sampai" value="<?= e($sampai) ?>"><input type="hidden" name="pegawai" value="<?= $fPeg ?>">
    <h3 id="dlgTitle">Koreksi presensi</h3>
    <p class="muted" id="dlgSub"></p>
    <div id="manual" hidden>
      <label>Pegawai</label>
      <select class="input" name="id_pengguna" id="f_peg"><?php foreach ($pegawai as $p): ?><option value="<?= (int)$p['id_pengguna'] ?>"><?= e($p['nama']) ?></option><?php endforeach; ?></select>
      <label>Tanggal</label><input class="input" type="date" name="tanggal" id="f_tgl" max="<?= date('Y-m-d') ?>">
    </div>
    <div class="two">
      <div><label>Jam masuk</label><input class="input" type="time" name="jam_masuk" id="f_masuk" required></div>
      <div><label>Jam pulang</label><input class="input" type="time" name="jam_pulang" id="f_pulang"></div>
    </div>
    <label>Status</label>
    <select class="input" name="status" id="f_status">
      <option value="auto">Otomatis (hitung dari jam)</option>
      <?php foreach (NAMA_STATUS as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?>
    </select>
    <label>Alasan koreksi (wajib)</label>
    <textarea class="input" name="alasan" id="f_alasan" rows="3" maxlength="255" required placeholder="Tuliskan alasan koreksi…"></textarea>
    <div class="btn-row end">
      <button type="button" class="btn btn-line" id="btnBatal">Batal</button>
      <button class="btn btn-primary">Simpan koreksi</button>
    </div>
  </form>
</dialog>
<script>
(function () {
  var d = document.getElementById('dlg'), $ = function (i) { return document.getElementById(i); };
  function buka(manual) { $('manual').hidden = !manual; $('f_tgl').required = manual; d.showModal(); }
  document.querySelectorAll('.btn-koreksi').forEach(function (b) {
    b.addEventListener('click', function () {
      $('dlgTitle').textContent = 'Koreksi presensi'; $('dlgSub').textContent = b.dataset.nama + ' · ' + b.dataset.tgl;
      $('f_id').value = b.dataset.id; $('f_masuk').value = b.dataset.masuk; $('f_pulang').value = b.dataset.pulang;
      $('f_status').value = b.dataset.status; $('f_alasan').value = ''; buka(false);
    });
  });
  $('btnTambah').addEventListener('click', function () {
    $('dlgTitle').textContent = 'Tambah presensi manual'; $('dlgSub').textContent = 'Untuk pegawai yang tidak dapat melakukan presensi (mis. perangkat rusak).';
    $('f_id').value = 0; $('f_masuk').value = ''; $('f_pulang').value = ''; $('f_status').value = 'auto'; $('f_alasan').value = ''; $('f_tgl').value = ''; buka(true);
  });
  $('btnBatal').addEventListener('click', function () { d.close(); });
})();
</script>
<?php p_foot(); ?>
