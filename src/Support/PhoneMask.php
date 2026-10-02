<?php

namespace Bale\Srikandi\Support;

/**
 * Penyamaran nomor WhatsApp untuk jawaban API (spec §5.0b).
 *
 * 🔴 Ini **petunjuk**, bukan data yang boleh diparse scraper.
 *
 * Tujuannya supaya scraper bisa memastikan Bale membalas untuk nomor yang
 * memang milik device `purpose=otp` — tanpa memegang nomornya. Kalau
 * scraper punya nomor penuh, kebocoran state sistem kembali terjadi dan
 * nomor itu bisa basi tanpa ada yang memberi tahu ketika admin mengganti
 * device.
 *
 * Karena itu bentuknya tidak stabil dan tidak boleh di-`explode`:
 * `6281…6416` — empat digit depan, elipsis, empat digit belakang.
 */
final class PhoneMask
{
    /**
     * Jumlah digit yang dipertahankan di setiap ujung.
     */
    protected const EDGE_DIGITS = 4;

    /**
     * Pemisah bagian tengah. Bukan `...` supaya tidak terbaca sebagai
     * penanda numerik yang bisa diparse.
     */
    protected const ELLIPSIS = '…';

    public static function mask(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        $length = strlen($digits);

        // 🔴 Nomor pendek tidak disamarkan sebagian — lebih aman disamarkan
        // penuh. Empat digit depan + empat digit belakang dari nomor 6 digit
        // berarti 8 dari 6 digit, yaitu seluruh nomor.
        if ($length <= self::EDGE_DIGITS * 2) {
            return str_repeat('*', $length);
        }

        return substr($digits, 0, self::EDGE_DIGITS)
            .self::ELLIPSIS
            .substr($digits, -self::EDGE_DIGITS);
    }

    /**
     * True kalau `$masked` masih memuat nomor lengkap `$phone`.
     *
     * Dipakai test untuk membuktikan penyamaran tidak bocor.
     */
    public static function leaksFullNumber(?string $masked, ?string $phone): bool
    {
        if ($masked === null || $phone === null) {
            return false;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $digits !== '' && str_contains(preg_replace('/\D+/', '', $masked) ?? '', $digits);
    }
}
