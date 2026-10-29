<?php

namespace Bale\Srikandi\Support;

use Illuminate\Support\Carbon;

/**
 * Periode Overview Srikandi: bulan atau rentang tanggal.
 *
 * 🔴 Satu-satunya tempat yang menerjemahkan query `bulan` / `dari` / `sampai`
 * menjadi batas tanggal. Halaman, ketiga section, dan export memakai objek ini
 * supaya angka di dokumen export tidak mungkin berbeda dari angka di layar.
 *
 * 🔴 Filter tetap memakai `tanggal_naskah` (tanggal DOKUMEN), bukan
 * `first_seen_at` (kapan Bale pertama melihatnya). Alasannya ada di
 * `ResolvesBulan`.
 *
 * 🔴 Batas atas selalu AKHIR HARI (`toDateTimeString()`). `tanggal_naskah`
 * di-cast `date` dan SQLite membandingkan string secara leksikografis, jadi
 * batas atas tanggal polos akan membuang naskah di hari terakhir.
 */
final class OverviewPeriod
{
    /**
     * Batas rentang. Cukup lebar untuk satu kuartal, cukup sempit untuk
     * menjaga PDF tetap bisa dirender dompdf tanpa kehabisan memori.
     */
    public const MAX_DAYS = 90;

    private function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly bool $isRange,
        public readonly ?string $notice = null,
    ) {}

    /**
     * Rentang menang bila salah satu batasnya diisi; selain itu bulan.
     *
     * Input rusak tidak melempar exception: URL adalah input pengguna, dan
     * halaman kosong lebih berguna daripada stack trace. Setiap koreksi
     * (dibalik, dipotong, tidak dikenali) meninggalkan `notice` supaya
     * pemotongan tidak pernah diam-diam.
     */
    public static function fromQuery(?string $bulan, ?string $dari, ?string $sampai): self
    {
        $hasDari = is_string($dari) && trim($dari) !== '';
        $hasSampai = is_string($sampai) && trim($sampai) !== '';

        if (! $hasDari && ! $hasSampai) {
            return self::monthOf($bulan);
        }

        $start = self::parseDate($dari);
        $end = self::parseDate($sampai);

        // Satu sisi rusak = seluruh rentang tidak bisa dipercaya. Jatuh ke
        // bulan berjalan lebih jujur daripada setengah memakai tanggal lain.
        if ($start === null || $end === null) {
            return self::monthOf($bulan, __('Rentang tanggal tidak dikenali; menampilkan bulan berjalan.'));
        }

        $notice = null;

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
            $notice = __('Tanggal awal dan akhir tertukar; urutannya dibalik.');
        }

        if ((int) $start->diffInDays($end) + 1 > self::MAX_DAYS) {
            $end = $start->copy()->addDays(self::MAX_DAYS - 1);
            $notice = __('Rentang dibatasi :days hari.', ['days' => self::MAX_DAYS]);
        }

        return new self($start->startOfDay(), $end->endOfDay(), true, $notice);
    }

    public static function monthOf(?string $bulan, ?string $notice = null): self
    {
        $start = Carbon::createFromFormat('Y-m-d', self::normalizeMonth($bulan).'-01')->startOfMonth();

        return new self($start, $start->copy()->endOfMonth(), false, $notice);
    }

    /**
     * Bulan valid (`Y-m`) atau bulan berjalan kalau kosong/rusak.
     */
    public static function normalizeMonth(?string $bulan): string
    {
        return is_string($bulan) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan) === 1
            ? $bulan
            : Carbon::now()->format('Y-m');
    }

    /**
     * Batas untuk `whereBetween` pada kolom DATE.
     *
     * @return array{0: string, 1: string}
     */
    public function tanggalRange(): array
    {
        return [$this->start->toDateString(), $this->end->toDateTimeString()];
    }

    /**
     * Label yang ditampilkan di halaman, PDF, dan XLSX.
     *
     * 🔴 Selalu bahasa Indonesia dan zona waktu aplikasi, bukan locale/UTC
     * proses. Dokumen export dibaca operator yang sama dengan halaman.
     */
    public function label(): string
    {
        $start = $this->start->copy()->timezone(config('app.timezone'))->locale('id');
        $end = $this->end->copy()->timezone(config('app.timezone'))->locale('id');

        if (! $this->isRange) {
            return $start->translatedFormat('F Y');
        }

        if ($start->isSameDay($end)) {
            return $start->translatedFormat('d M Y');
        }

        return $start->translatedFormat('d M Y').' – '.$end->translatedFormat('d M Y');
    }

    /**
     * Kunci unik periode untuk `:key` komponen Livewire.
     */
    public function key(): string
    {
        return $this->isRange
            ? $this->start->format('Y-m-d').'_'.$this->end->format('Y-m-d')
            : $this->start->format('Y-m');
    }

    /**
     * Query string periode untuk tautan export.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        return $this->isRange
            ? ['dari' => $this->start->toDateString(), 'sampai' => $this->end->toDateString()]
            : ['bulan' => $this->start->format('Y-m')];
    }

    private static function parseDate(?string $value): ?Carbon
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m) !== 1) {
            return null;
        }

        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', trim($value))->startOfDay();
    }
}
