<?php

namespace Bale\Srikandi\Commands;

use Bale\Srikandi\Models\SrikandiSessionSnapshot;
use Illuminate\Console\Command;

/**
 * Hapus snapshot sesi scraper yang sudah lama.
 *
 * 🔴 Kenapa perlu command terpisah: `srikandi_session_snapshots` tumbuh
 * sekitar satu-dua baris per client per HARI dan tidak pernah dipakai untuk
 * membaca ulang naskah -- isinya murni diagnostik. Tanpa prune, tabel ini
 * tumbuh selamanya sementara nilainya hanya berguna beberapa minggu.
 *
 * Ambang default dari `config('srikandi.snapshot.retention_days')`; bisa
 * ditimpa dengan `--days`. `--dry-run` menghitung saja, tidak menghapus.
 */
class PruneSessionSnapshotsCommand extends Command
{
    protected $signature = 'srikandi:prune-session-snapshots
        {--days= : Simpan snapshot lebih baru dari N hari (default: config)}
        {--dry-run : Hitung saja, jangan hapus}';

    protected $description = 'Hapus snapshot sesi scraper yang lebih tua dari ambang retensi';

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('srikandi.snapshot.retention_days', 90);

        if ($days < 1) {
            $this->error('--days harus minimal 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $query = SrikandiSessionSnapshot::query()->where('started_at', '<', $cutoff);
        $count = $query->count();

        if ($this->option('dry-run')) {
            $this->info(sprintf(
                '[dry-run] %d snapshot sebelum %s akan dihapus (retensi %d hari).',
                $count,
                $cutoff->toDateTimeString(),
                $days,
            ));

            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->info(sprintf('Tidak ada snapshot lebih tua dari %d hari.', $days));

            return self::SUCCESS;
        }

        // `delete()` pada query langsung, bukan `->each()`: jumlahnya bisa
        // besar dan tiap model tidak perlu dihidrasi untuk dihapus.
        $deleted = $query->delete();

        $this->info(sprintf('Menghapus %d snapshot lebih tua dari %s (retensi %d hari).', $deleted, $cutoff->toDateTimeString(), $days));

        return self::SUCCESS;
    }
}
