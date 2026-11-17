<?php
/**
 * Konfigurasi Sistem Presensi MA Nurul Jadid
 * Sesuaikan nilai di bawah ini dengan server Anda.
 */

// --- Database MySQL / MariaDB ---
define('DB_HOST', getenv('DB_HOST') !== false ? getenv('DB_HOST') : '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') !== false ? getenv('DB_NAME') : 'presensi_ma');
define('DB_USER', getenv('DB_USER') !== false ? getenv('DB_USER') : 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');

// --- Aturan presensi (Bab 1.5 & KF-05) ---
define('JAM_MASUK',     '06:30:00');   // jam kedatangan
define('TOLERANSI_MNT', 30);           // toleransi keterlambatan (menit) -> batas 07:00
define('JAM_PULANG',    '15:00:00');   // pulang sebelum jam ini = "pulang awal"
define('HARI_KERJA',    [1, 2, 3, 4, 5]); // 1=Senin ... 7=Minggu
// Tanggal libur (format Y-m-d) yang tidak dihitung sebagai hari kerja di rekapitulasi
define('HARI_LIBUR',    []);           // contoh: ['2026-11-20']

// --- Biometrik ---
// 'webauthn'  : sidik jari asli perangkat (WebAuthn). WAJIB HTTPS (atau localhost).
// 'simulasi'  : tombol simulasi sidik jari, hanya untuk demo/pengujian tanpa HTTPS.
define('BIOMETRIK_MODE', 'simulasi');

// --- Umum ---
define('APP_NAME',  'Presensi MA');
define('APP_SCHOOL','MA Nurul Jadid');
define('APP_TZ',    'Asia/Jakarta');   // WIB
define('MIN_PASSWORD', 8);

// Kosongkan agar terdeteksi otomatis. Isi jika aplikasi berada di balik proxy,
// contoh: 'https://presensi.sekolah.sch.id'
define('APP_ORIGIN', '');

// Path URL aplikasi (otomatis). Contoh hasil: '' atau '/presensi-ma'
$__docroot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ''), '/');
$__root    = str_replace('\\', '/', __DIR__);
define('BASE_URL', ($__docroot !== '' && strpos($__root, $__docroot) === 0) ? substr($__root, strlen($__docroot)) : '');
