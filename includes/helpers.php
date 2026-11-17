<?php
const NAMA_PERAN = [
    'guru'   => 'Guru',
    'tendik' => 'Tenaga Kependidikan',
    'admin'  => 'Admin Tata Usaha',
    'kepala' => 'Kepala Madrasah',
];
const NAMA_STATUS = [
    'tepat_waktu' => 'Tepat waktu',
    'terlambat'   => 'Terlambat',
    'pulang_awal' => 'Pulang awal',
];
const NAMA_HARI  = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
const HARI_PEND  = ['Min','Sen','Sel','Rab','Kam','Jum','Sab'];
const NAMA_BULAN = ['', 'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
const BULAN_PEND = ['', 'Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function url(string $path = ''): string { return BASE_URL . '/' . ltrim($path, '/'); }

function redirect(string $path): never {
    header('Location: ' . url($path));
    exit;
}

/* ---------- CSRF ---------- */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}
function csrf_valid(?string $t): bool {
    return !empty($_SESSION['csrf']) && is_string($t) && hash_equals($_SESSION['csrf'], $t);
}
function csrf_check_post(): void {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        http_response_code(419);
        exit('Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.');
    }
}

/* ---------- Flash message ---------- */
function flash(string $type, string $msg): void { $_SESSION['flash'] = [$type, $msg]; }
function flash_show(): string {
    if (empty($_SESSION['flash'])) return '';
    [$t, $m] = $_SESSION['flash']; unset($_SESSION['flash']);
    return '<div class="alert alert-' . e($t) . '">' . e($m) . '</div>';
}

/* ---------- Format tanggal & jam (gaya wireframe: 06.42) ---------- */
function fmt_jam(?string $t): string { return $t ? str_replace(':', '.', substr($t, 0, 5)) : '–'; }
function fmt_tgl(string $d, bool $pendek = true): string {
    $ts = strtotime($d);
    return $pendek
        ? date('j', $ts) . ' ' . BULAN_PEND[(int)date('n', $ts)] . ' ' . date('Y', $ts)
        : date('j', $ts) . ' ' . NAMA_BULAN[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}
function fmt_tgl_hari(string $d): string {   // "Sen, 16 Nov"
    $ts = strtotime($d);
    return HARI_PEND[(int)date('w', $ts)] . ', ' . date('j', $ts) . ' ' . BULAN_PEND[(int)date('n', $ts)];
}
function nama_bulan_tahun(string $ym): string {  // "2026-11" -> "November 2026"
    [$y, $m] = explode('-', $ym);
    return NAMA_BULAN[(int)$m] . ' ' . $y;
}

/* ---------- Haversine (Persamaan 2.1) -> meter ---------- */
function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371000.0;
    $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
    $dp = deg2rad($lat2 - $lat1); $dl = deg2rad($lng2 - $lng1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return 2 * $R * asin(min(1.0, sqrt($a)));
}

/* ---------- Status kehadiran (KF-05) ---------- */
function batas_terlambat(): string {
    return date('H:i:s', strtotime(JAM_MASUK) + TOLERANSI_MNT * 60);
}
function status_masuk(string $jam): string {
    return $jam > batas_terlambat() ? 'terlambat' : 'tepat_waktu';
}
function status_setelah_pulang(string $statusMasuk, string $jamPulang): string {
    if ($statusMasuk === 'terlambat') return 'terlambat';
    return $jamPulang < JAM_PULANG ? 'pulang_awal' : 'tepat_waktu';
}
function badge_status(string $s): string {
    $cls = ['tepat_waktu' => 'ok', 'terlambat' => 'warn', 'pulang_awal' => 'info'][$s] ?? 'muted';
    return '<span class="badge badge-' . $cls . '">' . e(NAMA_STATUS[$s] ?? $s) . '</span>';
}

/* ---------- Lokasi aktif ---------- */
function lokasi_aktif(): ?array {
    return db()->query('SELECT * FROM lokasi WHERE aktif = 1 ORDER BY id_lokasi DESC LIMIT 1')->fetch() ?: null;
}

/* ---------- Hari kerja untuk rekapitulasi ---------- */
function daftar_hari_kerja(string $ym): array {
    $awal = strtotime($ym . '-01');
    $akhir = strtotime(date('Y-m-t', $awal));
    // Hari yang belum "selesai" tidak dihitung (hari ini dihitung setelah jam pulang)
    $batas = strtotime(date('Y-m-d'));
    if (date('H:i:s') < JAM_PULANG) $batas = strtotime('-1 day', $batas);
    $out = [];
    for ($t = $awal; $t <= $akhir && $t <= $batas; $t = strtotime('+1 day', $t)) {
        $d = date('Y-m-d', $t);
        if (in_array((int)date('N', $t), HARI_KERJA, true) && !in_array($d, HARI_LIBUR, true)) $out[] = $d;
    }
    return $out;
}

/* ---------- Nama perangkat dari User-Agent (perkiraan) ---------- */
function nama_perangkat_dari_ua(string $ua): string {
    if (preg_match('/Android[^;)]*;\s*([^;)]+?)(?:\s+Build|\))/i', $ua, $m)) {
        $n = trim($m[1]);
        if ($n !== '' && stripos($n, 'wv') === false) return mb_substr($n, 0, 60);
    }
    if (stripos($ua, 'iPhone') !== false) return 'iPhone';
    if (stripos($ua, 'Windows') !== false) return 'Komputer Windows';
    if (stripos($ua, 'Macintosh') !== false) return 'Mac';
    if (stripos($ua, 'Android') !== false) return 'Perangkat Android';
    return 'Perangkat lain';
}

function json_out(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}


/* ---------- Ikon SVG (garis, mengikuti warna teks) ---------- */
function icon(string $n, int $size = 20): string {
    static $p = [
        'fingerprint' => '<path d="M12 11c0 3-1 6-2.5 8.5"/><path d="M8.6 15.2c.5-1.1.7-2.5.7-4.2a2.7 2.7 0 0 1 5.4 0c0 2.5-.4 4.8-1.4 6.9"/><path d="M5.6 8.6A7 7 0 0 1 12 4.5a7 7 0 0 1 7 7c0 1.5-.1 2.9-.4 4.3"/><path d="M5 12c0 2-.3 3.6-.8 5"/><path d="M16 14.6c-.2 1.7-.6 3.2-1.2 4.7"/>',
        'history'     => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'users'       => '<circle cx="9" cy="8" r="3.4"/><path d="M2.8 19.5c0-3.4 2.8-5.6 6.2-5.6s6.2 2.2 6.2 5.6"/><circle cx="17.3" cy="9" r="2.4"/><path d="M17 14c2.5.2 4.2 1.9 4.2 4.6"/>',
        'pin'         => '<path d="M12 21s6.8-5.9 6.8-11.2a6.8 6.8 0 0 0-13.6 0C5.2 15.1 12 21 12 21z"/><circle cx="12" cy="9.8" r="2.4"/>',
        'edit'        => '<path d="M4.5 19.5h3.8L19 8.8 15.2 5 4.5 15.7z"/><path d="M13.3 6.9l3.8 3.8"/>',
        'chart'       => '<path d="M4 4v16h16"/><rect x="7.5" y="11" width="3" height="6" rx=".7"/><rect x="13" y="7" width="3" height="10" rx=".7"/>',
        'logout'      => '<path d="M9.5 4.5H6a1.5 1.5 0 0 0-1.5 1.5v12A1.5 1.5 0 0 0 6 19.5h3.5"/><path d="M15.5 8l4 4-4 4"/><path d="M19.5 12h-10"/>',
        'login'       => '<path d="M14.5 4.5H18A1.5 1.5 0 0 1 19.5 6v12a1.5 1.5 0 0 1-1.5 1.5h-3.5"/><path d="M9.5 8l-4 4 4 4"/><path d="M5.5 12h10"/>',
        'clock'       => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'alert'       => '<path d="M12 4.2l8.4 14.6H3.6z"/><path d="M12 10v4"/><path d="M12 16.9v.1"/>',
        'download'    => '<path d="M12 4.5v10"/><path d="M8 11l4 4 4-4"/><path d="M5 19.5h14"/>',
        'plus'        => '<path d="M12 5v14M5 12h14"/>',
        'search'      => '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4 4"/>',
        'shield'      => '<path d="M12 3.8l7 2.6v5.2c0 4.3-3 7.4-7 8.9-4-1.5-7-4.6-7-8.9V6.4z"/><path d="M9 12l2.2 2.2L15.2 10"/>',
        'device'      => '<rect x="7" y="3.5" width="10" height="17" rx="2.6"/><path d="M11 17.5h2"/>',
        'percent'     => '<path d="M18 6L6 18"/><circle cx="7.5" cy="7.5" r="2.3"/><circle cx="16.5" cy="16.5" r="2.3"/>',
    ];
    return '<svg class="i" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$n] ?? '') . '</svg>';
}
