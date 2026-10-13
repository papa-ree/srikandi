<?php

namespace Bale\Srikandi\Services;

use Bale\Srikandi\Models\SrikandiNaskahKeluar;
use Bale\Srikandi\Support\NaskahYear;
use Illuminate\Support\Facades\DB;

/**
 * Simpan hasil pembacaan list Naskah Keluar dari scraper (PRD §4.1c).
 *
 * 🔴 Kembar dari {@see NaskahIngestService}, tapi tanpa notifikasi. Yang
 * memutuskan `inserted` vs `revised` tetap BACKEND, lewat perbandingan
 * `row_hash` milik Srikandi -- scraper tidak pernah mengirim status "baru".
 *
 * 🔴 TIDAK menyentuh `srikandi_naskah` maupun `NaskahNotifier`. Notifikasi
 * naskah baru untuk Naskah Keluar belum dipasang: `NaskahNotifier`
 * mengakumulasi `notify_pending_*` milik Naskah Masuk, dan menumpangkan keluar
 * ke sana akan mencampur dua jenis di satu pesan.
 */
class NaskahKeluarIngestService
{
    /**
     * @param  iterable<array<string, mixed>>  $items  isi `data[]` dari Srikandi
     * @param  string  $sumber  slug client Srikandi yang mengirim payload ini
     * @return array{inserted: int, revised: int, unchanged: int, skipped: int}
     */
    public function ingest(iterable $items, string $sumber): array
    {
        $counts = [
            'inserted' => 0,
            'revised' => 0,
            'unchanged' => 0,
            'skipped' => 0,
        ];

        foreach ($items as $item) {
            if (! is_array($item)) {
                $counts['skipped']++;

                continue;
            }

            $row = $this->mapItem($item);

            if ($row === null) {
                $counts['skipped']++;

                continue;
            }

            $outcome = DB::transaction(fn () => $this->persist($row + ['sumber' => $sumber]));

            $counts[$outcome]++;
        }

        return $counts;
    }

    /**
     * Simpan satu baris dan tentukan apa yang terjadi padanya.
     *
     * `last_seen_at` diperbarui di KETIGA cabang, termasuk `unchanged` --
     * itulah yang membuat naskah yang hilang dari list Srikandi terdeteksi.
     *
     * @param  array<string, mixed>  $row
     * @return 'inserted'|'revised'|'unchanged'
     */
    protected function persist(array $row): string
    {
        // 🔴 `sumber` WAJIB dalam pencarian. Tanpa itu dua akun Srikandi dengan
        // nomor sama akan saling menimpa, dan yang terjadi adalah `unchanged` --
        // bukan error, jadi tidak ada yang tahu satu akun kehilangan riwayatnya.
        $existing = SrikandiNaskahKeluar::query()
            ->where('sumber', $row['sumber'])
            ->where('nomor_naskah', $row['nomor_naskah'])
            ->where('tahun', $row['tahun'])
            ->lockForUpdate()
            ->first();

        $now = now();

        if ($existing === null) {
            SrikandiNaskahKeluar::query()->create($row + [
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);

            return 'inserted';
        }

        // Hash SEBELUM ditulis harus ditangkap dulu; `forceFill()` menimpa
        // `row_hash` pada model yang sama, sehingga perbandingan sesudahnya
        // selalu sama dan `revised` tidak akan pernah terjadi.
        $previousHash = $existing->row_hash;

        $existing->forceFill($row + ['last_seen_at' => $now])->save();

        // `row_hash` yang tadinya NULL diperlakukan sebagai berubah, bukan
        // unchanged: baris tanpa hash adalah data lama yang belum pernah punya
        // bukti perbandingan, dan siklus ini pertama kali Srikandi mengirimnya.
        if ($previousHash !== $row['row_hash']) {
            return 'revised';
        }

        return 'unchanged';
    }

    /**
     * Pemetaan field payload Srikandi -> kolom tabel (PRD §4.1c).
     *
     * Menerima nama ternormalisasi Kontrak API (rak PRD §6.3) DAN nama mentah
     * Srikandi dari discovery, supaya scraper bisa mengirim apa adanya.
     *
     * Mengembalikan NULL kalau `nomor` kosong -- tanpa itu semua baris rusak
     * menumpuk pada satu kunci dedup dengan `tahun = 0`.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    protected function mapItem(array $item): ?array
    {
        $nomor = $this->stringOrNull($item['nomor_naskah'] ?? $item['nomor'] ?? null);

        if ($nomor === null) {
            return null;
        }

        $tanggal = $item['tanggal_naskah'] ?? $item['tanggal'] ?? null;

        return [
            'nomor_naskah' => $nomor,
            'tahun' => NaskahYear::from($tanggal),
            'tanggal_naskah' => NaskahYear::normalizeDate($tanggal),
            'hal' => $this->stringOrNull($item['hal'] ?? null),
            'ringkasan' => $this->stringOrNull($item['ringkasan'] ?? null),
            'tujuan_naskah' => $this->stringOrNull($item['tujuan_naskah'] ?? null),
            /*
             * Di sampel discovery tidak ada `status_tindak_lanjut`; yang paling
             * dekat maknanya adalah `status_send.status` ("KIRIM"/"BELUM"). Kalau
             * scraper mengirim nama ternormalisasi, itu yang dipakai.
             */
            'status_tindak_lanjut' => $this->stringOrNull(
                $item['status_tindak_lanjut'] ?? $item['status_send']['status'] ?? null
            ),
            'status_baca' => $this->stringOrNull($item['status_baca'] ?? null),
            // Bentuk mentah, termasuk NULL (lihat migrasi).
            'status_berkas' => $this->rawString($item['status_berkas'] ?? null),
            'no_urut' => $this->intOrNull($item['no_urut'] ?? $item['nomor_urut'] ?? null),
            'file_path' => $this->stringOrNull($item['file_path'] ?? null),
            'file_name_original' => $this->stringOrNull($item['file_name_original'] ?? null),
            // `row_hash` diturunkan dari MD5 Srikandi (`hash_value`), bukan dari
            // `row_hash` yang mungkin dihitung scraper. Kalau `hash_value` tidak
            // ada, baru pakai `row_hash` apa adanya.
            'row_hash' => $this->normalizeHash($item['hash_value'] ?? $item['row_hash'] ?? null),
            'hash_value' => $this->rawString($item['hash_value'] ?? null),
            'snapshot' => $item,
        ];
    }

    /**
     * Hash milik Srikandi disimpan dengan prefix algoritma.
     *
     * Prefix itu bukan hiasan: tanpa itu `NULL` dan string kosong tidak bisa
     * dibedakan dari `md5:...`, dan tidak jelas apakah sebuah baris sudah punya
     * bukti hash atau belum.
     */
    protected function normalizeHash(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);

        if ($value === null) {
            return null;
        }

        if (str_contains($value, ':')) {
            return $value;
        }

        return 'md5:'.$value;
    }

    protected function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Nilai mentah untuk kolom yang bentuknya dijaga apa adanya.
     *
     * `status_berkas` sering `null` di payload dan itu nilai yang benar. Jadi
     * fungsi ini TIDAK boleh mengubah NULL menjadi string kosong.
     */
    protected function rawString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Nomor urut adalah angka tampilan, bukan identitas. Nilai non-angka
     * dikembalikan NULL, bukan `0` -- `0` adalah nomor urut yang sah.
     */
    protected function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (int) trim($value);
        }

        return null;
    }
}
