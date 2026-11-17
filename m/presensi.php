<?php
/* (b) Presensi - KF-03, KF-04, KF-08 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

$u   = require_mobile_login();
$dev = perangkat_sesi();
$lok = lokasi_aktif();

$st = db()->prepare('SELECT * FROM presensi WHERE id_pengguna = ? AND tanggal = ?');
$st->execute([$u['id'], date('Y-m-d')]);
$hari = $st->fetch() ?: null;

$jam = (int)date('G');
$sapa = $jam < 11 ? 'pagi' : ($jam < 15 ? 'siang' : ($jam < 18 ? 'sore' : 'malam'));
$sudahMasuk  = $hari && $hari['jam_masuk'];
$sudahPulang = $hari && $hari['jam_pulang'];

if (!$sudahMasuk)      $catatan = 'Presensi pulang aktif setelah presensi masuk';
elseif (!$sudahPulang) $catatan = 'Presensi masuk tercatat pukul ' . fmt_jam($hari['jam_masuk']) . ' WIB (' . NAMA_STATUS[$hari['status']] . ')';
else                   $catatan = 'Presensi hari ini lengkap · masuk ' . fmt_jam($hari['jam_masuk']) . ', pulang ' . fmt_jam($hari['jam_pulang']);

m_head('Presensi');
?>
<div class="m-hero">
  <div class="hero-top">
    <div>
      <div class="hi">Selamat <?= $sapa ?>,</div>
      <div class="nama"><?= e($u['nama']) ?></div>
      <span class="chip chip-gold"><?= e(NAMA_PERAN[$u['peran']]) ?></span>
    </div>
    <div class="menu-wrap">
      <button class="avatar" id="btnMenu" aria-label="Menu akun"><?= e(mb_strtoupper(mb_substr($u['nama'], 0, 1))) ?></button>
      <div class="menu" id="menu" hidden>
        <?php if (is_panel_role($u['peran'])): ?>
          <a href="<?= e(url('admin/')) ?>"><?= icon('chart', 18) ?> Web Panel</a>
        <?php endif; ?>
        <form method="post" action="<?= e(url('m/logout.php')) ?>"><?= csrf_field() ?><button><?= icon('logout', 18) ?> Keluar</button></form>
      </div>
    </div>
  </div>
</div>

<div class="m-body overlap" id="app"
     data-api="<?= e(url('api/presensi.php')) ?>"
     data-csrf="<?= e(csrf_token()) ?>"
     data-mode="<?= e(BIOMETRIK_MODE) ?>"
     data-terdaftar="<?= $dev['credential_id'] ? '1' : '0' ?>"
     data-server-ms="<?= (int)(microtime(true) * 1000) ?>"
     data-lat="<?= $lok ? e($lok['latitude']) : '' ?>"
     data-lng="<?= $lok ? e($lok['longitude']) : '' ?>"
     data-radius="<?= $lok ? (int)$lok['radius_meter'] : 0 ?>">

  <div class="card clock">
    <div class="date-pill"><?= NAMA_HARI[(int)date('w')] ?>, <?= fmt_tgl(date('Y-m-d'), false) ?></div>
    <div class="time" id="clock">--.--</div>
    <div class="shift">
      <div><span>Masuk</span><b><?= fmt_jam(JAM_MASUK) ?></b></div>
      <div><span>Batas terlambat</span><b><?= fmt_jam(batas_terlambat()) ?></b></div>
      <div><span>Pulang</span><b><?= fmt_jam(JAM_PULANG) ?></b></div>
    </div>
  </div>

  <?php if (BIOMETRIK_MODE === 'webauthn' && !$dev['credential_id']): ?>
  <div class="alert alert-warn" id="regBox">
    <b>Sidik jari belum didaftarkan</b> pada perangkat ini.
    <button class="btn btn-sm btn-gold" id="btnReg" style="margin-top:8px;display:flex">Daftarkan sidik jari</button>
  </div>
  <?php endif; ?>

  <?php if (!$lok): ?>
    <div class="alert alert-err">Lokasi presensi belum diatur oleh Admin.</div>
  <?php else: ?>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css">
  <div class="card map"><div id="map" class="map-real"></div></div>
  <div class="geo-row"><span class="badge badge-muted" id="geoBadge">Mencari lokasi…</span><span class="small muted" id="geoText"></span></div>
  <?php endif; ?>

  <button class="btn btn-primary btn-xl btn-block" id="btnMasuk" <?= (!$lok || $sudahMasuk) ? 'disabled' : '' ?>><?= icon('fingerprint', 24) ?> Presensi Masuk</button>
  <button class="btn btn-gold btn-xl btn-block"    id="btnPulang" <?= (!$lok || !$sudahMasuk || $sudahPulang) ? 'disabled' : '' ?>><?= icon('logout', 24) ?> Presensi Pulang</button>

  <?php if ($sudahMasuk): ?>
    <div class="today">
      <div><span>Masuk</span><b><?= e(fmt_jam($hari['jam_masuk'])) ?></b></div>
      <div><span>Pulang</span><b><?= e(fmt_jam($hari['jam_pulang'])) ?></b></div>
      <?= badge_status($hari['status']) ?>
    </div>
  <?php else: ?>
    <p class="center muted small" style="margin-top:10px"><?= e($catatan) ?></p>
  <?php endif; ?>
</div>

<!-- (c) Verifikasi sidik jari -->
<div class="overlay" id="ovBio" hidden>
  <div class="sheet">
    <div class="grip"></div>
    <h2>Verifikasi sidik jari</h2>
    <p class="muted" id="bioText">Letakkan jari pada sensor untuk melakukan presensi.</p>
    <div class="fp-wrap"><?= icon('fingerprint', 60) ?></div>
    <p class="muted small">Sensor sidik jari perangkat</p>
    <?php if (BIOMETRIK_MODE === 'simulasi'): ?>
      <button class="btn btn-primary btn-block" id="bioOk">Sentuh sensor (simulasi)</button>
      <button class="btn btn-sm btn-block link" id="bioFail">Simulasikan sidik jari tidak cocok</button>
    <?php endif; ?>
    <button class="btn btn-line btn-block" id="bioBatal">Batal</button>
    <p class="muted small" style="margin-top:10px"><?= BIOMETRIK_MODE === 'webauthn' ? 'Dialog biometrik bawaan Android' : 'Mode simulasi (tanpa sensor sungguhan)' ?></p>
  </div>
</div>

<!-- (d)/(e) Hasil presensi -->
<div class="overlay full" id="ovHasil" hidden></div>

<?php m_nav('presensi'); ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>
<?php m_foot('assets/js/presensi.js'); ?>
