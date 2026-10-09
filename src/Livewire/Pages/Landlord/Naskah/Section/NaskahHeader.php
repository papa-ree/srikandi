<?php

namespace Bale\Srikandi\Livewire\Pages\Landlord\Naskah\Section;

use Bale\Srikandi\Models\SrikandiNaskah;
use Livewire\Component;

/**
 * Ringkasan jumlah naskah di atas tabel.
 *
 * 🔴 Angka basi dihitung dengan scope `notFresh()`/`fresh()` yang sudah ada di
 * model, BUKAN dengan `where('last_seen_at', '<', now()->subDay())` yang
 * ditulis ulang di sini.
 *
 * Model mewajibkan batas menit yang sama antara scope dan `isStale()`; kalau
 * view ini punya batas sendiri, kartu ini dan badge "basi" di baris bisa
 * menjawab pertanyaan berbeda untuk baris yang sama — dan tidak ada yang
 * bisa mengetahuinya dari halaman mana pun.
 */
class NaskahHeader extends Component
{
    /**
     * Ambang "naskah ini sudah tidak terlihat di list Srikandi".
     */
    public int $thresholdMinutes = 1440;

    public function render()
    {
        $base = SrikandiNaskah::query();

        return view('srikandi::livewire.pages.landlord.naskah.section.naskah-header', [
            'total' => (clone $base)->count(),
            'fresh' => (clone $base)->fresh($this->thresholdMinutes)->count(),
            'stale' => (clone $base)->notFresh($this->thresholdMinutes)->count(),
            'withBerkas' => (clone $base)->whereNotNull('status_berkas')->count(),
            'thresholdMinutes' => $this->thresholdMinutes,
        ]);
    }
}
