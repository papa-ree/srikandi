<?php

namespace Bale\Srikandi\Livewire\Pages\Landlord\NaskahKeluar;

use Bale\Srikandi\Models\SrikandiNaskahKeluar;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Detail satu Naskah Keluar. Read-only: tidak ada form, tidak ada aksi tulis.
 *
 * 🔴 Route model binding, bukan `find()` manual.
 *
 * Kalau barisnya tidak ada, sistem harus menjawab 404 dan tidak pernah
 * "halaman detail kosong" -- admin yang mengira naskahnya hilang akan mulai
 * mencari-cari di tempat lain.
 */
#[Layout('core::layouts.app')]
#[Title('Detail Naskah Keluar')]
class Detail extends Component
{
    use AuthorizesRequests;

    public SrikandiNaskahKeluar $naskahKeluar;

    public function mount(SrikandiNaskahKeluar $naskahKeluar): void
    {
        $this->authorize('srikandi.naskah.keluar.read');

        $this->naskahKeluar = $naskahKeluar;
    }

    public function render()
    {
        return view('srikandi::livewire.pages.landlord.naskah-keluar.detail', [
            'naskah' => $this->naskahKeluar,
            // 🔴 `isStale()` dipanggil sekali, di sini, lalu dipakai view dan
            // test dari nilai yang sama. Kalau view memanggilnya sendiri dengan
            // ambang default sendiri, "basi" di badge dan di ringkasan bisa
            // berbeda karena `now()` bergeser di antara dua pemanggilan.
            'stale' => $this->naskahKeluar->isStale(),
            'hasBerkas' => $this->naskahKeluar->hasBerkas(),
        ]);
    }
}
