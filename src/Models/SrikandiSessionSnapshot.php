<?php

namespace Bale\Srikandi\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Snapshot detail panen satu SESI scraper Srikandi (rak-srikandi PLAN 2026-10-14).
 *
 * Berbeda dari {@see SrikandiNaskah}/{@see SrikandiNaskahKeluar} yang satu
 * baris = satu DOKUMEN, di sini satu baris = satu SESI. Isi `snapshots` adalah
 * array ringkasan per jenis (`naskah masuk`/`naskah keluar`) yang dibaca
 * scraper selama sesi itu.
 *
 * 🔴 Ini laporan DIAGNOSTIK baca-saja. Tidak ada yang boleh memutuskan
 * status naskah dari tabel ini; naskah tetap ditentukan `row_hash` di tabelnya
 * masing-masing.
 *
 * 🔴 Idempoten lewat `(sumber, started_at, finished_at)`. Scraper menelan
 * error kiriman (non-fatal), jadi sesi yang sama bisa dikirim ulang; unique
 * key di DB yang menjaganya, bukan asumsi bahwa kiriman hanya sekali.
 *
 * Primary key UUID, konsisten dengan tabel Srikandi lain.
 *
 * @property string $id
 * @property string $sumber
 * @property Carbon $started_at
 * @property Carbon $finished_at
 * @property string $status
 * @property string|null $error_code
 * @property Carbon|null $login_at
 * @property Carbon|null $naskah_at
 * @property array|null $snapshots
 */
class SrikandiSessionSnapshot extends Model
{
    use HasUuids;

    protected $table = 'srikandi_session_snapshots';

    protected $fillable = [
        'sumber',
        'started_at',
        'finished_at',
        'status',
        'error_code',
        'login_at',
        'naskah_at',
        'snapshots',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'login_at' => 'datetime',
        'naskah_at' => 'datetime',
        'snapshots' => 'array',
    ];

    /**
     * Kunci idempotensi. `sumber` kolom PERTAMA -- dua client bisa punya sesi
     * dengan rentang identik, dan tanpa `sumber` yang kedua akan menimpa yang
     * pertama secara diam-diam.
     */
    public function dedupKey(): string
    {
        return implode('|', [
            $this->sumber,
            $this->started_at?->toDateTimeString(),
            $this->finished_at?->toDateTimeString(),
        ]);
    }

    public function scopeSumber(Builder $query, string $sumber): Builder
    {
        return $query->where('sumber', $sumber);
    }

    /**
     * Sesi yang gagal login/panen. Dipakai untuk halaman diagnostik nanti.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }
}
