<?php
/* (b) Pengelolaan lokasi - KF-09 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

$admin = require_role(['admin']);
$pdo = db();
$lok = lokasi_aktif();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $nama = trim($_POST['nama'] ?? '');
    $lat  = str_replace(',', '.', trim($_POST['latitude'] ?? ''));
    $lng  = str_replace(',', '.', trim($_POST['longitude'] ?? ''));
    $rad  = $_POST['radius'] ?? '';
    $err  = null;
    if ($nama === '')                                              $err = 'Nama lokasi wajib diisi.';
    elseif (!is_numeric($lat) || abs($lat) > 90)                   $err = 'Latitude tidak valid (−90 sampai 90).';
    elseif (!is_numeric($lng) || abs($lng) > 180)                  $err = 'Longitude tidak valid (−180 sampai 180).';
    elseif (!ctype_digit((string)$rad) || $rad < 10 || $rad > 5000) $err = 'Radius harus 10–5000 meter.';

    if ($err) flash('err', $err);
    else {
        if ($lok) $pdo->prepare('UPDATE lokasi SET nama_lokasi=?, latitude=?, longitude=?, radius_meter=? WHERE id_lokasi=?')->execute([$nama, $lat, $lng, (int)$rad, $lok['id_lokasi']]);
        else      $pdo->prepare('INSERT INTO lokasi (nama_lokasi, latitude, longitude, radius_meter) VALUES (?,?,?,?)')->execute([$nama, $lat, $lng, (int)$rad]);
        flash('ok', 'Lokasi presensi disimpan.');
    }
    redirect('admin/lokasi.php');
}

p_head('Lokasi Presensi', 'lokasi', $admin);
$v = fn($k, $d = '') => e($lok[$k] ?? $d);
?>
<div class="loc-grid">
  <form method="post" class="loc-form" autocomplete="off">
    <?= csrf_field() ?>
    <label>Nama lokasi</label>
    <input class="input" name="nama" value="<?= $v('nama_lokasi', 'Titik pusat ' . APP_SCHOOL) ?>" required>
    <label>Latitude</label>
    <input class="input" id="lat" name="latitude" inputmode="decimal" value="<?= $v('latitude') ?>" placeholder="-7.7xxxxx" required>
    <label>Longitude</label>
    <input class="input" id="lng" name="longitude" inputmode="decimal" value="<?= $v('longitude') ?>" placeholder="113.5xxxxx" required>
    <label>Radius (meter)</label>
    <div class="pw"><input class="input" id="rad" name="radius" type="number" min="10" max="5000" value="<?= $v('radius_meter', 100) ?>" required><span class="unit">m</span></div>
    <p class="muted small">Presensi diterima jika jarak perangkat ke titik pusat ≤ radius (rumus Haversine).</p>
    <div class="btn-row">
      <button type="button" class="btn btn-line" id="btnGps">Lokasi saat ini</button>
      <button class="btn btn-primary">Simpan</button>
    </div>
    <?php if ($lok): ?><p class="small"><a class="small-link" target="_blank" rel="noopener" href="https://www.google.com/maps?q=<?= e($lok['latitude']) ?>,<?= e($lok['longitude']) ?>">Periksa titik di Google Maps ↗</a></p><?php endif; ?>
  </form>

  <div class="map-prev">
    <div id="amap"></div>
    <span class="map-label" id="radLabel">Radius <?= $v('radius_meter', 100) ?> m</span>
  </div>
</div>
<p class="muted small">Klik pada peta atau geser pin untuk memindahkan titik pusat. Koordinat terisi otomatis.</p>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>
<script>
(function () {
  var $ = function (i) { return document.getElementById(i); };
  var latEl = $('lat'), lngEl = $('lng'), rad = $('rad');
  if (typeof L === 'undefined') { $('amap').innerHTML = '<div class="map-fallback">Peta tidak dapat dimuat. Periksa koneksi internet. Koordinat tetap bisa diisi manual.</div>'; }
  else {
    L.Icon.Default.imagePath = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/';
    var lat0 = parseFloat(latEl.value), lng0 = parseFloat(lngEl.value);
    if (isNaN(lat0) || isNaN(lng0)) { lat0 = -2.9846944; lng0 = 104.7325278; }
    var map = L.map('amap').setView([lat0, lng0], 17);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
    var marker = L.marker([lat0, lng0], { draggable: true }).addTo(map);
    var circle = L.circle([lat0, lng0], { radius: parseInt(rad.value, 10) || 100, color: '#1f3a68', weight: 2, dashArray: '6 5', fillColor: '#1f3a68', fillOpacity: 0.12 }).addTo(map);
    function setPos(ll, pindah) {
      marker.setLatLng(ll); circle.setLatLng(ll);
      latEl.value = ll.lat.toFixed(7); lngEl.value = ll.lng.toFixed(7);
      if (pindah) map.setView(ll, Math.max(map.getZoom(), 17));
    }
    map.on('click', function (e) { setPos(e.latlng, false); });
    marker.on('dragend', function () { setPos(marker.getLatLng(), false); });
    function dariInput() {
      var la = parseFloat(latEl.value.replace(',', '.')), ln = parseFloat(lngEl.value.replace(',', '.'));
      if (!isNaN(la) && !isNaN(ln) && Math.abs(la) <= 90 && Math.abs(ln) <= 180) { marker.setLatLng([la, ln]); circle.setLatLng([la, ln]); map.setView([la, ln], map.getZoom()); }
    }
    latEl.addEventListener('change', dariInput); lngEl.addEventListener('change', dariInput);
    rad.addEventListener('input', function () { circle.setRadius(parseInt(rad.value, 10) || 0); });
    window.__setPos = setPos;
  }
  rad.addEventListener('input', function () { $('radLabel').textContent = 'Radius ' + (rad.value || 0) + ' m'; });
  $('btnGps').addEventListener('click', function () {
    var b = this;
    if (!navigator.geolocation) { alert('Peramban tidak mendukung geolokasi.'); return; }
    b.disabled = true; b.textContent = 'Mencari…';
    navigator.geolocation.getCurrentPosition(function (p) {
      var ll = { lat: p.coords.latitude, lng: p.coords.longitude };
      if (window.__setPos) window.__setPos(ll, true); else { latEl.value = ll.lat.toFixed(7); lngEl.value = ll.lng.toFixed(7); }
      b.disabled = false; b.textContent = 'Lokasi saat ini';
    }, function () {
      alert('Lokasi tidak dapat dibaca. Izinkan akses lokasi pada browser (butuh HTTPS atau localhost).');
      b.disabled = false; b.textContent = 'Lokasi saat ini';
    }, { enableHighAccuracy: true, timeout: 15000 });
  });
})();
</script>
<?php p_foot(); ?>
