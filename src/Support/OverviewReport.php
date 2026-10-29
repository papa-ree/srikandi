<?php

namespace Bale\Srikandi\Support;

use Bale\Srikandi\Models\SrikandiNaskah;
use Bale\Srikandi\Models\SrikandiNaskahKeluar;
use Illuminate\Support\Carbon;

/**
 * Data yang dipakai halaman Overview dan kedua format export.
 *
 * 🔴 Satu pembangun data untuk PDF dan XLSX.
 *
 * Kalau controller export membangun query sendiri, angka di dokumen bisa
 * menyimpang dari angka di layar tanpa ada yang menyadarinya -- jenis bug yang
 * justru paling mahal karena dokumen dianggap bukti.
 *
 * Isi sengaja terbatas pada yang DIMINTA: ringkasan masuk/keluar dan daftar
 * naskah per baris. Grafik, distribusi, dan kesehatan client tidak ikut:
 * status sesi adalah kondisi SEKARANG, bukan angka periode, dan menaruhnya di
 * dokumen bertanggal akan menyesatkan.
 */
final class OverviewReport
{
    /**
     * Batas baris per tabel.
     *
     * Rentang 90 hari bisa berisi puluhan ribu naskah. dompdf merender seluruh
     * dokumen di memori, jadi tanpa batas export bisa mati di tengah jalan.
     * Angka total tetap angka sebenarnya; yang dipotong hanya daftar barisnya
     * dan hal itu dicetak di dokumen.
     */
    public const MAX_ROWS = 5000;

    public function __construct(public readonly OverviewPeriod $period) {}

    /**
     * @return list<array{label: string, value: string|int}>
     */
    public function summary(): array
    {
        [$start, $end] = $this->period->tanggalRange();
        $masuk = SrikandiNaskah::query()->whereBetween('tanggal_naskah', [$start, $end]);

        return [
            ['label' => __('Periode'), 'value' => $this->period->label()],
            ['label' => __('Naskah Masuk'), 'value' => (clone $masuk)->count()],
            ['label' => __('Naskah Keluar'), 'value' => SrikandiNaskahKeluar::query()
                ->whereBetween('tanggal_naskah', [$start, $end])
                ->count()],
            ['label' => __('Punya Berkas'), 'value' => (clone $masuk)
                ->whereNotNull('status_berkas')
                ->where('status_berkas', '!=', '')
                ->count()],
            ['label' => __('Dibuat'), 'value' => $this->generatedAt()->locale('id')->translatedFormat('d M Y H:i')],
        ];
    }

    /**
     * @return array{title: string, columns: list<string>, headings: list<string>, rows: list<array<string, string>>, total: int}
     */
    public function masuk(): array
    {
        [$start, $end] = $this->period->tanggalRange();
        $query = SrikandiNaskah::query()->whereBetween('tanggal_naskah', [$start, $end]);

        return $this->build(
            __('Naskah Masuk'),
            ['nomor_naskah', 'tanggal_naskah', 'sumber', 'pengirim', 'hal', 'ringkasan', 'status_baca', 'status_berkas'],
            [__('Nomor Naskah'), __('Tanggal'), __('Sumber'), __('Pengirim'), __('Hal'), __('Ringkasan'), __('Status Baca'), __('Status Berkas')],
            (clone $query)->count(),
            $query->orderBy('tanggal_naskah')->orderBy('nomor_naskah')
                ->limit(self::MAX_ROWS)
                ->get()
                ->map(fn (SrikandiNaskah $naskah): array => [
                    'nomor_naskah' => (string) $naskah->nomor_naskah,
                    'tanggal_naskah' => self::date($naskah->tanggal_naskah),
                    'sumber' => (string) $naskah->sumber,
                    'pengirim' => (string) ($naskah->pengirim ?? ''),
                    'hal' => (string) ($naskah->hal ?? ''),
                    'ringkasan' => (string) ($naskah->ringkasan ?? ''),
                    'status_baca' => self::status($naskah->status_baca),
                    'status_berkas' => self::status($naskah->status_berkas),
                ])
                ->all(),
        );
    }

    /**
     * @return array{title: string, columns: list<string>, headings: list<string>, rows: list<array<string, string>>, total: int}
     */
    public function keluar(): array
    {
        [$start, $end] = $this->period->tanggalRange();
        $query = SrikandiNaskahKeluar::query()->whereBetween('tanggal_naskah', [$start, $end]);

        return $this->build(
            __('Naskah Keluar'),
            ['nomor_naskah', 'tanggal_naskah', 'sumber', 'tujuan_naskah', 'hal', 'ringkasan', 'status_tindak_lanjut', 'status_berkas'],
            [__('Nomor Naskah'), __('Tanggal'), __('Sumber'), __('Tujuan'), __('Hal'), __('Ringkasan'), __('Status Tindak Lanjut'), __('Status Berkas')],
            (clone $query)->count(),
            $query->orderBy('tanggal_naskah')->orderBy('nomor_naskah')
                ->limit(self::MAX_ROWS)
                ->get()
                ->map(fn (SrikandiNaskahKeluar $naskah): array => [
                    'nomor_naskah' => (string) $naskah->nomor_naskah,
                    'tanggal_naskah' => self::date($naskah->tanggal_naskah),
                    'sumber' => (string) $naskah->sumber,
                    'tujuan_naskah' => (string) ($naskah->tujuan_naskah ?? ''),
                    'hal' => (string) ($naskah->hal ?? ''),
                    'ringkasan' => (string) ($naskah->ringkasan ?? ''),
                    'status_tindak_lanjut' => self::status($naskah->status_tindak_lanjut),
                    'status_berkas' => self::status($naskah->status_berkas),
                ])
                ->all(),
        );
    }

    public function generatedAt(): Carbon
    {
        return Carbon::now(config('app.timezone'));
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $headings
     * @param  list<array<string, string>>  $rows
     * @return array{title: string, columns: list<string>, headings: list<string>, rows: list<array<string, string>>, total: int}
     */
    private function build(string $title, array $columns, array $headings, int $total, array $rows): array
    {
        return compact('title', 'columns', 'headings', 'total', 'rows');
    }

    /**
     * Tanggal untuk dokumen export: zona waktu aplikasi + nama bulan Indonesia.
     *
     * 🔴 Jangan pakai `format()` polos. Dokumen dibaca operator, bukan mesin,
     * dan `Y-m-d` Inggris/UTC tidak menjawab "kapan" dengan bahasa yang sama
     * seperti halaman.
     */
    private static function date(?Carbon $date): string
    {
        return $date === null
            ? ''
            : $date->copy()->timezone(config('app.timezone'))->locale('id')->translatedFormat('d M Y');
    }

    private static function status(?string $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : '-';
    }
}
