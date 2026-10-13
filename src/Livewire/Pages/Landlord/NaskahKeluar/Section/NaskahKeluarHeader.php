<?php

namespace Bale\Srikandi\Livewire\Pages\Landlord\NaskahKeluar\Section;

use Bale\Srikandi\Models\SrikandiNaskahKeluar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Ringkasan jumlah Naskah Keluar di atas tabel.
 *
 * 🔴 Angka basi dihitung dengan scope `notFresh()`/`fresh()` yang sudah ada di
 * model, BUKAN dengan `where('last_seen_at', '<', now()->subDay())` yang
 * ditulis ulang di sini.
 *
 * Model mewajibkan batas menit yang sama antara scope dan `isStale()`; kalau
 * view ini punya batas sendiri, kartu ini dan badge "basi" di baris bisa
 * menjawab pertanyaan berbeda untuk baris yang sama.
 */
class NaskahKeluarHeader extends Component
{
    use AuthorizesRequests;

    /**
     * Ambang "naskah ini sudah tidak terlihat di list Srikandi".
     */
    public int $thresholdMinutes = 1440;

    public function mount(): void
    {
        $this->authorize('srikandi.naskah.keluar.read');
    }

    public function render()
    {
        $base = SrikandiNaskahKeluar::query();

        return view('srikandi::livewire.pages.landlord.naskah-keluar.section.naskah-keluar-header', [
            'total' => (clone $base)->count(),
            'fresh' => (clone $base)->fresh($this->thresholdMinutes)->count(),
            'stale' => (clone $base)->notFresh($this->thresholdMinutes)->count(),
            // 🔴 "Ada berkas" mengikuti `hasBerkas()`, bukan `status_berkas`:
            // bentuk Naskah Keluar menandai lampiran lewat `file_path` (PRD
            // §4.1c), dan `status_berkas` hampir selalu NULL.
            'withBerkas' => (clone $base)->where(function (Builder $q): void {
                $q->where(function (Builder $inner): void {
                    $inner->whereNotNull('file_path')->where('file_path', '!=', '');
                })->orWhere(function (Builder $inner): void {
                    $inner->whereNotNull('file_name_original')->where('file_name_original', '!=', '');
                });
            })->count(),
            'thresholdMinutes' => $this->thresholdMinutes,
        ]);
    }
}
