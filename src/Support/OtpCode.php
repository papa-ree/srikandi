<?php

namespace Bale\Srikandi\Support;

use Illuminate\Support\Facades\Hash;

/**
 * Pembuatan & pencocokan kode OTP (spec §5.1, §6.1).
 *
 * Kode OTP adalah satu-satunya rahasia berumur pendek di Bale: ia dikirim
 * lewat WhatsApp dalam bentuk polos. Karena itu bentuk polosnya TIDAK PERNAH
 * disimpan — hanya hash-nya.
 */
final class OtpCode
{
    /**
     * Kode acak sepanjang `$length`, hanya angka.
     *
     * `random_int` dipakai, bukan `rand`/`mt_rand`: prediktabilitas di sini
     * berarti siapa pun bisa menebak OTP orang lain.
     */
    public static function generate(int $length): string
    {
        $length = max(4, $length);

        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= (string) random_int(0, 9);
        }

        return $code;
    }

    public static function hash(string $code): string
    {
        return Hash::make(self::normalize($code));
    }

    /**
     * Cocokkan kode kiriman dengan hash yang tersimpan.
     *
     * Normalisasi dilakukan SEBELUM perbandingan (spec §6.1): operator sering
     * mengetik `123 456` atau `123-456`, dan itu tetap kode yang sama.
     */
    public static function verify(string $candidate, string $hash): bool
    {
        return Hash::check(self::normalize($candidate), $hash);
    }

    /**
     * Buang semua yang bukan digit.
     *
     * Spasi, tanda hubung, dan karakter pemisah lain dibuang seluruhnya, bukan
     * diganti spasi — supaya `12-3 456` tetap cocok dengan `123456`.
     */
    public static function normalize(string $code): string
    {
        return preg_replace('/\D+/', '', $code) ?? '';
    }

    /**
     * Berapa digit kode setelah dibersihkan.
     *
     * Dipakai untuk menolak kiriman yang jelas bukan OTP (mis. "ok", "terima
     * kasih") tanpa harus menyentuh hash.
     */
    public static function digitCount(string $code): int
    {
        return strlen(self::normalize($code));
    }
}
