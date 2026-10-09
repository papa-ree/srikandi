<?php

namespace Bale\Srikandi\Livewire\Pages\Landlord\Naskah;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Halaman daftar naskah dinas. Read-only.
 *
 * 🔴 Komponen ini hanya merakit dua anak. Tidak ada state, tidak ada query.
 *
 * Halaman ini menampilkan naskah hasil pembacaan scraper, dan naskah asli
 * tetap milik Srikandi. Menambah aksi di sini berarti Bale mulai menulis ke
 * dokumen yang bukan miliknya — dan `srikandi_naskah` tidak punya jalur untuk
 * tahu bahwa isinya sudah tidak sama dengan sistem aslinya.
 */
#[Layout('core::layouts.app')]
#[Title('Naskah Srikandi')]
class Index extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('srikandi.naskah.read');
    }

    public function render()
    {
        return view('srikandi::livewire.pages.landlord.naskah.index');
    }
}
