<?php

namespace Bale\Srikandi\Models;

use Bale\Srikandi\Support\BlindIndex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * State satu permintaan kode OTP Srikandi (spec §3.1).
 *
 * 🔴 `code_hash` TIDAK pernah dikembalikan ke scraper dan tidak pernah muncul
 * di log. Yang disimpan hanya hash-nya; bentuk polosnya hidup sedetik di
 * memori sambil dikirim lewat WhatsApp lalu dibuang.
 *
 * @property string $id
 * @property string $purpose
 * @property string $phone
 * @property string $state
 * @property string|null $session_key
 * @property string $code_hash
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $verified_at
 * @property Carbon|null $consumed_at
 * @property array|null $metadata
 */
class SrikandiOtpState extends Model
{
    use HasUuids;

    protected $table = 'srikandi_otp_states';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * 🔴 `code_hash` TIDAK ada di sini.
     *
     * Kolom itu ikut terisi di dalam model, tapi tidak boleh bisa diisi dari
     * luar — pemanggil menentukan `$code` polos, dan service yang menghitung
     * hash-nya. Kalau `code_hash` fillable, siapa pun yang punya akses model
     * bisa menimpa hash dengan hash miliknya sendiri.
     */
    protected $fillable = [
        'sumber',
        'request_id',
        'purpose',
        'phone',
        'state',
        'session_key',
        'attempts',
        'expires_at',
        'opened_at',
        'verified_at',
        'consumed_at',
        'metadata',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected $casts = [
        // 🔴 `phone` terenkripsi. Konsekuensinya tidak bisa dicari dengan
        // `where('phone', ...)`: yang tersimpan ciphertext, jadi query itu
        // tidak error tapi selalu kosong. Gunakan `scopeWherePhone()`.
        'phone' => 'encrypted',

        'attempts' => 'integer',
        'expires_at' => 'datetime',
        'opened_at' => 'datetime',
        'verified_at' => 'datetime',
        'consumed_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * 🔴 Cari state by nomor tujuan lewat blind index.
     *
     * Ada sebagai scope, bukan `where('phone', ...)` di pemanggil, karena
     * kesalahan di sini tidak kelihatan: query-nya jalan, hanya mengembalikan
     * nol baris. Gejalanya "balasan OTP tidak pernah sampai".
     */
    public function scopeWherePhone(Builder $query, string $phone): Builder
    {
        return $query->where('phone_index', (new BlindIndex)->make($phone));
    }

    /**
     * 🔴 Sinkronkan `phone_index` setiap kali `phone` berubah.
     *
     * Tanpa ini, index menunjuk nomor lama dan pencarian kembali nomor yang
     * sudah diganti -- sehingga balasan OTP untuk nomor baru tidak pernah
     * match, sementara request-nya sudah tercatat.
     */
    protected static function booted(): void
    {
        static::saving(function (self $state) {
            if ($state->isDirty('phone')) {
                $state->setAttribute(
                    'phone_index',
                    (new BlindIndex)->make($state->getAttribute('phone')) ?: null
                );
            }
        });
    }

    public const STATE_PENDING = 'pending';

    public const STATE_VERIFIED = 'verified';

    public const STATE_CONSUMED = 'consumed';

    public const STATE_EXPIRED = 'expired';

    /**
     * Set hash kode OTP. Satu-satunya jalan masuk ke kolom ini.
     */
    public function setCodeHash(string $hash): self
    {
        $this->setAttribute('code_hash', $hash);

        return $this;
    }

    /**
     * Record ini JENDELA LISTENING, bukan vertebrae OTP buatan Bale (spec §5.0b).
     *
     * Jendela tidak punya kode: kode OTP yang benar datang dari Srikandi, bukan
     * dari Bale. Karena itu `code_hash` NULL adalah kondisi normal — bukan data
     * rusak — dan seluruh machinery `code_hash` + `otp-verify` tidak berlaku
     * untuknya.
     */
    public function isWindow(): bool
    {
        return $this->getAttribute('code_hash') === null;
    }

    public function isPending(): bool
    {
        return $this->state === self::STATE_PENDING;
    }

    public function isVerified(): bool
    {
        return $this->state === self::STATE_VERIFIED;
    }

    public function isExpired(): bool
    {
        if ($this->state === self::STATE_EXPIRED) {
            return true;
        }

        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Record yang masih bisa diverifikasi: `pending` DAN belum kedaluwarsa.
     *
     * Syarat `state` dan `expires_at` keduanya wajib. Hanya salah satu akan
     * membuka dua celah: `pending` saja menganggap kode kedaluwarsa masih
     * berlaku; `expires_at` saja membuat record `verified`/`consumed` bisa
     * dibalik jadi pending oleh kiriman yang telat.
     *
     * Berlaku juga untuk jendela listening: jendela punya `expires_at` dan
     * harus ikut kedaluwarsa supaya `otp-pending` berhenti mengembalikan
     * balasan untuk siklus yang sudah lewat.
     */
    public function isVerifiable(): bool
    {
        return $this->isPending() && ! $this->isExpired();
    }

    /**
     * Batas bawah jendela baca: pesan hanya dibaca sejak jendela dibuka.
     *
     * Record lama (dibuat sebelum kontrak v2) tidak punya `opened_at`. Untuk
     * mereka batas bawahnya `created_at` — mengubahnya jadi NULL membuat
     * jendela tidak berbatas dan `otp-pending` kembali membaca pesan lama.
     */
    public function windowOpenedAt(): Carbon
    {
        return $this->opened_at ?? $this->created_at;
    }

    /**
     * Jumlah percobaan yang boleh dipakai lagi sebelum `expired`.
     */
    public function attemptsRemaining(): int
    {
        return max(0, (int) config('srikandi.otp.max_attempts', 5) - $this->attempts);
    }
}
