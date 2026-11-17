<?php
/* (f) Riwayat kehadiran pribadi - KF-08 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

$u = require_mobile_login();

$bulan = $_GET['bulan'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan)) $bulan = date('Y-m');

$st = db()->prepare('SELECT * FROM presensi WHERE id_pengguna = ? AND tanggal BETWEEN ? AND LAST_DAY(?) ORDER BY tanggal DESC');
$st->execute([$u['id'], $bulan . '-01', $bulan . '-01']);
$rows = $st->fetchAll();

$hadir = count($rows);
$terlambat = count(array_filter($rows, fn($r) => $r['status'] === 'terlambat'));
$awal = count(array_filter($rows, fn($r) => $r['status'] === 'pulang_awal'));

// Pilihan bulan: 12 bulan terakhir
$opsi = [];
for ($i = 0; $i < 12; $i++) { $k = date('Y-m', strtotime("-$i month", strtotime(date('Y-m-01')))); $opsi[$k] = nama_bulan_tahun($k); }
if (!isset($opsi[$bulan])) $opsi[$bulan] = nama_bulan_tahun($bulan);

m_head('Riwayat');
?>
<div class="m-hero short">
  <h1>Riwayat kehadiran</h1>
  <form method="get"><select class="input on-dark" name="bulan" onchange="this.form.submit()">
    <?php foreach ($opsi as $k => $v): ?><option value="<?= e($k) ?>" <?= $k === $bulan ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
  </select></form>
</div>
<div class="m-body overlap">
  <div class="stats3">
    <div class="stat s-green"><b><?= $hadir ?></b><span>Hadir</span></div>
    <div class="stat s-gold"><b><?= $terlambat ?></b><span>Terlambat</span></div>
    <div class="stat s-blue"><b><?= $awal ?></b><span>Pulang awal</span></div>
  </div>

  <ul class="riwayat">
    <?php foreach ($rows as $r): $ts = strtotime($r['tanggal']); ?>
      <li class="st-<?= e($r['status']) ?>">
        <div class="dtile"><b><?= date('j', $ts) ?></b><span><?= e(HARI_PEND[(int)date('w', $ts)]) ?></span></div>
        <div class="grow">
          <div class="rt"><?= e(fmt_jam($r['jam_masuk'])) ?> <i>→</i> <?= e(fmt_jam($r['jam_pulang'])) ?></div>
          <div class="muted small">Masuk · Pulang</div>
        </div>
        <?= badge_status($r['status']) ?>
      </li>
    <?php endforeach; ?>
    <?php if (!$rows): ?><li class="empty"><?= icon('history', 28) ?><div>Belum ada data presensi pada bulan ini.</div></li><?php endif; ?>
  </ul>
</div>
<?php m_nav('riwayat'); m_foot(); ?>
