<?php
/**
 * Verifikasi sidik jari lewat WebAuthn (API biometrik bawaan perangkat).
 * Server TIDAK pernah menerima data sidik jari; hanya bukti tanda tangan
 * kriptografis bahwa sensor perangkat memverifikasi pemilik (flag UV).
 * Syarat: HTTPS (atau http://localhost).
 */

function b64u_enc(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true) ?: ''; }

function wa_rp_id(): string {
    if (APP_ORIGIN !== '') return parse_url(APP_ORIGIN, PHP_URL_HOST);
    return preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
}
function wa_origin(): string {
    if (APP_ORIGIN !== '') return rtrim(APP_ORIGIN, '/');
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function wa_new_challenge(): string {
    $c = random_bytes(32);
    $_SESSION['wa_challenge'] = ['v' => b64u_enc($c), 't' => time()];
    return b64u_enc($c);
}

/** Cek clientDataJSON: tipe, challenge (sekali pakai, maks 2 menit), origin. */
function wa_check_client_data(string $clientDataB64, string $type): ?string {
    $raw = b64u_dec($clientDataB64);
    $cd  = json_decode($raw, true);
    $ch  = $_SESSION['wa_challenge'] ?? null;
    unset($_SESSION['wa_challenge']);               // sekali pakai
    if (!is_array($cd) || !$ch || time() - $ch['t'] > 120) return null;
    if (($cd['type'] ?? '') !== $type) return null;
    if (!hash_equals($ch['v'], (string)($cd['challenge'] ?? ''))) return null;
    if (($cd['origin'] ?? '') !== wa_origin()) return null;
    return $raw;
}

/** Simpan kunci publik hasil pendaftaran sidik jari pada perangkat. */
function wa_register(int $idPerangkat, array $in): array {
    if (!wa_check_client_data($in['clientDataJSON'] ?? '', 'webauthn.create')) {
        return [false, 'Pendaftaran sidik jari tidak valid atau kedaluwarsa. Coba lagi.'];
    }
    $der = b64u_dec($in['publicKey'] ?? '');
    $id  = (string)($in['id'] ?? '');
    if ($der === '' || $id === '') return [false, 'Data sidik jari dari perangkat tidak lengkap.'];
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    if (!openssl_pkey_get_public($pem)) return [false, 'Kunci publik perangkat tidak dapat dibaca.'];
    db()->prepare('UPDATE perangkat SET credential_id = ?, public_key = ? WHERE id_perangkat = ?')
        ->execute([$id, $pem, $idPerangkat]);
    return [true, 'Sidik jari perangkat berhasil didaftarkan.'];
}

/** Verifikasi assertion (tanda tangan sensor sidik jari). */
function wa_verify(array $perangkat, array $in): bool {
    if (empty($perangkat['public_key']) || empty($perangkat['credential_id'])) return false;
    if (!hash_equals($perangkat['credential_id'], (string)($in['id'] ?? ''))) return false;

    $cdRaw = wa_check_client_data($in['clientDataJSON'] ?? '', 'webauthn.get');
    if ($cdRaw === null) return false;

    $auth = b64u_dec($in['authenticatorData'] ?? '');
    $sig  = b64u_dec($in['signature'] ?? '');
    if (strlen($auth) < 37 || $sig === '') return false;

    if (!hash_equals(hash('sha256', wa_rp_id(), true), substr($auth, 0, 32))) return false;
    $flags = ord($auth[32]);
    if (!($flags & 0x01) || !($flags & 0x04)) return false;      // User Present + User Verified (sidik jari)

    $ok = openssl_verify($auth . hash('sha256', $cdRaw, true), $sig, $perangkat['public_key'], OPENSSL_ALGO_SHA256);
    return $ok === 1;
}
