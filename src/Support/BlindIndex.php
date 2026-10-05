<?php

namespace Bale\Srikandi\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * Blind index untuk kolom yang terenkripsi tapi harus bisa dicari.
 *
 * 🔴 KENAPA BUKAN `hash()` atau `sha256()`
 *
 * Nomor telepon adalah ruang tebak yang kecil. Indonesia punya sekitar 300
 * juta nomor aktif; HMAC-SHA256 maupun SHA-256 biasa, penyerang yang punya dump
 * `srikandi_clients` bisa mencoba semua nomor dan mencocokkan hash-nya dalam
 * hitungan menit di satu laptop.
 *
 * HMAC itu berbeda: tanpa kunci, dump itu tidak bisa dipakai apa pun.
 * Yang memegangnya saja sudah tidak cukup -- harus punya kunci juga.
 *
 * 🔴 KUNCI HARUS BERBEDA DARI KUNCI ENKRIPSI
 *
 * Kalau `SRIKANDI_INDEX_KEY` sama dengan `APP_KEY`, penyerang yang bisa
 * menghitung HMAC (karena punya APP_KEY dari file `.env` yang bocor, atau dari
 * instance yang dikompromikan) juga bisa mencoba mendekripsi kolomnya. Dua
 * mekanisme yang melindungi data jadi satu titik kegagalan.
 *
 * Karena itu `indexKey()` TIDAK pernah fallback ke `APP_KEY`. Kalau env-nya
 * lupa diisi, indeks jadi tidak bisa dihitung dan error-nya jelas -- lebih baik
 * halaman error daripada diam-diam memakai kunci yang sama.
 */
class BlindIndex
{
    /**
     * Hitung blind index untuk satu nilai.
     *
     * Mengembalikan string kosong kalau input kosong: kolom `*_index` dibuat
     * nullable, dan indeks untuk `null` tidak boleh menabrak indeks untuk
     * string kosong -- kalau begitu, semua client tanpa nomor akan saling
     * dianggap nomor yang sama saat dicari.
     */
    public function make(?string $value): string
    {
        $normalized = $this->normalize($value);

        if ($normalized === '') {
            return '';
        }

        return hash_hmac('sha256', $normalized, $this->indexKey());
    }

    /**
     * Bandingkan nilai biasa dengan nilai terenkripsi lewat blind index.
     *
     * Dipakai form edit: yang di bandingkan bukan `phone` (karena tidak bisa
     * dibaca), tapi `phone_index` yang tersimpan.
     */
    public function matches(?string $value, ?string $storedIndex): bool
    {
        if ($storedIndex === null || $storedIndex === '') {
            return false;
        }

        return hash_equals($storedIndex, $this->make($value));
    }

    /**
     * 🔴 Normalisasi WAJIB sama dengan yang dipakai `PhoneNumber::toInternational()`.
     *
     * Kalau tidak, `+62 812-3456` dan `628123456` menghasilkan index berbeda --
     * padahal itu nomor yang sama. Efeknya bukan error, yang lebih buruk:
     * UI menampilkan nomor yang tersimpan, user mengedit field lain, dan nomor
     * itu terisi ulang dengan bentuk berbeda sehingga index-nya berubah. Cari
     * berdasarkan nomor pun gagal tanpa ada yang memberitahu.
     */
    protected function normalize(?string $value): string
    {
        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            return '';
        }

        // Nomor HP Indonesia: hanya digit setelah normalisasi awalan.
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($digits === '') {
            return '';
        }

        $localPrefix = (string) Config::get('wara.phone_format.local_prefix_to_international', '0');
        $international = (string) Config::get('wara.phone_format.international_prefix', '62');

        if ($international !== '' && Str::startsWith($digits, $international)) {
            return $digits;
        }

        if ($localPrefix !== '' && Str::startsWith($digits, $localPrefix)) {
            return $international.substr($digits, strlen($localPrefix));
        }

        return $digits;
    }

    /**
     * Kunci HMAC untuk blind index.
     *
     * 🔴 TIDAK ada fallback ke `APP_KEY` -- sengaja. Lihat docblock class.
     */
    protected function indexKey(): string
    {
        $key = (string) Config::get('srikandi.index_key', '');

        if (trim($key) === '') {
            throw new \RuntimeException(
                'SRIKANDI_INDEX_KEY belum diisi. Blind index tidak boleh memakai APP_KEY: '
                .'kunci yang sama untuk enkripsi dan untuk index berarti dump tabel '
                .'srikandi_clients cukup untuk MENDEKRIPSI kolom terenkripsi.'
            );
        }

        return $key;
    }
}
