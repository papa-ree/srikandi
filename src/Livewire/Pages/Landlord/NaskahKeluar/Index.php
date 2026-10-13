<?php

namespace Bale\Srikandi\Livewire\Pages\Landlord\NaskahKeluar;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Halaman daftar Naskah Keluar. Read-only.
 *
 * 🔴 Komponen ini hanya merakit dua anak. Tidak ada state, tidak ada query.
 *
 * Sama seperti Naskah Masuk: naskah asli tetap milik Srikandi. Halaman ini
 * menampilkan salinan yang dibaca scraper, jadi tidak ada aksi tulis di sini.
 *
 * 🔴 Permission TERPISAH `srikandi.naskah.keluar.read` -- bukan
 * `srikandi.naskah.read`. Naskah Keluar adalah tabel dan halaman yang berbeda;
 * hak aksesnya bisa diberikan per-jenis.
 */
#[Layout('core::layouts.app')]
#[Title('Naskah Keluar Srikandi')]
class Index extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('srikandi.naskah.keluar.read');
    }

    public function render()
    {
        return view('srikandi::livewire.pages.landlord.naskah-keluar.index');
    }
}
