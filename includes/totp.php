<?php
/**
 * Verificação em duas etapas (TOTP — RFC 6238)
 * Funciona com Google Authenticator, Microsoft Authenticator, Authy, 2FAS, etc.
 */
function base32_encode(string $bin): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = ''; foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = ''; foreach (str_split($bits, 5) as $chunk) $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}
function base32_decode(string $b32): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = ''; foreach (str_split($b32) as $c) { $p = strpos($alpha, $c); if ($p !== false) $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT); }
    $out = ''; foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}
function totp_new_secret(): string { return base32_encode(random_bytes(20)); }

function totp_code(string $secret, int $step, int $digits = 6): string {
    $hash = hash_hmac('sha1', pack('N*', 0) . pack('N*', $step), base32_decode($secret), true);
    $o = ord($hash[19]) & 0xf;
    $num = ((ord($hash[$o]) & 0x7f) << 24) | ((ord($hash[$o + 1]) & 0xff) << 16) | ((ord($hash[$o + 2]) & 0xff) << 8) | (ord($hash[$o + 3]) & 0xff);
    return str_pad((string)($num % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/** Confere o código (aceita 30s antes/depois por diferença de relógio). Retorna o passo usado ou null. */
function totp_verify(string $secret, string $code, int $lastStep = 0): ?int {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6 || $secret === '') return null;
    $now = intdiv(time(), 30);
    foreach ([0, -1, 1] as $d) {
        $s = $now + $d;
        if ($s <= $lastStep) continue;            // impede reutilizar o mesmo código
        if (hash_equals(totp_code($secret, $s), $code)) return $s;
    }
    return null;
}

function totp_uri(string $secret, string $account): string {
    $issuer = site_name();
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
}

/** 8 códigos de recuperação (para quando perder o celular). Retorna [mostrar, guardar(hash)]. */
function recovery_codes_new(): array {
    $plain = []; $hashed = [];
    for ($i = 0; $i < 8; $i++) {
        $c = strtoupper(bin2hex(random_bytes(3))) . '-' . strtoupper(bin2hex(random_bytes(3)));
        $plain[] = $c; $hashed[] = password_hash($c, PASSWORD_DEFAULT);
    }
    return [$plain, json_encode($hashed)];
}
/** Usa um código de recuperação. Retorna o novo JSON (sem o código usado) ou null se inválido. */
function recovery_code_use(?string $json, string $code): ?string {
    $list = json_decode((string)$json, true) ?: [];
    $code = strtoupper(trim($code));
    foreach ($list as $i => $h) {
        if (password_verify($code, $h)) { unset($list[$i]); return json_encode(array_values($list)); }
    }
    return null;
}
