<?php

namespace Bale\Srikandi\Livewire\Pages\Landlord\Overview\Concerns;

use Bale\Srikandi\Models\SrikandiNaskah;
use Illuminate\Support\Carbon;

/**
 * Filter periode bulan untuk halaman Overview.
 *
 * 🔴 Filter memakai `tanggal_naskah` (tanggal DOKUMEN), bukan `first_seen_at`
 * (kapan Bale pertama melihatnya).
 *
 * Overview menjawab "naskah bertanggal bulan ini ada berapa", jadi periodenya
 * mengikuti bulan dan tahun pada `tanggal_naskah`. Satu siklus scraper sering
 * menyapu beberapa bulan dokumen sekaligus, sehingga memakai `first_seen_at`
 * akan menaruh semua naskah May--Oct ke dalam bulan ingest (October) dan
 * menyembunyikan sebaran yang sebenarnya.
 *
 * Konsekuensi yang diterima: naskah dengan `tanggal_naskah` NULL tidak masuk
 * bulan mana pun, dan bulan tanpa dokumen bertanggal bulan itu akan nol meski
 * scraper sibuk. Itu memang pertanyaan yang berbeda dari "aktivitas ingest".
 *
 * 🔴 Rentang bulan dihitung di PHP, BUKAN `DATE_FORMAT()` / `strftime()`.
 *
 * Produksi MySQL, test SQLite. Fungsi ekstraksi bulan berbeda nama dan
 * bentuknya antar keduanya; satu `selectRaw` akan hijau di satu driver dan
 * error di driver lain. Batas `whereBetween(tanggal_naskah, ...)` portabel, dan
 * pengelompokan per hari memakai `DATE()` yang sama-sama dikenal MySQL dan
 * SQLite.
 */
trait ResolvesBulan
{
    /**
     * Bulan valid (`Y-m`) atau bulan berjalan kalau nilainya kosong/rusak.
     *
     * URL adalah input pengguna: `?bulan=abc` tidak boleh melempar exception
     * `createFromFormat()` yang membocorkan stack trace, dan tidak boleh
     * diam-diam menampilkan data bulan lain tanpa penanda.
     */
    protected function normalizeBulan(?string $value): string
    {
        if (is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1) {
            return $value;
        }

        return Carbon::now()->format('Y-m');
    }

    /**
     * 🔴 Dinormalkan ULANG setiap kali `$bulan` berubah, bukan hanya saat mount.
     *
     * `wire:model` dan `#[Url]` adalah batas kepercayaan. Nilai mount sudah
     * lewat `normalizeBulan()`, tapi request Livewire berikutnya mengirim ulang
     * properti dari snapshot/input. Tanpa hook ini, `bulan=abc` yang dikirim ke
     * update request akan sampai ke `Carbon::createFromFormat()` dan melempar
     * exception alih-alih kembali ke bulan berjalan.
     *
     * Diletakkan di trait supaya keempat komponen (halaman + tiga section) yang
     * menerima `bulan` punya penjaga yang sama, bukan satu halaman saja.
     */
    public function updatedBulan(): void
    {
        $this->bulan = $this->normalizeBulan($this->bulan);
    }

    /**
     * Batas awal & akhir bulan, inklusif dua-duanya.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function bulanRange(string $bulan): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $bulan.'-01')->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }

    /**
     * Batas bulan untuk `whereBetween` pada kolom DATE seperti `tanggal_naskah`.
     *
     * 🔴 Batas bawah tanggal polos (`'Y-m-d'`), batas atas AKHIR HARI.
     *
     * Kolom `tanggal_naskah` di-cast `date`, jadi Eloquent menyimpannya sebagai
     * `'Y-m-d H:i:s'` (mis. `'2026-08-31 00:00:00'`). SQLite membandingkan
     * string secara leksikografis, dan string yang LEBIH PANJANG dengan awalan
     * sama dianggap LEBIH BESAR: `'2026-08-31 00:00:00' > '2026-08-31'`. Jadi
     * batas atas tanggal polos akan MEMBUANG naskah di hari terakhir bulan itu
     * tanpa error apa pun.
     *
     * Batas bawah justru sebaliknya: tanggal polos `'2026-08-01'` cocok baik
     * untuk nilai ber-jam (`'2026-08-01 00:00:00'`) maupun nilai polos
     * (`'2026-08-01'`), sedangkan `'2026-08-01 00:00:00'` akan membuang bentuk
     * polos. Karena itu keduanya tidak boleh diperlakukan sama.
     *
     * MySQL tidak terpengaruh (kolom DATE dipotong jadi tengah malam), jadi bug
     * ini hanya muncul di test -- persis kebalikan dari dugaan.
     *
     * @return array{0: string, 1: string}
     */
    protected function bulanTanggalRange(string $bulan): array
    {
        [$start, $end] = $this->bulanRange($bulan);

        return [$start->toDateString(), $end->toDateTimeString()];
    }

    protected function bulanLabel(string $bulan): string
    {
        return Carbon::createFromFormat('Y-m-d', $bulan.'-01')->format('F Y');
    }

    /**
     * Opsi bulan: dari bulan paling awal yang punya naskah (menurut
     * `tanggal_naskah`) sampai bulan ini.
     *
     * 🔴 Bulan berjalan SELALU ada opsi meski belum ada naskah.
     *
     * Hari pertama bulan berjalan biasanya belum ada baris apa pun. Kalau
     * daftarnya diturunkan murni dari data, dropdown kosong dan operator tidak
     * punya cara melihat "bulan ini masih nol" -- satu-satunya sinyal yang
     * berguna saat scraper mati sejak awal bulan.
     *
     * @return array<string, string>
     */
    protected function bulanOptions(): array
    {
        $end = Carbon::now()->startOfMonth();
        $earliest = SrikandiNaskah::query()->min('tanggal_naskah');
        $start = $earliest !== null ? Carbon::parse($earliest)->startOfMonth() : $end->copy();

        $options = [];

        for ($month = $end->copy(); $month->greaterThanOrEqualTo($start); $month->subMonth()) {
            $options[$month->format('Y-m')] = $month->format('F Y');
        }

        return $options;
    }
}
