<?php

namespace Bale\Srikandi\Livewire\Pages\Landlord\Overview;

use Bale\Srikandi\Livewire\Pages\Landlord\Overview\Concerns\ResolvesBulan;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Overview Srikandi (landlord).
 *
 * 🔴 Komponen ini TIPIS: seluruh query ada di anak `Section/*`, bukan di sini.
 *
 * Yang dipegang halaman ini hanya SATU hal: periode bulan yang sedang dilihat
 * (`$bulan`). Itu sengaja karena nilainya harus hidup di URL (`#[Url]`) supaya
 * tautan laporan bisa dikirim ke orang lain, dan URL hanya bisa di-ikat ke satu
 * komponen. Semua turunannya menerima bulan itu sebagai prop.
 *
 * 🔴 `#[Url]` adalah input pengguna, jadi `mount()` menormalkannya dulu.
 * `?bulan=abc` tidak boleh sampai ke `Carbon::createFromFormat()` di anak --
 * hasilnya exception, bukan halaman kosong.
 */
#[Layout('core::layouts.app')]
#[Title('Overview Srikandi')]
class Index extends Component
{
    use AuthorizesRequests;
    use ResolvesBulan;

    /**
     * Periode yang ditampilkan, format `Y-m`.
     */
    #[Url(as: 'bulan', history: true)]
    public string $bulan = '';

    public function mount(): void
    {
        // 🔴 Permission yang dipakai `srikandi.client.read`, bukan
        // `srikandi.naskah.read`.
        //
        // Overview adalah pintu masuk grup Srikandi: ia menampilkan kesehatan
        // client LEBIH DULU sebelum statistik naskah. Kalau dijaga dengan
        // `naskah.read`, operator yang hanya berhak mengelola akun (tanpa akses
        // arsip naskah) tidak punya halaman muka sama sekali.
        $this->authorize('srikandi.client.read');

        $this->bulan = $this->normalizeBulan($this->bulan);
    }

    public function render()
    {
        return view('srikandi::livewire.pages.landlord.overview.index', [
            'monthOptions' => $this->bulanOptions(),
        ]);
    }
}
