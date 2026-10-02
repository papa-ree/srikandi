<?php

namespace Bale\Srikandi\Support;

/**
 * Penurunan `tahun` naskah dari tanggal (spec §3.2).
 *
 * ⚠️ Jebakan yang sudah terverifikasi: `tahun` diambil dari `tanggal`, BUKAN
 * dari nomor naskah. Nomor naskah tidak andal berakhiran tahun.
 *
 * ⚠️ Nilai kembali untuk tanggal tidak terbaca adalah `0`, bukan `NULL` — kolom
 * ini bagian dari unique key, dan NULL menggagalkan dedup di MySQL secara
 * diam-diam.
 */
final class NaskahYear
{
    /**
     * @param  mixed  $tanggal  nilai mentah dari payload Srikandi
     */
    public static function from(mixed $tanggal): int
    {
        $fallback = (int) config('srikandi.naskah.unknown_year', 0);

        if (! is_string($tanggal)) {
            return $fallback;
        }

        $tanggal = trim($tanggal);

        if ($tanggal === '') {
            return $fallback;
        }

        // Sumbernya `tanggal` yang bentuknya `YYYY-MM-DD`. Ambil 4 digit
        // pertama, tapi tetap validasi supaya "2026-09-25" yang benar-benar
        // tanggal dan "2026" yang tidak bisa diurai tidak tercampur.
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tanggal, $m) !== 1) {
            return $fallback;
        }

        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        if (! checkdate($month, $day, $year)) {
            return $fallback;
        }

        return $year;
    }

    /**
     * Tanggal naskah yang sudah dibersihkan, atau NULL bila tidak terbaca.
     *
     * Dipisahkan dari {@see from()} karena kolom `tanggal_naskah` memang boleh
     * NULL — hanya `tahun` yang tidak boleh.
     */
    public static function normalizeDate(mixed $tanggal): ?string
    {
        if (! is_string($tanggal)) {
            return null;
        }

        $tanggal = trim($tanggal);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tanggal, $m) !== 1) {
            return null;
        }

        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
