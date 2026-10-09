<?php

namespace Bale\Srikandi\Livewire\Pages\Landlord\Client;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Daftar client Srikandi.
 *
 * 🔴 Komponen ini TIPIS. Query, pencarian nomor, dan tabel semuanya milik
 * `Section\ClientTable`, bukan milik halaman.
 *
 * Alasannya bukan selera: pencarian nomor hidup di state `ClientTable` dan
 * mengubahnya harus memutar ulang tabel. Kalau state-nya ditahan di halaman,
 * filter dan paginasi tabel akan berada di dua tempat berbeda yang harus
 * selalu sinkron tanpa ada yang memaksa mereka sinkron.
 *
 * 🔴 Tombol hapus tidak ada, dan `HasDeleteOption` tidak dipakai.
 *
 * Melenceng access berarti mematikan `is_active`, bukan menghapus baris.
 * Naskah yang sudah tercatat menunjuk `slug` client, dan menghapusnya
 * meninggalkan riwayat yang tidak bisa ditelusuri. Detail
 * alasannya ada di `Section\ClientTable`.
 */
#[Layout('core::layouts.app')]
#[Title('Client Srikandi')]
class Index extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('srikandi.client.read');
    }

    public function render()
    {
        return view('srikandi::livewire.pages.landlord.client.index');
    }
}
