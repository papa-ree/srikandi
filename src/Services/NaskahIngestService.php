<?php

namespace Bale\Srikandi\Services;

use Bale\Srikandi\Exceptions\SrikandiException;
use Bale\Srikandi\Models\SrikandiNaskah;
use Bale\Srikandi\Support\NaskahYear;
use Illuminate\Support\Facades\DB;

/**
 * Simpan hasil pembacaan list naskah dari scraper (spec §5.4).
 *
 * 🔴 Yang memutuskan `inserted` vs `revised` adalah BACKEND, lewat perbandingan
 * `row_hash`. Scraper tidak mengirim status "baru".
 *
 * Alasannya: kalau scraper yang menentukan, scraper harus tahu apa yang berubah
 * — dan scraper tidak punya akses ke DB Bale untuk membandingkan. Setelah
 * scraper menentukan "ini baru", Bale tidak punya jalan untuk mengoreksi. Dengan
 * membandingkan hash, Bale bisa menentukan sendiri dari bukti.
 */
class NaskahIngestService
{
    /**
     * @param  string  $baleId  UUID `bale_lists.id` - identitas organisasi penyewa
     * @param  iterable<array<string, mixed>>  $items  isi `data[]` dari Srikandi
     * @return array{inserted: int, revised: int, unchanged: int, skipped: int}
     *
     * @throws SrikandiException
     */
    public function ingest(string $baleId, iterable $items): array
    {
        $baleId = $this->requireBaleId($baleId);

        $counts = ['inserted' => 0, 'revised' => 0, 'unchanged' => 0, 'skipped' => 0];

        foreach ($items as $item) {
            if (! is_array($item)) {
                $counts['skipped']++;

                continue;
            }

            $row = $this->mapItem($baleId, $item);

            if ($row === null) {
                $counts['skipped']++;

                continue;
            }

            $outcome = DB::transaction(fn () => $this->persist($row));

            $counts[$outcome]++;
        }

        return $counts;
    }

    /**
     * Simpan satu baris dan tentukan apa yang terjadi padanya.
     *
     * `last_seen_at` diperbarui di KETIGA cabang, termasuk `unchanged` (spec
     * §5.4). Itu yang membuat tabel ini bisa dipakai mendeteksi naskah yang
     * hilang dari list Srikandi: baris yang lama tidak terlihat akan punya
     * `last_seen_at` jauh di belakang.
     *
     * @param  array<string, mixed>  $row
     * @return 'inserted'|'revised'|'unchanged'
     */
    protected function persist(array $row): string
    {
        $existing = SrikandiNaskah::query()
            ->where('bale_id', $row['bale_id'])
            ->where('nomor_naskah', $row['nomor_naskah'])
            ->where('tahun', $row['tahun'])
            ->lockForUpdate()
            ->first();

        $now = now();

        if ($existing === null) {
            SrikandiNaskah::query()->create($row + [
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);

            return 'inserted';
        }

        /*
         * Hash SEBELUM ditulis harus ditangkap dulu.
         *
         * `forceFill()` di bawah menimpa `row_hash` pada model yang sama, jadi
         * perbandingan yang dilakukan sesudahnya selalu sama dan `revised`
         * tidak akan pernah terjadi — semua revisi dilaporkan `unchanged`.
         */
        $previousHash = $existing->row_hash;

        $existing->forceFill($row + ['last_seen_at' => $now])->save();

        /*
         * Hash yang berubah = naskah direvisi Srikandi. Hash yang tetap = baris
         * tidak berubah, dan `last_seen_at` tetap diperbarui supaya naskah ini
         * masih terlihat ada di list.
         *
         * `row_hash` yang tadinya NULL diperlakukan sebagai berubah, bukan
         * unchanged: baris tanpa hash berarti data lama yang belum pernah punya
         * bukti perbandingan. Menandainya unchanged akan membuatnya tidak pernah
         * bisa terdeteksi berubah di siklus berikutnya — dan tepat siklus itu
         * pertama kali Srikandi mengirim hash.
         */
        if ($previousHash !== $row['row_hash']) {
            return 'revised';
        }

        return 'unchanged';
    }

    /**
     * Pemetaan field payload Srikandi -> kolom tabel (spec §4).
     *
     * Mengembalikan NULL kalau `nomor` kosong — tanpa itu, semua baris rusak
     * akan menumpuk pada satu kunci dedup dengan `tahun = 0`.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    protected function mapItem(string $baleId, array $item): ?array
    {
        $nomor = $this->stringOrNull($item['nomor'] ?? $item['nomor_naskah'] ?? null);

        if ($nomor === null) {
            return null;
        }

        $tanggal = $item['tanggal'] ?? null;

        return [
            'bale_id' => $baleId,
            'nomor_naskah' => $nomor,
            'tahun' => NaskahYear::from($tanggal),
            'tanggal_naskah' => NaskahYear::normalizeDate($tanggal),
            'hal' => $this->stringOrNull($item['hal'] ?? null),
            'ringkasan' => $this->stringOrNull($item['ringkasan'] ?? null),
            'pengirim' => $this->stringOrNull($item['nama_pengirim'] ?? null),
            'status_baca' => $this->stringOrNull($item['status_baca'] ?? null),
            'status_tindak' => $this->stringOrNull($item['status_tindak'] ?? null),
            // Bentuk mentah, termasuk NULL (spec §3.2). Lihat model.
            'status_berkas' => $this->rawString($item['status_berkas'] ?? null),
            'row_hash' => $this->normalizeHash($item['hash_value'] ?? null),
            'snapshot' => $item,
        ];
    }

    /**
     * Hash milik Srikandi disimpan dengan prefix algoritma (spec §3.2).
     *
     * Prefix itu bukan hiasan: tanpa itu, `NULL` dan string kosong jadi tidak
     * bisa dibedakan dari `md5:...`, dan nanti tidak jelas apakah sebuah baris
     * sudah punya bukti hash atau belum.
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
     * fungsi ini TIDAK boleh mengubah NULL menjadi string kosong — keduanya
     * berbeda, dan yang kosong akan membuat badge menampilkan "TIDAK ADA" untuk
     * baris yang sebenarnya tidak punya data berkas sama sekali.
     */
    protected function rawString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            // Versi lain mengirim array di sini. Jangan diam-diam diubah jadi
            // teks; simpan sebagai JSON supaya bentuknya tetap berbeda dan
            // ketahuan kalau verstinya berubah.
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    protected function requireBaleId(string $baleId): string
    {
        $baleId = strtolower(trim($baleId));

        if ($baleId === '') {
            throw SrikandiException::invalidRequest('bale_id wajib diisi.');
        }

        return $baleId;
    }
}
