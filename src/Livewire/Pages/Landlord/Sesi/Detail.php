<?php

namespace Bale\Srikandi\Livewire\Pages\Landlord\Sesi;

use Bale\Srikandi\Models\SrikandiSessionSnapshot;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Detail satu sesi scraper. Read-only: tidak ada form, tidak ada aksi tulis.
 *
 * 🔴 Route model binding, bukan `find()` manual.
 *
 * Sesi diidentifikasi UUID. Kalau barisnya tidak ada, jawabannya 404 -- bukan
 * "halaman detail kosong". Admin yang mengira sesinya hilang akan mulai
 * mencari-cari di tempat lain.
 */
#[Layout('core::layouts.app')]
#[Title('Detail Log Sesi')]
class Detail extends Component
{
    use AuthorizesRequests;

    public SrikandiSessionSnapshot $snapshot;

    public function mount(SrikandiSessionSnapshot $snapshot): void
    {
        $this->authorize('srikandi.sesi.read');

        $this->snapshot = $snapshot;
    }

    public function render()
    {
        return view('srikandi::livewire.pages.landlord.sesi.detail', [
            'snapshot' => $this->snapshot,
            // 🔴 Dihitung sekali, di sini. View dan test membaca nilai yang sama
            // -- kalau view menghitung sendiri, rentang waktu dan durasi bisa
            // tidak sinkron karena `now()` bergeser di antara dua pemanggilan.
            'duration' => $this->snapshot->durationForHumans(),
            // Array `CycleSnapshot[]`. Bisa NULL untuk sesi yang gagal sebelum
            // sempat membaca list; view menanganinya sebagai daftar kosong.
            'entries' => $this->snapshot->snapshots ?? [],
        ]);
    }
}
