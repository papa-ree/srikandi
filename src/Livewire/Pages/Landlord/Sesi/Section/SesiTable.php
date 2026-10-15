<?php

namespace Bale\Srikandi\Livewire\Pages\Landlord\Sesi\Section;

use Bale\Srikandi\Models\SrikandiSessionSnapshot;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Tabel Log Sesi. Read-only: tidak ada `Form`, tidak ada `deleteEvent`.
 *
 * 🔴 Opsi filter `sumber` dibangun dari `distinct()->pluck()`, BUKAN daftar
 * konstanta.
 *
 * `sumber` adalah slug client. Client bisa ditambah kapan saja, dan kalau
 * daftarnya ditutup di sini, sesi client baru tetap tampil tapi tidak bisa
 * difilter -- tanpa jejak bahwa filternya sudah usang.
 *
 * 🔴 Opsi filter `status` justru DAFTAR TETAP (`success`/`failed`).
 *
 * Berbeda dari `status_tindak_lanjut` Naskah Keluar yang berasal dari sistem
 * luar dan bisa bertambah, di sini controller sudah memvalidasi bahwa nilainya
 * hanya dua itu (SessionSnapshotTest). Menurunkan opsinya dari data justru akan
 * MENYEMBUNYIKAN status yang belum pernah terkirim -- padahal operator ingin
 * bisa menyaring "yang gagal" bahkan sebelum ada satu pun sesi gagal.
 */
class SesiTable extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('srikandi.sesi.read');
    }

    public function render()
    {
        return view('srikandi::livewire.pages.landlord.sesi.section.sesi-table', [
            'sumberFilter' => $this->sumberFilterOptions(),
        ]);
    }

    /**
     * Opsi filter `sumber`, dari data.
     *
     * @return array<string, string>
     */
    protected function sumberFilterOptions(): array
    {
        $values = SrikandiSessionSnapshot::query()
            ->distinct()
            ->orderBy('sumber')
            ->pluck('sumber')
            ->filter(fn ($value): bool => $value !== null && trim((string) $value) !== '')
            ->map(fn ($value): string => (string) $value)
            ->unique()
            ->values();

        return $values->mapWithKeys(fn (string $value): array => [$value => $value])->all();
    }
}
