<?php

namespace Bale\Srikandi\Livewire\Pages\Landlord\Overview\Section;

use Bale\Srikandi\Livewire\Pages\Landlord\Overview\Concerns\ResolvesBulan;
use Bale\Srikandi\Models\SrikandiClient;
use Bale\Srikandi\Models\SrikandiNaskah;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Tabel kesehatan tiap client Srikandi.
 *
 * 🔴 Baris dibangun di `render()` lalu diteruskan sebagai array, BUKAN dengan
 * memanggil method publik dari Blade (`$this->statusBadge($client)`).
 *
 * Method publik pada komponen Livewire dapat dipanggil dari frontend. Method
 * yang menerima argumen model akan gagal di-resolve saat dipanggil tanpa test,
 * tapi tetap terdaftar sebagai aksi yang bisa dipanggil siapa pun yang bisa
 * memanggil komponen. Membangun barisnya di dalam komponen menutup pintu itu,
 * dan `statusBadge()` cukup `protected`.
 *
 * 🔴 Status "error" dilihat dari `last_error_at` 24 jam terakhir, bukan dari
 * `last_error_message` yang mungkin tidak pernah dikosongkan.
 *
 * Pesan error lama sengaja TIDAK dihapus saat siklus berikutnya sukses (kolomnya
 * riwayat terakhir). Kalau badge merah hanya melihat ada/tidaknya pesan, client
 * yang sudah sehat akan tetap merah selamanya.
 */
class ClientStatusTable extends Component
{
    use AuthorizesRequests;
    use ResolvesBulan;

    public string $bulan = '';

    public function mount(string $bulan = ''): void
    {
        $this->authorize('srikandi.client.read');
        $this->bulan = $this->normalizeBulan($bulan);
    }

    public function render()
    {
        [$start, $end] = $this->bulanTanggalRange($this->bulan);

        $monthCounts = SrikandiNaskah::query()
            ->whereBetween('tanggal_naskah', [$start, $end])
            ->selectRaw('sumber, COUNT(*) as total')
            ->groupBy('sumber')
            ->pluck('total', 'sumber');

        $clients = SrikandiClient::query()
            ->with('waraClient')
            ->withCount([
                'notifyRecipients as active_recipients_count' => fn ($query) => $query->where('is_active', true),
            ])
            ->orderBy('nama')
            ->get();

        $rows = $clients->map(fn (SrikandiClient $client): array => [
            'client' => $client,
            'naskah' => (int) ($monthCounts[$client->slug] ?? 0),
            'status' => $this->statusBadge($client),
            'notifikasi' => $client->notify_enabled
                ? [__('Aktif'), 'emerald']
                : [__('Nonaktif'), 'gray'],
            'recipients' => (int) $client->active_recipients_count,
        ]);

        return view('srikandi::livewire.pages.landlord.overview.section.client-status-table', [
            'bulanLabel' => $this->bulanLabel($this->bulan),
            'rows' => $rows,
            'totalClients' => $rows->count(),
        ]);
    }

    /**
     * Status kesehatan ringkas satu client.
     *
     * Urutannya penting: error terbaru menang atas yang lain. Client nonaktif
     * yang baru saja error tetap layak ditandai merah -- operator perlu tahu
     * ada yang gagal sebelum ia memutuskan mengaktifkannya kembali.
     *
     * @return array{0: string, 1: string}
     */
    protected function statusBadge(SrikandiClient $client): array
    {
        if ($client->last_error_at !== null && $client->last_error_at->gte(now()->subDay())) {
            return [__('Error'), 'red'];
        }

        if (! $client->isActive()) {
            return [__('Nonaktif'), 'gray'];
        }

        if ($client->isReadyForScraper()) {
            return [__('Siap'), 'emerald'];
        }

        return [__('Belum siap'), 'amber'];
    }
}
