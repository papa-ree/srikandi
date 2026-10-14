<?php

namespace Bale\Srikandi\Livewire\Pages\Landlord\Overview\Section;

use Bale\Srikandi\Livewire\Pages\Landlord\Overview\Concerns\ResolvesBulan;
use Bale\Srikandi\Models\SrikandiClient;
use Bale\Srikandi\Models\SrikandiNaskah;
use Bale\Srikandi\Models\SrikandiNaskahKeluar;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Empat kartu angka utama Overview.
 *
 * 🔴 Ambang "masih terlihat" memakai scope `fresh()` milik model, BUKAN
 * `where('last_seen_at', '<', ...)` yang ditulis ulang di sini.
 *
 * `NaskahHeader` (daftar naskah) menampilkan angka yang sama. Angka di sini dan
 * di halaman naskah harus berasal dari scope yang sama supaya tidak menjawab
 * pertanyaan berbeda untuk data yang sama -- kelas bug yang tidak terlihat dari
 * halaman mana pun.
 */
class OverviewMetrics extends Component
{
    use AuthorizesRequests;
    use ResolvesBulan;

    public string $bulan = '';

    public int $thresholdMinutes = 1440;

    public function mount(string $bulan = ''): void
    {
        $this->authorize('srikandi.client.read');
        $this->bulan = $this->normalizeBulan($bulan);
    }

    public function render()
    {
        [$start, $end] = $this->bulanTanggalRange($this->bulan);
        $base = SrikandiNaskah::query()->whereBetween('tanggal_naskah', [$start, $end]);

        return view('srikandi::livewire.pages.landlord.overview.section.overview-metrics', [
            'bulanLabel' => $this->bulanLabel($this->bulan),
            'total' => (clone $base)->count(),
            'totalKeluar' => (clone SrikandiNaskahKeluar::query()->whereBetween('tanggal_naskah', [$start, $end]))->count(),
            'menyetor' => (clone $base)->distinct()->count('sumber'),
            'withBerkas' => (clone $base)
                ->whereNotNull('status_berkas')
                ->where('status_berkas', '!=', '')
                ->count(),
            'fresh' => SrikandiNaskah::query()->fresh($this->thresholdMinutes)->count(),
            'activeClients' => SrikandiClient::query()->active()->count(),
            'thresholdMinutes' => $this->thresholdMinutes,
        ]);
    }
}
