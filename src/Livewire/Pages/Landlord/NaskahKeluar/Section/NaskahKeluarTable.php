<?php

namespace Bale\Srikandi\Livewire\Pages\Landlord\NaskahKeluar\Section;

use Bale\Srikandi\Models\SrikandiNaskahKeluar;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Tabel Naskah Keluar. Read-only: tidak ada `Form`, tidak ada `deleteEvent`.
 *
 * 🔴 Opsi filter dibangun dari `distinct()->pluck()`, BUKAN daftar konstanta.
 *
 * `status_tindak_lanjut` datang dari sistem luar (nilai `status_send.status`
 * Srikandi, mis. "KIRIM"/"BELUM"). Kalau daftarnya ditutup di sini, begitu
 * Srikandi menambah status baru baris itu masih tampil di tabel tapi tidak bisa
 * dicari -- dan tidak ada jejak bahwa filternya sudah usang.
 *
 * 🔴 Tahun `0` ("tahun tidak diketahui") tetap jadi opsi.
 *
 * `0` falsy di PHP, jadi filter yang membuang nilai falsy akan membuat baris
 * tanpa tahun mustahil dicari -- dan itu justru kelompok yang paling perlu
 * dilihat, karena `tahun` yang hilang biasanya berarti sumber datanya masih
 * bermasalah.
 */
class NaskahKeluarTable extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('srikandi.naskah.keluar.read');
    }

    public function render()
    {
        return view('srikandi::livewire.pages.landlord.naskah-keluar.section.naskah-keluar-table', [
            'statusFilter' => $this->statusFilterOptions(),
            'tahunFilter' => $this->tahunFilterOptions(),
        ]);
    }

    /**
     * Opsi filter `status_tindak_lanjut`.
     *
     * 🔴 Nilai NULL ikut jadi opsi lewat `nullLabel`, bukan dibuang.
     *
     * `data-table` memetakan nilai NULL ke sentinel string `'null'` lalu
     * menulisnya jadi `whereNull()`. Tanpa itu, semua naskah yang belum punya
     * status tindak lanjut mustahil difilter.
     *
     * @return array<string, string>
     */
    protected function statusFilterOptions(): array
    {
        $values = SrikandiNaskahKeluar::query()
            ->distinct()
            ->pluck('status_tindak_lanjut')
            ->filter(fn ($value): bool => $value !== null && trim((string) $value) !== '')
            ->map(fn ($value): string => (string) $value)
            ->unique()
            ->sort()
            ->values();

        return $values->mapWithKeys(fn (string $value): array => [$value => $value])->all();
    }

    /**
     * Opsi filter `tahun`, termahal ke termurah.
     *
     * `0` tidak dibuang -- lihat catatan di docblock class.
     *
     * @return array<int, string>
     */
    protected function tahunFilterOptions(): array
    {
        $years = SrikandiNaskahKeluar::query()
            ->distinct()
            ->orderByDesc('tahun')
            ->pluck('tahun')
            ->map(fn ($value): int => (int) $value);

        return $years->mapWithKeys(
            fn (int $year): array => [$year => $year === 0 ? __('0 -- tahun tidak diketahui') : (string) $year]
        )->all();
    }
}
