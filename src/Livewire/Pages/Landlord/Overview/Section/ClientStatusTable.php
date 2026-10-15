<?php

namespace Bale\Srikandi\Livewire\Pages\Landlord\Overview\Section;

use Bale\Srikandi\Livewire\Pages\Landlord\Overview\Concerns\ResolvesBulan;
use Bale\Srikandi\Models\SrikandiClient;
use Bale\Srikandi\Models\SrikandiNaskah;
use Bale\Srikandi\Models\SrikandiSessionSnapshot;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Tabel kesehatan tiap client Srikandi.
 *
 * 🔴 Baris dibangun di `render()` lalu diteruskan sebagai array, BUKAN dengan
 * memanggil method publik dari Blade (`$this->sessionStatusBadge($session)`).
 *
 * Method publik pada komponen Livewire dapat dipanggil dari frontend. Method
 * yang menerima argumen model akan gagal di-resolve saat dipanggil tanpa test,
 * tapi tetap terdaftar sebagai aksi yang bisa dipanggil siapa pun yang bisa
 * memanggil komponen. Membangun barisnya di dalam komponen menutup pintu itu,
 * dan `sessionStatusBadge()` cukup `protected`.
 *
 * 🔴 Status kesehatan diambil dari SESI SCRAPER TERBARU
 * (`srikandi_session_snapshots`), bukan dari kolom heartbeat `last_error_at`.
 *
 * Snapshot adalah kiriman paling akhir dan paling lengkap dari scraper: status
 * `success`/`failed` satu sesi. Heartbeat `last_error_at` adalah riwayat yang
 * sengaja tidak dikosongkan saat siklus berikutnya sukses, jadi memakainya
 * membuat client yang sudah sehat tetap merah. Kolom Login/Naskah Terakhir juga
 * digantikan `finished_at` sesi terbaru -- satu sumber kebenaran untuk "kapan
 * terakhir client bekerja".
 *
 * 🔴 Tabel ini tetap TIDAK ikut disaring bulan. Angka naskah ikut bulan
 * terpilih; status & sesi terakhir adalah kondisi SEKARANG.
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

        $latestSessions = $this->latestSessionBySlug($clients->pluck('slug')->all());

        $rows = $clients->map(fn (SrikandiClient $client): array => [
            'client' => $client,
            'naskah' => (int) ($monthCounts[$client->slug] ?? 0),
            'status' => $this->sessionStatusBadge($latestSessions[$client->slug] ?? null),
            'session' => $latestSessions[$client->slug] ?? null,
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
     * Status kesehatan dari sesi scraper TERBARU.
     *
     *  - belum ada sesi  -> "Belum ada sesi" (abu)
     *  - sesi gagal      -> "Gagal" (merah), `error_code` jadi tooltip
     *  - sesi sukses     -> "Sukses" (hijau)
     *
     * 🔴 Pakai `isFailed()`, bukan `$session->status === 'failed'` yang ditulis
     * ulang -- definisi "gagal" harus satu sumber dengan badge Log Sesi.
     *
     * @return array{0: string, 1: string}
     */
    protected function sessionStatusBadge(?SrikandiSessionSnapshot $session): array
    {
        if ($session === null) {
            return [__('Belum ada sesi'), 'gray'];
        }

        return $session->isFailed()
            ? [__('Gagal'), 'red']
            : [__('Sukses'), 'emerald'];
    }

    /**
     * Sesi TERBARU tiap slug, satu query untuk semua client.
     *
     * 🔴 `MAX(finished_at)` + `groupBy` + `joinSub` DB-agnostic (MySQL prod,
     * SQLite test); tidak ada `GROUP_CONCAT`/window function yang berbeda antar
     * driver. Ambil baris dengan `finished_at` terbesar per slug, lalu
     * `unique('sumber')` sebagai jaring terakhir kalau dua sesi selesai pada
     * detik yang sama.
     *
     * @param  array<int, string>  $slugs
     * @return array<string, SrikandiSessionSnapshot>
     */
    protected function latestSessionBySlug(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $latestFinished = SrikandiSessionSnapshot::query()
            ->selectRaw('sumber, MAX(finished_at) AS max_finished_at')
            ->whereIn('sumber', $slugs)
            ->groupBy('sumber');

        return SrikandiSessionSnapshot::query()
            ->joinSub($latestFinished, 'latest_session', function ($join): void {
                $join->on('srikandi_session_snapshots.sumber', '=', 'latest_session.sumber')
                    ->on('srikandi_session_snapshots.finished_at', '=', 'latest_session.max_finished_at');
            })
            ->orderByDesc('srikandi_session_snapshots.finished_at')
            ->get(['srikandi_session_snapshots.*'])
            ->unique('sumber')
            ->keyBy('sumber')
            ->all();
    }
}
