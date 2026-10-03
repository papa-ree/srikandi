<?php

namespace Bale\Srikandi\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cache satu baris list naskah dinas dari Srikandi (spec §3.2).
 *
 * Ini SALINAN, bukan sumber kebenaran. Naskah asli tetap milik Srikandi; tabel
 * ini hanya menyimpan potret yang dibaca scraper supaya Bale tidak perlu login
 * berulang ke Srikandi hanya untuk tahu "naskah ini sudah pernah masuk".
 *
 * @property int $id
 * @property string $bale_id
 * @property string $nomor_naskah
 * @property int $tahun
 * @property string|null $tanggal_naskah
 * @property string|null $hal
 * @property string|null $ringkasan
 * @property string|null $pengirim
 * @property string|null $status_baca
 * @property string|null $status_tindak
 * @property string|null $status_berkas
 * @property string|null $row_hash
 * @property array|null $snapshot
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 */
class SrikandiNaskah extends Model
{
    protected $table = 'srikandi_naskah';

    protected $fillable = [
        'bale_id',
        'nomor_naskah',
        'tahun',
        'tanggal_naskah',
        'hal',
        'ringkasan',
        'pengirim',
        'status_baca',
        'status_tindak',
        'status_berkas',
        'row_hash',
        'snapshot',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'tahun' => 'integer',
        'tanggal_naskah' => 'date',
        'snapshot' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /**
     * Kunci dedup (spec §3.2).
     *
     * `tahun` ikut masuk karena `nomor_naskah` yang sama bisa muncul di tahun
     * berbeda.
     */
    public function dedupKey(): string
    {
        return implode('|', [
            $this->bale_id,
            $this->nomor_naskah,
            $this->tahun,
        ]);
    }

    /**
     * 🔴 `status_berkas` disimpan apa adanya (spec §3.2).
     *
     * Di payload JSON nilainya `null`; badge DOM menormalkan jadi `TIDAK ADA`.
     * Yang benar untuk disimpan adalah bentuk mentah — kalau nanti verstinya
     * berubah jadi array, kolom ini yang paling sering diam-diam rusak, dan
     * gejalanya baris tidak pernah ter-update.
     */
    public function hasBerkas(): bool
    {
        return $this->status_berkas !== null && trim($this->status_berkas) !== '';
    }

    /**
     * Naskah yang tidak terlihat lagi di list Srikandi.
     *
     * `last_seen_at` yang tidak diperbarui sementara — bukan dihapus, karena
     * riwayat naskah tetap perlu bisa ditelusuri.
     */
    public function isStale(int $thresholdMinutes = 1440): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->lt(now()->subMinutes($thresholdMinutes));
    }
}
