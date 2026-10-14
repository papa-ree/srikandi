<?php

namespace Bale\Srikandi\Livewire\Pages\Landlord\Overview\Section;

use Bale\Srikandi\Livewire\Pages\Landlord\Overview\Concerns\ResolvesBulan;
use Bale\Srikandi\Models\SrikandiNaskah;
use Bale\Srikandi\Models\SrikandiNaskahKeluar;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Visualisasi naskah pada Overview: tren harian + dua distribusi.
 *
 * 🔴 Pengelompokan per hari memakai `DATE(tanggal_naskah)`, yang sama-sama
 * dikenal MySQL dan SQLite.
 *
 * `DATE_FORMAT()` (MySQL) dan `strftime()` (SQLite) tidak portabel; satu
 * `selectRaw` yang memakai salah satunya akan hijau di satu driver dan error di
 * driver lain. `DATE()` ada di keduanya, dan `whereBetween()` menahan rentangnya
 * sehingga fungsinya tetap bisa memakai index `tanggal_naskah`.
 *
 * 🔴 Sumbu-X tren tetap dirender penuh (hari 1..N), termasuk hari bernilai nol.
 *
 * Kalau label hanya diambil dari hari yang punya baris, bulan dengan tiga hari
 * aktif akan tampil sebagai garis tiga titik yang datar -- dan mata membacanya
 * "aktivitas stabil", bukan "scraper mati 27 hari". Menyertakan hari nol membuat
 * jeda terlihat sebagai jeda.
 */
class OverviewCharts extends Component
{
    use AuthorizesRequests;
    use ResolvesBulan;

    public string $bulan = '';

    public function mount(string $bulan = ''): void
    {
        $this->authorize('srikandi.client.read');
        $this->bulan = $this->normalizeBulan($bulan);
    }

    public function render()
    {
        [$start, $end] = $this->bulanRange($this->bulan);
        [$dStart, $dEnd] = $this->bulanTanggalRange($this->bulan);

        $daily = SrikandiNaskah::query()
            ->whereBetween('tanggal_naskah', [$dStart, $dEnd])
            ->selectRaw('DATE(tanggal_naskah) as hari, COUNT(*) as total')
            ->groupByRaw('DATE(tanggal_naskah)')
            ->pluck('total', 'hari');

        $dailyKeluar = SrikandiNaskahKeluar::query()
            ->whereBetween('tanggal_naskah', [$dStart, $dEnd])
            ->selectRaw('DATE(tanggal_naskah) as hari, COUNT(*) as total')
            ->groupByRaw('DATE(tanggal_naskah)')
            ->pluck('total', 'hari');

        $trendLabels = [];
        $trendData = [];
        $keluarTrendData = [];

        for ($day = 1; $day <= $start->daysInMonth; $day++) {
            $key = $start->copy()->day($day)->format('Y-m-d');
            $trendLabels[] = (string) $day;
            $trendData[] = (int) ($daily[$key] ?? 0);
            $keluarTrendData[] = (int) ($dailyKeluar[$key] ?? 0);
        }

        $bySumber = SrikandiNaskah::query()
            ->whereBetween('tanggal_naskah', [$dStart, $dEnd])
            ->selectRaw('sumber, COUNT(*) as total')
            ->groupBy('sumber')
            ->orderByDesc('total')
            ->get();

        $byStatus = SrikandiNaskah::query()
            ->whereBetween('tanggal_naskah', [$dStart, $dEnd])
            ->selectRaw('status_baca, COUNT(*) as total')
            ->groupBy('status_baca')
            ->orderByDesc('total')
            ->get();

        $byStatusKeluar = SrikandiNaskahKeluar::query()
            ->whereBetween('tanggal_naskah', [$dStart, $dEnd])
            ->selectRaw('status_tindak_lanjut, COUNT(*) as total')
            ->groupBy('status_tindak_lanjut')
            ->orderByDesc('total')
            ->get();

        return view('srikandi::livewire.pages.landlord.overview.section.overview-charts', [
            'bulanLabel' => $this->bulanLabel($this->bulan),
            'trendLabels' => $trendLabels,
            'trendData' => $trendData,
            'keluarTrendData' => $keluarTrendData,
            'totalPeriode' => array_sum($trendData),
            'totalKeluarPeriode' => array_sum($keluarTrendData),
            'sumberLabels' => $bySumber->pluck('sumber')->all(),
            'sumberData' => $bySumber->pluck('total')->map(fn ($value): int => (int) $value)->all(),
            'statusLabels' => $byStatus->map(function ($row) {
                $value = trim((string) $row->status_baca);

                return $value !== '' ? $value : __('Tidak diketahui');
            })->all(),
            'statusData' => $byStatus->pluck('total')->map(fn ($value): int => (int) $value)->all(),
            'keluarStatusLabels' => $byStatusKeluar->map(function ($row) {
                $value = trim((string) $row->status_tindak_lanjut);

                return $value !== '' ? $value : __('Tidak diketahui');
            })->all(),
            'keluarStatusData' => $byStatusKeluar->pluck('total')->map(fn ($value): int => (int) $value)->all(),
        ]);
    }
}
