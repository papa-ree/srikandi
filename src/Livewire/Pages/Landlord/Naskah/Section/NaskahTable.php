<?php

namespace Bale\Srikandi\Livewire\Pages\Landlord\Naskah\Section;

use Bale\Srikandi\Models\SrikandiNaskah;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Tabel naskah dinas. Read-only: tidak ada `Form`, tidak ada `deleteEvent`.
 *
 * 🔴 Opsi filter dibangun dari `distinct()->pluck()`, BUKAN dari daftar
 * konstanta.
 *
 * `status_berkas` datang dari sistem luar. Kalau daftarnya ditutup di sini,
 * begitu Srikandi menambah status baru baris itu masih tampil di tabel tapi
 * tidak bisa dicari — dan tidak ada jejak bahwa filternya sudah usang.
 *
 * 🔴 Tahun `0` ("tahun tidak diketahui") tetap jadi opsi.
 *
 * `0` falsy di PHP, jadi filter yang membuang nilai falsy akan membuat baris
 * tanpa tahun mustahil dicari — dan itu justru kelompok yang paling perlu
 * dilihat, karena `tahun` yang hilang biasanya berarti sumber datanya masih
 * bermasalah.
 */
class NaskahTable extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('srikandi.naskah.read');
    }

    public function render()
    {
        return view('srikandi::livewire.pages.landlord.naskah.section.naskah-table', [
            'berkasFilter' => $this->berkasFilterOptions(),
            'tahunFilter' => $this->tahunFilterOptions(),
        ]);
    }

    /**
     * Opsi filter `status_berkas`.
     *
     * 🔴 Nilai NULL ikut jadi opsi lewat `nullLabel`, bukan dibuang.
     *
     * `data-table` memetakan nilai NULL ke sentinel string `'null'` lalu
     * menulisnya jadi `whereNull()` (`data-table.blade.php:214-222`). Tanpa
     * itu, semua naskah yang badge-nya "tidak ada" mustahil difilter — dan
     * `hasBerkas()` sudah menyatakan bahwa bentuk NULL dan `''` dua-duanya
     * berarti "tidak ada berkas".
     *
     * @return array<string, string>
     */
    protected function berkasFilterOptions(): array
    {
        $values = SrikandiNaskah::query()
            ->distinct()
            ->pluck('status_berkas')
            ->filter(fn ($value): bool => $value !== null && trim((string) $value) !== '')
            ->map(fn ($value): string => (string) $value)
            ->unique()
            ->sort()
            ->values();

        return $values->mapWithKeys(fn (string $value): array => [$value => $value])->all();
    }

    /**
     * Opsi filter `tahun`, termurah ke termahal.
     *
     * `0` tidak dibuang — lihat catatan di docblock class.
     *
     * @return array<int, string>
     */
    protected function tahunFilterOptions(): array
    {
        $years = SrikandiNaskah::query()
            ->distinct()
            ->orderByDesc('tahun')
            ->pluck('tahun')
            ->map(fn ($value): int => (int) $value);

        return $years->mapWithKeys(
            fn (int $year): array => [$year => $year === 0 ? __('0 — tahun tidak diketahui') : (string) $year]
        )->all();
    }
}
