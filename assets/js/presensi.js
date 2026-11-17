/* Presensi mobile: GPS + sidik jari + kirim ke server. Validasi final dilakukan di server. */
(function () {
  'use strict';
  var app = document.getElementById('app');
  var cfg = {
    api: app.dataset.api, csrf: app.dataset.csrf, mode: app.dataset.mode,
    terdaftar: app.dataset.terdaftar === '1',
    lat: parseFloat(app.dataset.lat), lng: parseFloat(app.dataset.lng), radius: parseInt(app.dataset.radius, 10) || 0
  };
  var $ = function (id) { return document.getElementById(id); };
  var busy = false, bioAbort = null, bioResolve = null;

  /* ---------- Jam (disinkronkan dengan waktu server) ---------- */
  var offset = parseInt(app.dataset.serverMs, 10) - Date.now();
  function tick() {
    var d = new Date(Date.now() + offset);
    $('clock').textContent = ('0' + d.getHours()).slice(-2) + '.' + ('0' + d.getMinutes()).slice(-2);
  }
  tick(); setInterval(tick, 5000);

  /* ---------- Menu akun ---------- */
  $('btnMenu').addEventListener('click', function (e) { e.stopPropagation(); $('menu').hidden = !$('menu').hidden; });
  document.addEventListener('click', function () { $('menu').hidden = true; });

  /* ---------- Util ---------- */
  function b64u(buf) {
    var s = '', b = new Uint8Array(buf);
    for (var i = 0; i < b.length; i++) s += String.fromCharCode(b[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function unb64u(s) {
    s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) s += '=';
    var bin = atob(s), a = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) a[i] = bin.charCodeAt(i);
    return a.buffer;
  }
  function post(body) {
    return fetch(cfg.api, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); });
  }
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

  /* ---------- Posisi & peta kecil ---------- */
  function haversine(a, b, c, d) {
    var R = 6371000, r = Math.PI / 180, dp = (c - a) * r, dl = (d - b) * r;
    var x = Math.sin(dp / 2) * Math.sin(dp / 2) + Math.cos(a * r) * Math.cos(c * r) * Math.sin(dl / 2) * Math.sin(dl / 2);
    return 2 * R * Math.asin(Math.min(1, Math.sqrt(x)));
  }
  function setGeo(kelas, label, teks) {
    var b = $('geoBadge'); if (!b) return;
    b.className = 'badge badge-' + kelas; b.textContent = label; $('geoText').textContent = teks || '';
  }
  var map = null, circle = null, meMarker = null, accCircle = null;
  function initMap() {
    var el = $('map'); if (!el || !cfg.radius) return;
    if (typeof L === 'undefined') { el.innerHTML = '<div class="map-fallback">Peta tidak dapat dimuat. Periksa koneksi internet.</div>'; return; }
    map = L.map(el, { zoomControl: true }).setView([cfg.lat, cfg.lng], 17);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
    circle = L.circle([cfg.lat, cfg.lng], { radius: cfg.radius, color: '#1f3a68', weight: 2, dashArray: '6 5', fillColor: '#1f3a68', fillOpacity: 0.12 }).addTo(map);
    L.circleMarker([cfg.lat, cfg.lng], { radius: 7, color: '#1f3a68', weight: 3, fillColor: '#fff', fillOpacity: 1 }).addTo(map).bindTooltip('Titik pusat');
    map.fitBounds(circle.getBounds(), { padding: [20, 20] });
  }
  initMap();

  var lastFix = null;   // posisi terbaru dari pemantau GPS
  function updateGeo(pos) {
    lastFix = { pos: pos, t: Date.now() };
    var la = pos.coords.latitude, ln = pos.coords.longitude;
    var d = haversine(la, ln, cfg.lat, cfg.lng), inside = d <= cfg.radius;
    setGeo(inside ? 'ok' : 'err', inside ? 'Di dalam area' : 'Di luar area', Math.round(d) + ' m dari titik pusat (maks. ' + cfg.radius + ' m)');
    if (!map) return;
    var ll = [la, ln], pertama = !meMarker;
    if (pertama) {
      meMarker = L.circleMarker(ll, { radius: 7, color: '#fff', weight: 2, fillColor: '#e03131', fillOpacity: 1 }).addTo(map).bindTooltip('Posisi Anda');
      accCircle = L.circle(ll, { radius: pos.coords.accuracy || 0, color: '#e03131', weight: 1, fillOpacity: 0.08 }).addTo(map);
    } else { meMarker.setLatLng(ll); accCircle.setLatLng(ll).setRadius(pos.coords.accuracy || 0); }
    if (pertama) {
      var cb = circle.getBounds();
      map.fitBounds(L.latLngBounds([ll, cb.getSouthWest(), cb.getNorthEast()]), { padding: [25, 25], maxZoom: 18 });
    }
  }
  if (cfg.radius && navigator.geolocation) {
    navigator.geolocation.watchPosition(updateGeo, function (err) {
      setGeo('err', 'GPS nonaktif', err.code === 1 ? 'Izin lokasi ditolak' : 'Lokasi tidak tersedia');
    }, { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 });
  } else if (cfg.radius) { setGeo('err', 'GPS tidak didukung', ''); }

  function getPosition() {
    return new Promise(function (resolve, reject) {
      if (lastFix && Date.now() - lastFix.t < 20000) return resolve(lastFix.pos);   // fix GPS masih segar
      if (!navigator.geolocation) return reject({ code: 0 });
      navigator.geolocation.getCurrentPosition(resolve, reject, { enableHighAccuracy: true, timeout: 15000, maximumAge: 10000 });
    });
  }

  /* ---------- Layar hasil (d) berhasil / (e) ditolak ---------- */
  function showHasil(html) { var o = $('ovHasil'); o.innerHTML = html; o.hidden = false; }
  var SVG_OK = '<svg viewBox="0 0 24 24" width="54" height="54" fill="none" stroke="#13705a" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path class="draw" d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
  var SVG_ERR = '<svg viewBox="0 0 24 24" width="50" height="50" fill="none" stroke="#c0432e" stroke-width="3" stroke-linecap="round"><path class="draw" d="M6.5 6.5l11 11M17.5 6.5l-11 11"/></svg>';
  function hasilOk(d) {
    showHasil(
      '<div class="res-wrap"><div class="res-top ok"><div class="res-badge">' + SVG_OK + '</div>' +
      '<h1>Presensi ' + esc(d.jenis) + ' berhasil</h1><p>Data Anda sudah tercatat.</p></div>' +
      '<div class="card res-card kv"><div><span>Waktu</span><b>' + esc(d.waktu) + '</b></div>' +
      '<div><span>Status</span><b class="badge badge-' + d.kelas + '">' + esc(d.status) + '</b></div>' +
      '<div><span>Jarak</span><b>' + esc(d.jarak) + '</b></div>' +
      '<div><span>Lokasi</span><b>' + esc(d.lokasi) + '</b></div></div>' +
      '<div class="res-foot"><button class="btn btn-primary btn-xl btn-block" id="hSelesai">Selesai</button></div></div>');
    $('hSelesai').onclick = function () { location.reload(); };
  }
  function hasilGagal(pesan, jenis, aksiDaftar) {
    showHasil(
      '<div class="res-wrap"><div class="res-top err"><div class="res-badge">' + SVG_ERR + '</div><h1>Presensi ditolak</h1></div>' +
      '<div class="card res-card"><div class="alert alert-err" style="margin-top:0">' + esc(pesan) + '</div>' +
      '<b class="small">Presensi juga ditolak jika:</b><ul class="small muted"><li>GPS atau izin lokasi nonaktif</li><li>Sidik jari tidak cocok atau belum terdaftar</li><li>Presensi ganda pada hari yang sama</li></ul></div>' +
      '<div class="res-foot">' +
      (aksiDaftar ? '<button class="btn btn-primary btn-xl btn-block" id="hDaftar">Daftarkan sidik jari</button>' : '<button class="btn btn-primary btn-xl btn-block" id="hUlang">Coba lagi</button>') +
      '<button class="btn btn-line btn-block" id="hTutup">Tutup</button></div></div>');
    $('hTutup').onclick = function () { $('ovHasil').hidden = true; };
    if ($('hUlang')) $('hUlang').onclick = function () { $('ovHasil').hidden = true; presensi(jenis); };
    if ($('hDaftar')) $('hDaftar').onclick = function () { $('ovHasil').hidden = true; daftarSidikJari(); };
  }

  /* ---------- Sidik jari ---------- */
  function sheet(show, jenis) {
    $('ovBio').hidden = !show;
    if (jenis) $('bioText').textContent = 'Letakkan jari pada sensor untuk melakukan presensi ' + jenis + '.';
  }
  function tutupSheet() { sheet(false); if (bioAbort) { try { bioAbort.abort(); } catch (e) {} bioAbort = null; } }
  $('bioBatal').onclick = function () { tutupSheet(); if (bioResolve) { bioResolve({ batal: true }); bioResolve = null; } };
  if ($('bioOk'))   $('bioOk').onclick   = function () { if (bioResolve) { bioResolve({ biometrik: 'cocok' });  bioResolve = null; } };
  if ($('bioFail')) $('bioFail').onclick = function () { if (bioResolve) { bioResolve({ biometrik: 'gagal' });  bioResolve = null; } };

  function ambilSidikJari(jenis) {
    sheet(true, jenis);
    if (cfg.mode !== 'webauthn') return new Promise(function (res) { bioResolve = res; });

    return new Promise(function (resolve) {
      bioResolve = resolve;
      post({ action: 'wa_challenge' }).then(function (c) {
        if (c.login) { location.href = cfg.api.replace(/api\/presensi\.php$/, 'index.php'); return; }
        if (!c.terdaftar) return resolve({ belum: true });
        bioAbort = new AbortController();
        return navigator.credentials.get({
          publicKey: {
            challenge: unb64u(c.challenge), rpId: c.rpId, timeout: 60000, userVerification: 'required',
            allowCredentials: [{ type: 'public-key', id: unb64u(c.credential) }]
          },
          signal: bioAbort.signal
        }).then(function (cred) {
          var r = cred.response;
          resolve({ assertion: { id: cred.id, authenticatorData: b64u(r.authenticatorData), clientDataJSON: b64u(r.clientDataJSON), signature: b64u(r.signature) } });
        });
      }).catch(function (err) {
        resolve(err && err.name === 'AbortError' ? { batal: true } : { biometrik: 'gagal' });
      });
    });
  }

  /* ---------- Alur presensi ---------- */
  function presensi(jenis) {
    if (busy) return; busy = true;
    var posisi;
    getPosition().then(function (p) {
      posisi = p;
      return ambilSidikJari(jenis);
    }).then(function (b) {
      tutupSheet();
      if (b.batal) { busy = false; return; }
      if (b.belum) { busy = false; return hasilGagal('Sidik jari belum terdaftar pada perangkat ini. Daftarkan sidik jari terlebih dahulu.', jenis, true); }
      var body = { action: 'presensi', jenis: jenis, lat: posisi.coords.latitude, lng: posisi.coords.longitude, accuracy: posisi.coords.accuracy };
      if (b.assertion) body.assertion = b.assertion; else body.biometrik = b.biometrik;
      return post(body).then(function (r) {
        busy = false;
        if (r.login) { location.reload(); return; }
        if (r.ok) hasilOk(r.data); else hasilGagal(r.pesan, jenis, r.kode === 'biometrik_belum');
      });
    }).catch(function (err) {
      busy = false; tutupSheet();
      if (err && typeof err.code === 'number') {
        hasilGagal(err.code === 1 ? 'GPS atau izin lokasi nonaktif. Aktifkan izin lokasi untuk situs ini lalu coba lagi.'
          : 'Lokasi tidak dapat dibaca. Pastikan GPS aktif, lalu coba lagi.', jenis, false);
      } else { hasilGagal('Koneksi ke server gagal. Periksa jaringan Anda lalu coba lagi.', jenis, false); }
    });
  }
  $('btnMasuk').addEventListener('click',  function () { presensi('masuk'); });
  $('btnPulang').addEventListener('click', function () { presensi('pulang'); });

  /* ---------- Pendaftaran sidik jari (mode webauthn) ---------- */
  function daftarSidikJari() {
    var avail = window.PublicKeyCredential && PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable
      ? PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable() : Promise.resolve(false);
    avail.then(function (ok) {
      if (!ok) { hasilGagal('Perangkat ini belum memiliki sidik jari terdaftar. Daftarkan sidik jari di Pengaturan perangkat, lalu coba lagi. Pastikan juga situs dibuka lewat HTTPS.', 'masuk', false); return; }
      return post({ action: 'wa_challenge' }).then(function (c) {
        return navigator.credentials.create({
          publicKey: {
            challenge: unb64u(c.challenge), rp: { name: 'Presensi MA', id: c.rpId },
            user: { id: unb64u(c.user.id), name: c.user.name, displayName: c.user.displayName },
            pubKeyCredParams: [{ type: 'public-key', alg: -7 }, { type: 'public-key', alg: -257 }],
            authenticatorSelection: { authenticatorAttachment: 'platform', userVerification: 'required', residentKey: 'discouraged' },
            attestation: 'none', timeout: 60000
          }
        });
      }).then(function (cred) {
        var r = cred.response;
        if (!r.getPublicKey) throw new Error('browser');
        return post({ action: 'wa_register', id: cred.id, clientDataJSON: b64u(r.clientDataJSON), publicKey: b64u(r.getPublicKey()) });
      }).then(function (r) {
        if (r.ok) location.reload(); else hasilGagal(r.pesan, 'masuk', false);
      });
    }).catch(function (err) {
      var m = err && err.message === 'browser' ? 'Peramban ini belum mendukung pendaftaran sidik jari. Gunakan Chrome versi terbaru di Android.'
        : 'Pendaftaran sidik jari dibatalkan atau gagal. Pastikan perangkat memiliki sidik jari terdaftar.';
      hasilGagal(m, 'masuk', false);
    });
  }
  if ($('btnReg')) $('btnReg').addEventListener('click', daftarSidikJari);
})();
