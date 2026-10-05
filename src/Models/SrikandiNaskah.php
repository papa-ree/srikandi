<?php

namespace Bale\Srikandi\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Cache satu baris list naskah dinas dari Srikandi (spec §3.2).
 *
 * Ini SALINAN, bukan sumber kebenaran. Naskah asli tetap milik Srikandi; tabel
 * ini hanya menyimpan potret yang dibaca scraper supaya Bale tidak perlu login
 * berulang ke Srikandi hanya untuk tahu "naskah ini sudah pernah masuk".
 *
 * 🔴 Primary key UUID, bukan auto-increment. Konsisten dengan `bale_lists`,
 * `api_tokens`, dan `wara_clients`, dan supaya tidak perlu koordinasi dengan
 * ID yang dibangkitkan database.
 *
 * @property string $id
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
    use HasUuids;

    protected $table = 'srikandi_naskah';

    protected $fillable = [
        'sumber',
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
     * 🔴 `sumber` adalah kolom PERTAMA kunci ini, dan itu wajib.
     *
     * Tanpa `sumber`, dua akun Srikandi di instalasi yang sama yang punya naskah
     * bernomor sama akan saling menimpa. Yang terjadi bukan error, melainkan
     * `unchanged` -- jadi satu akun diam-diam kehilangan riwayat naskahnya dan
     * tidak ada yang diberi tahu.
     *
     * `sumber` = `srikandi_clients.slug`. Sengaja BUKAN `session_key`: session
     * berubah setiap siklus login, sedangkan naskah bertahan lama.
     *
     * `tahun` ikut masuk karena `nomor_naskah` yang sama bisa muncul di tahun
     * berbeda.
     */
    public function dedupKey(): string
    {
        return implode('|', [
            $this->sumber,
            $this->nomor_naskah,
            $this->tahun,
        ]);
    }

    /**
     * Nilai sentinel untuk "naskah ini tidak punya berkas".
     *
     * 🔴 BUKAN string kosong dan bukan null.
     *
     * `status_berkas` disimpan apa adanya (lihat `hasBerkas()`), jadi nilainya
     * bisa `null` atau `''`. Keduanya itu "tidak ada berkas" -- tapi tidak bisa
     * dipakai sebagai nilai filter, karena `where('status_berkas', null)` dan
     * `where('status_berkas', '')` punya arti berbeda di SQL, dan
     * `whereNotNull()` tidak sama dengan "tidak ada berkas bernama tertentu".
     * Sentinel ini memisahkan "nilai yang dicari" dari "bentuk aslinya".
     */
    public const BERKAS_TIDAK_ADA = 'TIDAK_ADA';

    public function scopeSumber(Builder $query, string $sumber): Builder
    {
        return $query->where('sumber', $sumber);
    }

    public function scopeTahun(Builder $query, int $tahun): Builder
    {
        return $query->where('tahun', $tahun);
    }

    public function scopeBerkasState(Builder $query, string $state): Builder
    {
        // 🔴 `status_berkas` nullable, jadi "tidak punya berkas" harus ditulis
        // eksplisit. `where('status_berkas', $state)` tidak pernah cocok untuk
        // nilai NULL, dan `whereNotNull()` tidak sama dengan "tidak punya berkas
        // yang namanya persis `state`".
        if ($state === self::BERKAS_TIDAK_ADA) {
            return $query->where(function (Builder $q) {
                $q->whereNull('status_berkas')->orWhere('status_berkas', '');
            });
        }

        return $query->where('status_berkas', $state);
    }

    /**
     * Naskah yang masih terlihat di list Srikandi.
     *
     * 🔴 WAJIB SEPAKAT dengan {@see self::isStale()} pada ambang menit yang sama.
     *
     * Scope ini meng-bound `last_seen_at` di database supaya index
     * `['sumber', 'tahun', 'last_seen_at']` dipakai. `isStale()` versi PHP
     * mengiterasi seluruh baris -- mahal pada data besar. Dua-duanya harus
     * menyepakati batas yang sama, kalau tidak card ringkasan di header dan
     * filter di tabel akan menjawab pertanyaan berbeda untuk data yang sama,
     * dan itu kelas bug yang tidak terlihat dari halaman mana pun.
     *
     * Test `NaskahScopeTest` mengunci kesepakatan ini.
     */
    public function scopeFresh(Builder $query, int $thresholdMinutes = 1440): Builder
    {
        return $query->where('last_seen_at', '>=', now()->subMinutes($thresholdMinutes));
    }

    /**
     * Kebalikan dari {@see self::scopeFresh()} — naskah yang TIDAK terlihat lagi.
     *
     * Ada sebagai scope terpisah, bukan `->whereNot('last_seen_at', ...)`,
     * karena batasnya harus persis kebalikan dari `scopeFresh()` pada ambang yang
     * sama. Menuliskannya ulang di pemanggil berarti ada tempat yang bisa
     * keluar dari kesepakatan dengan `isStale()`.
     */
    public function scopeNotFresh(Builder $query, int $thresholdMinutes = 1440): Builder
    {
        return $query->where('last_seen_at', '<', now()->subMinutes($thresholdMinutes));
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
     *
     * 🔴 `startOfSecond()` itu wajib, bukan {@see self::scopeNotFresh()} yang sia-sia.
     *
     * Kolom `timestamp` di MySQL dan SQLite menyimpan presisi DETIK. Tapi
     * `Carbon::lt()` di sini tanpa `startOfSecond()` akan membandingkan sampai
     * mikrodetik, sehingga baris yang `last_seen_at`-nya persis satu menit
     * lalu bisa dianggap basi oleh PHP dan SEGAR oleh query — atau sebaliknya,
     * tergantung apakah `now()` dipanggil sebelum atau sesudah insert.
     *
     * Efeknya bukan crash: naskah tepat di batas exibitasikan sebagai basi di
     * card ringkasan dan sebagai segar di tabel. `NaskahSumberTest`
     * mengunci kesepakatan ini, jadi `startOfSecond()` tidak boleh dihapus
     * tanpa menyetel ulang testnya.
     */
    public function isStale(int $thresholdMinutes = 1440): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->lt(now()->subMinutes($thresholdMinutes)->startOfSecond());
    }
}
