<?php

namespace Bale\Srikandi\Livewire\Pages\Landlord\Naskah;

use Bale\Srikandi\Models\SrikandiNaskah;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Detail satu naskah dinas. Tetap read-only: tidak ada form, tidak ada aksi tulis.
 *
 * 🔴 Route model binding, bukan `find()` manual.
 *
 * Kalau barisnya tidak ada, sistem harus menjawab 404 dan tidak pernah
 * "halaman detail kosong" — admin yang mengira naskahnya hilang akan mulai
 * mencari-cari di tempat lain.
 */
#[Layout('core::layouts.app')]
#[Title('Detail Naskah')]
class Detail extends Component
{
    use AuthorizesRequests;

    public SrikandiNaskah $naskah;

    public function mount(SrikandiNaskah $naskah): void
    {
        $this->authorize('srikandi.naskah.read');

        $this->naskah = $naskah;
    }

    public function render()
    {
        return view('srikandi::livewire.pages.landlord.naskah.detail', [
            'naskah' => $this->naskah,
            // 🔴 `isStale()` dipanggil sekali, di sini, lalu dipakai view dan
            // test dari nilai yang sama. Kalau view memanggilnya sendiri dengan
            // ambang default sendiri, "basi" di badge dan di ringkasan bisa
            // berbeda karena `now()` bergeser di antara dua pemanggilan.
            'stale' => $this->naskah->isStale(),
            'hasBerkas' => $this->naskah->hasBerkas(),
        ]);
    }
}
