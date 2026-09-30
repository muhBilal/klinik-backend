<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Autentikasi dua langkah berbasis TOTP (RFC 6238: HMAC-SHA1, 6 digit, periode 30 detik) —
 * kompatibel dengan Google Authenticator, Microsoft Authenticator, Authy, dll.
 * Implementasi sendiri agar tidak menambah dependensi; diuji dengan vektor RFC di tests/Unit.
 */
class TwoFactorService
{
    private const PERIODE = 30;

    private const DIGIT = 6;

    private const ALFABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function buatSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        $issuer = (string) config('eklinik.two_factor.issuer');

        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($user->email).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGIT,
            'period' => self::PERIODE,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function langkahSaatIni(): int
    {
        return intdiv(now()->getTimestamp(), self::PERIODE);
    }

    public function kode(string $secret, int $langkah): string
    {
        $hash = hash_hmac('sha1', pack('N*', 0, $langkah), $this->base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $angka = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($angka % (10 ** self::DIGIT)), self::DIGIT, '0', STR_PAD_LEFT);
    }

    /**
     * Langkah waktu yang cocok (toleransi ±1 langkah untuk selisih jam perangkat), atau null.
     * Langkah ≤ `$setelahLangkah` ditolak agar kode yang sama tidak bisa dipakai ulang.
     */
    public function cocokkan(string $secret, string $kode, ?int $setelahLangkah = null): ?int
    {
        $kode = preg_replace('/\s+/', '', $kode);

        if (! preg_match('/^\d{'.self::DIGIT.'}$/', $kode)) {
            return null;
        }

        $sekarang = $this->langkahSaatIni();

        foreach ([$sekarang - 1, $sekarang, $sekarang + 1] as $langkah) {
            if ($setelahLangkah !== null && $langkah <= $setelahLangkah) {
                continue;
            }

            if (hash_equals($this->kode($secret, $langkah), $kode)) {
                return $langkah;
            }
        }

        return null;
    }

    /** @return list<string> kode pemulihan sekali pakai, format `abcde-fghij` */
    public function buatKodePemulihan(int $jumlah = 8): array
    {
        return array_map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)), range(1, $jumlah));
    }

    /**
     * Verifikasi kode TOTP atau kode pemulihan untuk user yang 2FA-nya aktif. Kode pemulihan hangus setelah dipakai.
     */
    public function verifikasiUser(User $user, string $kode): bool
    {
        if (! $user->twoFactorAktif()) {
            return false;
        }

        $kode = trim($kode);
        $langkah = $this->cocokkan($user->two_factor_secret, $kode, $user->two_factor_last_step);

        if ($langkah !== null) {
            $user->forceFill(['two_factor_last_step' => $langkah])->save();

            return true;
        }

        $kodes = $user->two_factor_recovery_codes ?? [];
        $cari = Str::lower($kode);

        foreach ($kodes as $i => $pemulihan) {
            if (hash_equals($pemulihan, $cari)) {
                unset($kodes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($kodes)])->save();
                app(AuditService::class)->catat('2fa_kode_pemulihan', 'user', $user->id, [
                    'user_id' => $user->id, 'label' => $user->email,
                ]);

                return true;
            }
        }

        return false;
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $hasil = '';
        foreach (str_split($bits, 5) as $potong) {
            $hasil .= self::ALFABET[bindec(str_pad($potong, 5, '0'))];
        }

        return $hasil;
    }

    private function base32Decode(string $teks): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($teks, '='))) as $char) {
            $posisi = strpos(self::ALFABET, $char);
            if ($posisi !== false) {
                $bits .= str_pad(decbin($posisi), 5, '0', STR_PAD_LEFT);
            }
        }

        $hasil = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $hasil .= chr(bindec($byte));
            }
        }

        return $hasil;
    }
}
