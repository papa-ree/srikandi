<?php

namespace Bale\Srikandi\Livewire\Pages\Landlord\Sesi\Section;

use Bale\Srikandi\Models\SrikandiSessionSnapshot;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Ringkasan jumlah sesi di atas tabel Log Sesi.
 *
 * 🔴 "Gagal" dihitung dengan scope `failed()` milik model, BUKAN dengan
 * `where('status', 'failed')` yang ditulis ulang di sini.
 *
 * Kalau badge di baris dan kartu ini punya definisi "gagal" masing-masing,
 * keduanya bisa menjawab angka yang berbeda untuk data yang sama.
 *
 * 🔴 "Sesi Terakhir" diurutkan `finished_at` menurun, bukan `started_at`.
 *
 * Satu slug bisa menjalankan beberapa siklus berurutan. Yang ingin diketahui
 * operator adalah sesi yang paling baru SELESAI, bukan yang paling baru mulai.
 */
class SesiHeader extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('srikandi.sesi.read');
    }

    public function render()
    {
        $base = SrikandiSessionSnapshot::query();

        return view('srikandi::livewire.pages.landlord.sesi.section.sesi-header', [
            'total' => (clone $base)->count(),
            'success' => (clone $base)->where('status', 'success')->count(),
            'failed' => (clone $base)->failed()->count(),
            'last' => (clone $base)->orderByDesc('finished_at')->first(),
        ]);
    }
}
