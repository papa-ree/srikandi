<?php

namespace Bale\Srikandi\Models;

use Bale\Core\Traits\LogsActivity;
use Bale\Srikandi\Support\BlindIndex;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Satu penerima notifikasi NASKAH BARU milik satu client Srikandi.
 *
 * 🔴 BUKAN TEMPAT NOTIFIKASI OTP GAGAL/KEDALUWARSA
 *
 * Notifikasi OTP gagal/kedaluwarsa dikirim ke `srikandi_clients.phone`
 * (nomor akun). Daftar ini HANYA untuk notifikasi naskah baru (S5.2).
 *
 * 🔴 NILAI TERENKRIPSI, TAPI BUKAN KREDENSIAL
 *
 * `value` (nomor penerima) memakai cast `encrypted` supaya konsisten dengan
 * `phone` di `srikandi_clients`. Namun berbeda dari password/TOTP, nomor ini
 * HARUS bisa dibaca admin untuk dikelola (tambah/hapus/aktif). Karena itu
 * model ini tidak menyembunyikannya dari form seperti `secret-field`; yang
 * dijaga hanya agar tidak bocor ke `toArray()` / log.
 *
 * 🔴 `value_index` HARUS ikut ditulis setiap `value` berubah
 *
 * Kolom `value` terenkripsi sehingga tidak bisa dipakai di `WHERE`. Deduplikasi
 * "satu nomor sekali per client" memakai `value_index` (HMAC dari nilai
 * ternormalisasi). Kalau index tidak ikut ditulis saat `value` berubah, dua
 * baris akan tampak berbeda padahal nomornya sama — dan notifikasi terkirim
 * dua kali. `setValue()` adalah satu-satunya jalan masuknya.
 *
 * @property string $id
 * @property string $client_id
 * @property string $type
 * @property string|null $label
 * @property string $value
 * @property string|null $value_index
 * @property bool $is_active
 */
class SrikandiNotifyRecipient extends Model
{
    use HasUuids;
    use LogsActivity;

    /** Penerima berupa nomor telepon. Satu-satunya yang dipakai saat ini. */
    public const TYPE_PHONE = 'phone';

    /** Penerima berupa id grup WhatsApp. Disiapkan, belum didukung jalur kirim. */
    public const TYPE_GROUP = 'group';

    protected $table = 'srikandi_notify_recipients';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * `value` TIDAK ada di sini: nilainya harus lewat {@see self::setValue()}
     * supaya `value_index` selalu ikut diperbarui. `client_id` juga di luar
     * `$fillable` karena penerima selalu dibuat lewat relasi milik client-nya.
     */
    protected $fillable = [
        'type',
        'label',
        'is_active',
    ];

    /**
     * Nomor penerima dan index tidak ikut serialisasi. `value` tetap bisa dibaca
     * eksplisit (`$recipient->value`) oleh form pengelolaan.
     */
    protected $hidden = [
        'value_index',
    ];

    protected $casts = [
        'value' => 'encrypted',
        'is_active' => 'boolean',
    ];

    /**
     * 🔴 `value` sengaja TIDAK di-log.
     *
     * Spatie v5 memanggil `getAttribute()`, yang menjalankan cast `encrypted`
     * dan menghasilkan teks polos. Untuk kredensial itu kebocoran; untuk nomor
     * notifikasi pun tidak perlu — yang berguna ditelusuri adalah "penerima
     * mana yang ditambah/diubah/dinonaktifkan", bukan nomornya.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'type',
                'label',
                'is_active',
            ])
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(SrikandiClient::class, 'client_id');
    }

    /**
     * Set nomor penerima dan hitung ulang `value_index` dalam satu jalan.
     *
     * Satu-satunya jalan masuk ke kolom `value`. Lihat catatan di docblock class.
     */
    public function setValue(string $value): self
    {
        $this->setAttribute('value', $value);
        $this->setAttribute('value_index', (new BlindIndex)->make($value) ?: null);

        return $this;
    }

    /**
     * Cari penerima dengan nomor tertentu di dalam satu client, lewat blind index.
     *
     * `value` terenkripsi, jadi `where('value', ...)` tidak akan pernah cocok.
     */
    public function scopeWhereValue($query, string $value)
    {
        return $query->where('value_index', (new BlindIndex)->make($value));
    }
}
