<?php

namespace Bale\Srikandi\Livewire\Pages\Landlord\Sesi;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Halaman daftar Log Sesi scraper. Read-only.
 *
 * 🔴 Komponen ini hanya merakit dua anak. Tidak ada state, tidak ada query.
 *
 * Satu baris = satu SESI panen (laporan diagnostik dari scraper), bukan satu
 * dokumen. Isinya tidak boleh diubah dari Bale: kalau bisa, laporan "apa yang
 * pernah dilihat scraper" berhenti bisa dipercaya.
 *
 * 🔴 Permission TERPISAH `srikandi.sesi.read` -- bukan `srikandi.naskah.read`
 * maupun `srikandi.naskah.keluar.read`. Snapshot adalah metrik internal panen;
 * hak aksesnya bisa diberikan terpisah dari arsip naskah.
 */
#[Layout('core::layouts.app')]
#[Title('Log Sesi Srikandi')]
class Index extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('srikandi.sesi.read');
    }

    public function render()
    {
        return view('srikandi::livewire.pages.landlord.sesi.index');
    }
}
