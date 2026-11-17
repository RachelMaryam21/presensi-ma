<?php
/* Sesi & hak akses berbasis peran (KF-13) */

function user(): ?array { return $_SESSION['user'] ?? null; }

function login_session(array $u, ?string $deviceId): void {
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'    => (int)$u['id_pengguna'],
        'nip'   => $u['nip'],
        'nama'  => $u['nama'],
        'peran' => $u['peran'],
    ];
    $_SESSION['device_id'] = $deviceId;   // null untuk login web panel
    csrf_token();
}

function logout_session(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Pastikan akun masih ada & aktif (admin bisa menonaktifkan kapan saja). */
function user_fresh(): ?array {
    $u = user();
    if (!$u) return null;
    $st = db()->prepare('SELECT id_pengguna, nip, nama, peran, aktif FROM pengguna WHERE id_pengguna = ?');
    $st->execute([$u['id']]);
    $row = $st->fetch();
    if (!$row || !$row['aktif']) { logout_session(); return null; }
    $_SESSION['user']['peran'] = $row['peran'];
    $_SESSION['user']['nama']  = $row['nama'];
    return $_SESSION['user'];
}

/** Perangkat aktif milik sesi mobile ini (pengikatan akun-perangkat, KF-02). */
function perangkat_sesi(): ?array {
    $u = user();
    if (!$u || empty($_SESSION['device_id'])) return null;
    $st = db()->prepare("SELECT * FROM perangkat WHERE id_pengguna = ? AND identitas_perangkat = ? AND status_persetujuan = 'aktif'");
    $st->execute([$u['id'], $_SESSION['device_id']]);
    return $st->fetch() ?: null;
}

function require_mobile_login(): array {
    $u = user_fresh();
    if (!$u || !perangkat_sesi()) {
        if ($u) logout_session();
        redirect('index.php');
    }
    return $u;
}

/** Halaman web panel: hanya peran tertentu. */
function require_role(array $roles): array {
    $u = user_fresh();
    if (!$u) redirect('admin/login.php');
    if (!in_array($u['peran'], $roles, true)) {
        http_response_code(403);
        exit('<!doctype html><meta charset="utf-8"><title>403</title><body style="font-family:sans-serif;padding:40px">'
           . '<h2>Akses ditolak</h2><p>Peran Anda tidak memiliki hak untuk membuka halaman ini.</p>'
           . '<p><a href="' . e(url('admin/')) . '">Kembali</a></p>');
    }
    return $u;
}

function is_panel_role(string $peran): bool { return in_array($peran, ['admin', 'kepala'], true); }
