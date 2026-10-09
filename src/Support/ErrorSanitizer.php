<?php

namespace Bale\Srikandi\Support;

/**
 * Sanitasi teks yang datang dari scraper sebelum disimpan (S3.2).
 *
 * 🔴 MASALAH YANG DISOLUSIKAN KELAS INI
 *
 * `error_message` heartbeat ditulis apa adanya ke `srikandi_clients`. Kalau
 * scraper melaporkan error Playwright, pesan itu bisa memuat kredensial:
 *
 *     "net::ERR_ABORTED at https://srikandi.go.id/login?user=admin&password=Rahasia123"
 *
 * `last_error_message` tidak terenkripsi dan dibaca dari halaman UI. Jadi satu
 * pesan error itu cukup untuk menuliskan password Srikandi ke database yang
 * tidak terenkripsi, lalu menampilkannya di layar.
 *
 * 🔴 MENGAPA TIDAK CUKUP MENYENSOR DI TEMPAT SAJA
 *
 * Sanitasi hanya berarti "kita tidak menyimpan pola yang dikenali". Kalau
 * kredensial muncul dalam bentuk lain — base64, ter-URL-encode, atau terpecah
 * di beberapa baris — penyensoran regex tidak akan menangkapnya.
 *
 * Jadi kelas ini mengakui dua hal yang berbeda:
 *
 * 1. **Sanitasi** — best-effort, mengurangi kebocoran yang berbentuk jelas.
 * 2. **Batas kepercayaan** — pemanggil harus tahu bahwa (1) bukan jaminan.
 *
 * Sanitasi tidak pernah dianggap sebagai pengganti "jangan pernah mengirim
 * kredensial di pesan error". Itu aturan untuk sisi scraper, bukan sesuatu yang
 * bisa diperbaiki di sini setelah faktanya sudah sampai.
 *
 * 🔴 APA YANG TIDAK DISANITASI, DAN KENAPA
 *
 * Teks biasa ("Password salah", "token kedaluwarsa") tidak disensor. Sanitasi
 * yang terlalu agresif membuat pesan error tidak berguna: operator harus bisa
 * membaca apa yang sebenarnya terjadi. Yang disensor hanya pola yang jelas
 * memuat nilai kredensial.
 *
 * 🔴 KELAS INI BUKAN PENJAGA. Dia lapisan kedua.
 *
 * Ada test di file test yang secara sengaja membuktikan kasus *tidak* bisa
 * ditutup oleh regex. Kalau suatu saat ada yang mengubah kelas ini jadi
 * "sanitasi melindungi kredensial" dan menghapus aturan di sisi scraper,
 * test pengakuan itulah yang lebih dulu memberi tahu.
 */
class ErrorSanitizer
{
    /**
     * Pola penyensoran, berurutan.
     *
     * 🔴 URUTAN DAN BENTUK DI SINI BERDASAR BUG NYATA, bukan tebakan.
     *
     * Tiga bug ditemukan lewat probe langsung ke kelas ini. Semuanya punya
     * gejala yang sama: tidak ada error, sanitasi "tampak berjalan", dan nilai
     * aslinya tetap ada di output. Test mengunci ketiganya.
     *
     * 1. Pola `:` harus dicek SEBELUM pola `=`.
     *
     *    Kalau `=` lebih dulu, pada `{"password":"Rahasia"}` regex `=` tidak
     *    match, lalu regex `:` match dengan nilai `"Rahasia"`. Karena
     *    `[REDACTED]` adalah teks biasa dan bukan string ber-quote, hasilnya
     *    JSON rusak DAN nilai aslinya masih ada di dalam output.
     *
     * 2. `"?` di antara nama field dan `:` itu WAJIB ada.
     *
     *    Di JSON nama field diapit tanda kutip: `"password": "Rahasia"`. Kalau
     *    antara `password` dan `:` hanya boleh whitespace, seluruh JSON lolos
     *    tanpa tersentuh. Gejalanya bukan error — pola `=` masih match di pesan
     *    lain, jadi sanitasi "tampak berjalan".
     *
     * 3. Cuma SATU pola untuk `:`.
     *
     *    Versi pertama punya dua: "berkutip" dan "tanpa kutip". Yang kedua
     *    keliru. Golongan terakhir pola pertama sudah `[^\s,;})]+`, yang match
     *    nilai tanpa tanda kutip juga, jadi pola kedua tidak menambah cakupan
     *    apa pun — dia hanya match ulang hasil pola pertama, lalu merusaknya.
     *
     *    Setelah pola pertama mengubah `{"password":"Rahasia"}` menjadi
     *    `{"password":"[REDACTED]"}`, pola kedua ikut match `password":"` lalu
     *    menelan `[REDACTED]` BESERTAN tanda kutip penutupnya, karena `"` bukan
     *    batas bagi `[^\s,;})]+`. Hasilnya `{"password":[REDACTED]}`: nilai
     *    hilang, JSON tidak bisa di-parse, dan tidak ada apa pun yang gagal.
     *
     * 🔴 `key` sengaja TIDAK ada di daftar. Terlalu umum: `key: not found`
     * akan ikut kena, dan penyensoran yang terlalu rakus membuat pesan error
     * tidak berguna. `api_key` dan `api-key` sudah cukup menutup kebutuhan
     * nyata.
     *
     * 1. Key-value: JSON, Python dict, YAML, dan bentuk tanpa tanda kutip
     */
    protected const PATTERNS = [
        '/((?:password|passwd|pwd|secret|token|api[_-]?key|auth|bearer)\s*"?\s*:\s*)'
            .'("[^"]*"|\'[^\']*\'|[^\s,;})]+)/i',
        // 2. Query string URL: ?password=Rahasia123&user=admin
        '/((?:password|passwd|pwd|secret|token|api[_-]?key|auth|bearer)\s*=\s*)[^\s&"\'<>]+/i',
        // 3. Header: Authorization: Bearer abc123
        '/(authorization\s*:\s*bearer\s+)[^\s]+/i',
    ];

    /**
     * Penanda pengganti. Harus jelas bahwa ini BUKAN nilai asli.
     *
     * `[REDACTED]` lebih baik daripada `***`: kalau operator melihat `***` dia
     * mungkin menduga nilainya masih ada di tempat lain. `[REDACTED]` langsung
     * memberi tahu nilainya tidak disimpan di sini sama sekali.
     */
    protected const REDACTED = '[REDACTED]';

    /**
     * Sanitasi satu pesan error.
     */
    public function sanitize(?string $message): ?string
    {
        if ($message === null || trim($message) === '') {
            return $message;
        }

        $out = $message;

        foreach (static::PATTERNS as $pattern) {
            $out = preg_replace_callback(
                $pattern,
                fn (array $matches) => self::replaceValue($matches),
                $out
            ) ?? $out;
        }

        return $out;
    }

    /**
     * Susun hasil penggantian, MEMERTAHANKAN tanda kutip nilai.
     *
     * 🔴 Tanpa ini, test "menyensor password di JSON" tetap hijau sementara
     * JSON-nya rusak. Dan JSON rusak tanpa error adalah kondisi paling buruk:
     * pemanggil mengira dia dapat data, dan apa adanya dapat `NULL`.
     *
     * Bentuk aslinya `{"password":"Rahasia"}`. Nilai yang diapit tanda kutip
     * harus diganti penanda yang juga diapit tanda kutip yang sama.
     */
    protected static function replaceValue(array $matches): string
    {
        $prefix = $matches[1];

        // Semua pola punya dua grup: (1) nama field, (2) nilai.
        $value = $matches[2] ?? '';

        $quote = '';

        if (str_starts_with($value, '"') || str_starts_with($value, "'")) {
            $quote = $value[0];
        }

        return $prefix.$quote.self::REDACTED.$quote;
    }

    /**
     * Sanitasi lalu batasi panjangnya.
     *
     * 🔴 Batas panjang bukan hiasan: `last_error_message` adalah `text` di
     * MySQL (berubah jadi `longtext` di beberapa driver), jadi pesan error yang
     * sangat panjang tetap bisa masuk. Tapi pesan 100 KB tidak berguna dibaca
     * manusia, dan hanya memperbesar baris yang dirender di halaman client.
     *
     * Potong dari AKHIR, bukan awal: bagian awal pesan error biasanya yang
     * menjelaskan apa yang terjadi.
     */
    public function sanitizeAndTruncate(?string $message, int $maxLength = 2000): ?string
    {
        $sanitized = $this->sanitize($message);

        if ($sanitized === null) {
            return null;
        }

        if (mb_strlen($sanitized) <= $maxLength) {
            return $sanitized;
        }

        // `[dipotong]` penting: tanpa penanda, operator bisa salah baca bagian
        // yang tersisa sebagai pesan lengkap.
        return mb_substr($sanitized, 0, $maxLength - mb_strlen(' … [dipotong]'))
            .' … [dipotong]';
    }
}
