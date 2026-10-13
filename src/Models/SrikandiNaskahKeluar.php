<?php

namespace Bale\Srikandi\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Cache satu baris list Naskah Keluar dari Srikandi (PRD §4.1c).
 *
 * Ini SALINAN, bukan sumber kebenaran. Naskah asli tetap milik Srikandi; tabel
 * ini hanya menyimpan potret yang dibaca scraper.
 *
 * 🔴 TABEL TERPISAH dari `srikandi_naskah`, dan itu keputusan yang disengaja.
 * Baris Naskah Keluar tidak punya `status_baca`/`status_tindak`/`status_berkas`
 * di level baris; yang ada `status` dan `status_send`. Satu tabel dengan kolom
 * `jenis` akan membuat status berarti dua hal berbeda tergantung barisnya.
 *
 * 🔴 Primary key UUID, konsisten dengan `srikandi_naskah`.
 *
 * @property string $id
 * @property string $sumber
 * @property string $nomor_naskah
 * @property int $tahun
 * @property string|null $tanggal_naskah
 * @property string|null $hal
 * @property string|null $ringkasan
 * @property string|null $tujuan_naskah
 * @property string|null $status_tindak_lanjut
 * @property string|null $status_baca
 * @property string|null $status_berkas
 * @property int|null $no_urut
 * @property string|null $file_path
 * @property string|null $file_name_original
 * @property string|null $row_hash
 * @property string|null $hash_value
 * @property array|null $snapshot
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 */
class SrikandiNaskahKeluar extends Model
{
    use HasUuids;

    protected $table = 'srikandi_naskah_keluar';

    protected $fillable = [
        'sumber',
        'nomor_naskah',
        'tahun',
        'tanggal_naskah',
        'hal',
        'ringkasan',
        'tujuan_naskah',
        'status_tindak_lanjut',
        'status_baca',
        'status_berkas',
        'no_urut',
        'file_path',
        'file_name_original',
        'row_hash',
        'hash_value',
        'snapshot',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'tahun' => 'integer',
        'no_urut' => 'integer',
        'tanggal_naskah' => 'date',
        'snapshot' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /**
     * Kunci dedup (PRD §4.1c).
     *
     * 🔴 `sumber` kolom PERTAMA, dan itu wajib. Tanpa `sumber`, dua akun
     * Srikandi dengan nomor naskah sama akan saling menimpa, dan yang terjadi
     * bukan error melainkan `unchanged` -- satu akun diam-diam kehilangan
     * riwayatnya.
     */
    public function dedupKey(): string
    {
        return implode('|', [
            $this->sumber,
            $this->nomor_naskah,
            $this->tahun,
        ]);
    }

    public function scopeSumber(Builder $query, string $sumber): Builder
    {
        return $query->where('sumber', $sumber);
    }

    public function scopeTahun(Builder $query, int $tahun): Builder
    {
        return $query->where('tahun', $tahun);
    }

    /**
     * Naskah yang masih terlihat di list Srikandi.
     *
     * Sepakat dengan `srikandi_naskah` pada ambang menit yang sama supaya
     * "segar/basi" berarti hal yang sama di dua tabel.
     */
    public function scopeFresh(Builder $query, int $thresholdMinutes = 1440): Builder
    {
        return $query->where('last_seen_at', '>=', now()->subMinutes($thresholdMinutes));
    }

    public function scopeNotFresh(Builder $query, int $thresholdMinutes = 1440): Builder
    {
        return $query->where('last_seen_at', '<', now()->subMinutes($thresholdMinutes));
    }

    public function isStale(int $thresholdMinutes = 1440): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->lt(now()->subMinutes($thresholdMinutes)->startOfSecond());
    }
}
