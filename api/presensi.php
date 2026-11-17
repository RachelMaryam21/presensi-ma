<?php
/**
 * Endpoint JSON untuk aplikasi mobile (presensi masuk/pulang + pendaftaran sidik jari).
 * Semua validasi dilakukan DI SERVER: jarak (Haversine), sidik jari, presensi ganda, status.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/webauthn.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'pesan' => 'Metode tidak diizinkan.'], 405);
if (!csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) json_out(['ok' => false, 'pesan' => 'Token keamanan tidak valid. Muat ulang halaman.'], 419);

$in  = json_decode(file_get_contents('php://input'), true) ?: [];
$u   = user_fresh();
$dev = $u ? perangkat_sesi() : null;
if (!$u || !$dev) json_out(['ok' => false, 'pesan' => 'Sesi berakhir atau perangkat tidak lagi terdaftar. Silakan masuk kembali.', 'login' => true], 401);

$tolak = fn(string $pesan, string $kode = 'ditolak') => json_out(['ok' => false, 'kode' => $kode, 'pesan' => $pesan]);

switch ($in['action'] ?? '') {

    /* ---- Tantangan WebAuthn (untuk daftar sidik jari / presensi) ---- */
    case 'wa_challenge':
        json_out([
            'ok'         => true,
            'challenge'  => wa_new_challenge(),
            'rpId'       => wa_rp_id(),
            'terdaftar'  => !empty($dev['credential_id']),
            'credential' => $dev['credential_id'],
            'user'       => ['id' => b64u_enc('u' . $u['id']), 'name' => $u['nip'], 'displayName' => $u['nama']],
        ]);

    case 'wa_register':
        if (BIOMETRIK_MODE !== 'webauthn') $tolak('Mode sidik jari asli tidak aktif.');
        [$ok, $msg] = wa_register((int)$dev['id_perangkat'], $in);
        json_out(['ok' => $ok, 'pesan' => $msg]);

    /* ---- Presensi ---- */
    case 'presensi':
        $jenis = $in['jenis'] ?? '';
        if (!in_array($jenis, ['masuk', 'pulang'], true)) $tolak('Jenis presensi tidak dikenal.');

        // 1) GPS / izin lokasi (KF-06)
        $lat = $in['lat'] ?? null; $lng = $in['lng'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lng) || abs($lat) > 90 || abs($lng) > 180) {
            $tolak('GPS atau izin lokasi nonaktif. Aktifkan layanan lokasi lalu coba lagi.', 'gps');
        }
        $lat = (float)$lat; $lng = (float)$lng;

        // 2) Sidik jari (KF-06)
        if (BIOMETRIK_MODE === 'webauthn') {
            if (empty($dev['credential_id'])) {
                $tolak('Sidik jari belum terdaftar pada perangkat ini. Daftarkan sidik jari terlebih dahulu.', 'biometrik_belum');
            }
            if (!wa_verify($dev, $in['assertion'] ?? [])) {
                $tolak('Sidik jari tidak cocok atau gagal dibaca. Silakan verifikasi ulang.', 'biometrik');
            }
        } else {
            if (($in['biometrik'] ?? '') !== 'cocok') {
                $tolak('Sidik jari tidak cocok atau gagal dibaca. Silakan verifikasi ulang.', 'biometrik');
            }
        }

        // 3) Radius lokasi - rumus Haversine (KNF-03)
        $lok = lokasi_aktif();
        if (!$lok) $tolak('Lokasi presensi belum diatur oleh Admin.', 'lokasi');
        $jarak = haversine($lat, $lng, (float)$lok['latitude'], (float)$lok['longitude']);
        if ($jarak > (int)$lok['radius_meter']) {
            $tolak('Posisi Anda di luar radius ' . (int)$lok['radius_meter'] . ' meter (' . round($jarak) . ' m dari titik pusat).', 'radius');
        }

        // 4) Simpan (KF-03/04/05/07)
        $pdo = db();
        $tgl = date('Y-m-d'); $jam = date('H:i:s');
        $koor = number_format($lat, 7, '.', '') . ',' . number_format($lng, 7, '.', '');

        $pdo->beginTransaction();
        try {
            if ($jenis === 'masuk') {
                $status = status_masuk($jam);
                try {
                    $pdo->prepare('INSERT INTO presensi (id_pengguna,id_lokasi,tanggal,jam_masuk,koordinat_masuk,jarak_masuk,status) VALUES (?,?,?,?,?,?,?)')
                        ->execute([$u['id'], $lok['id_lokasi'], $tgl, $jam, $koor, round($jarak, 1), $status]);
                } catch (PDOException $ex) {
                    $pdo->rollBack();
                    if ($ex->getCode() === '23000') $tolak('Presensi masuk sudah tercatat hari ini. Presensi ganda ditolak.', 'ganda');
                    throw $ex;
                }
            } else {
                $st = $pdo->prepare('SELECT * FROM presensi WHERE id_pengguna = ? AND tanggal = ? FOR UPDATE');
                $st->execute([$u['id'], $tgl]);
                $row = $st->fetch();
                if (!$row || !$row['jam_masuk']) { $pdo->rollBack(); $tolak('Presensi pulang hanya dapat dilakukan setelah presensi masuk.', 'urutan'); }
                if ($row['jam_pulang'])           { $pdo->rollBack(); $tolak('Presensi pulang sudah tercatat hari ini. Presensi ganda ditolak.', 'ganda'); }
                $status = status_setelah_pulang($row['status'], $jam);
                $pdo->prepare('UPDATE presensi SET jam_pulang=?, koordinat_pulang=?, jarak_pulang=?, status=? WHERE id_presensi=?')
                    ->execute([$jam, $koor, round($jarak, 1), $status, $row['id_presensi']]);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('presensi: ' . $ex->getMessage());
            json_out(['ok' => false, 'pesan' => 'Terjadi kesalahan server. Coba lagi.'], 500);
        }

        json_out(['ok' => true, 'data' => [
            'jenis'  => $jenis,
            'waktu'  => fmt_jam($jam) . ' WIB',
            'status' => NAMA_STATUS[$status],
            'kelas'  => $status === 'tepat_waktu' ? 'ok' : 'warn',
            'jarak'  => round($jarak) . ' m',
            'lokasi' => $lok['nama_lokasi'],
        ]]);

    default:
        json_out(['ok' => false, 'pesan' => 'Aksi tidak dikenal.'], 400);
}
