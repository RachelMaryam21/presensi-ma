<?php
/* (d) Rekapitulasi kehadiran - KF-11 (Admin), KF-12 (Kepala Madrasah, lihat saja) */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

$u = require_role(['admin', 'kepala']);
$pdo = db();

$bulan = $_GET['bulan'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan)) $bulan = date('Y-m');
$fPeran = isset(NAMA_PERAN[$_GET['peran'] ?? '']) ? $_GET['peran'] : '';

$hk = daftar_hari_kerja($bulan);
$hkSet = array_flip($hk);
$jmlHk = count($hk);

$sql = 'SELECT id_pengguna, nip, nama, peran FROM pengguna WHERE aktif = 1'; $par = [];
if ($fPeran) { $sql .= ' AND peran = ?'; $par[] = $fPeran; }
$st = $pdo->prepare($sql . ' ORDER BY nama'); $st->execute($par);
$pegawai = $st->fetchAll();

$st = $pdo->prepare('SELECT id_pengguna, tanggal, status FROM presensi WHERE tanggal BETWEEN ? AND LAST_DAY(?) AND jam_masuk IS NOT NULL');
$st->execute([$bulan . '-01', $bulan . '-01']);
$hadirMap = [];
foreach ($st as $r) if (isset($hkSet[$r['tanggal']])) $hadirMap[$r['id_pengguna']][] = $r['status'];

$rekap = []; $sumPersen = 0; $nPersen = 0; $sumTerlambat = 0; $bawah = 0;
foreach ($pegawai as $p) {
    $s = $hadirMap[$p['id_pengguna']] ?? [];
    $hadir = count($s);
    $tl = count(array_filter($s, fn($x) => $x === 'terlambat'));
    $persen = $jmlHk ? round($hadir / $jmlHk * 100) : null;
    $di_bawah = $jmlHk > 0 && ($hadir / $jmlHk * 100) < 90;   // dibandingkan sebelum pembulatan
    if ($persen !== null) { $sumPersen += $persen; $nPersen++; }
    $sumTerlambat += $tl; $bawah += $di_bawah ? 1 : 0;
    $rekap[] = ['nip' => $p['nip'], 'nama' => $p['nama'], 'peran' => $p['peran'], 'hadir' => $hadir, 'terlambat' => $tl,
                'absen' => max(0, $jmlHk - $hadir), 'persen' => $persen, 'bawah' => $di_bawah];
}
$rata = $nPersen ? round($sumPersen / $nPersen) : null;

/* Unduh CSV (khusus Admin) */
if (($_GET['export'] ?? '') === 'csv' && $u['peran'] === 'admin') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rekap-presensi-' . $bulan . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['NIP', 'Pegawai', 'Peran', 'Hari kerja', 'Hadir', 'Terlambat', 'Tidak hadir', 'Kehadiran (%)', 'Keterangan']);
    foreach ($rekap as $r) fputcsv($o, [$r['nip'], $r['nama'], NAMA_PERAN[$r['peran']], $jmlHk, $r['hadir'], $r['terlambat'], $r['absen'], $r['persen'] ?? '', $r['bawah'] ? 'Di bawah 90%' : 'Baik']);
    exit;
}

$opsi = [];
for ($i = 0; $i < 12; $i++) { $k = date('Y-m', strtotime("-$i month", strtotime(date('Y-m-01')))); $opsi[$k] = nama_bulan_tahun($k); }
if (!isset($opsi[$bulan])) $opsi[$bulan] = nama_bulan_tahun($bulan);

p_head('Rekapitulasi Kehadiran', 'rekap', $u);
?>
<form method="get" class="filters">
  <div><label>Bulan</label><select class="input" name="bulan"><?php foreach ($opsi as $k => $v): ?><option value="<?= e($k) ?>" <?= $k === $bulan ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
  <div><label>Peran</label><select class="input" name="peran"><option value="">Semua peran</option>
    <?php foreach (NAMA_PERAN as $k => $v): ?><option value="<?= $k ?>" <?= $fPeran === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
  <button class="btn btn-primary">Tampilkan</button>
  <?php if ($u['peran'] === 'admin'): ?><a class="btn btn-line" href="?<?= e(http_build_query(['bulan' => $bulan, 'peran' => $fPeran, 'export' => 'csv'])) ?>"><?= icon('download', 18) ?> Unduh CSV</a><?php endif; ?>
</form>

<div class="stats4">
  <div class="stat s-green"><i class="ico"><?= icon('users', 24) ?></i><div><b><?= count($rekap) ?></b><span>Pegawai</span></div></div>
  <div class="stat s-blue"><i class="ico"><?= icon('percent', 24) ?></i><div><b><?= $rata === null ? '–' : $rata . '%' ?></b><span>Rata-rata kehadiran</span></div></div>
  <div class="stat s-gold"><i class="ico"><?= icon('clock', 24) ?></i><div><b><?= $sumTerlambat ?></b><span>Kejadian terlambat</span></div></div>
  <div class="stat s-red"><i class="ico"><?= icon('alert', 24) ?></i><div><b><?= $bawah ?></b><span>Kehadiran di bawah 90%</span></div></div>
</div>

<div class="table-wrap">
<table class="tbl">
  <thead><tr><th>Pegawai</th><th>Hadir</th><th>Terlambat</th><th>Tidak hadir</th><th>Kehadiran</th><th>Keterangan</th></tr></thead>
  <tbody>
  <?php foreach ($rekap as $r): ?>
    <tr>
      <td><b><?= e($r['nama']) ?></b><div class="muted small"><?= e(NAMA_PERAN[$r['peran']]) ?></div></td>
      <td><?= $r['hadir'] ?></td><td><?= $r['terlambat'] ?></td><td><?= $r['absen'] ?></td>
      <td><?= $r['persen'] === null ? '–' : $r['persen'] . '%' ?></td>
      <td><?= $r['persen'] === null ? '<span class="muted">–</span>' : ($r['bawah'] ? '<span class="badge badge-warn">Di bawah 90%</span>' : '<span class="badge badge-ok">Baik</span>') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rekap): ?><tr><td colspan="6" class="muted center">Tidak ada pegawai.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<p class="muted small">Hari kerja <?= e(nama_bulan_tahun($bulan)) ?>: <?= $jmlHk ?> hari<?= $bulan === date('Y-m') ? ' (sampai hari terakhir yang sudah selesai)' : '' ?>.
  <?= $u['peran'] === 'kepala' ? 'Kepala Madrasah mengakses halaman ini dalam mode lihat saja.' : '' ?></p>
<?php p_foot(); ?>
