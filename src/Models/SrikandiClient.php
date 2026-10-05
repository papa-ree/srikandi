<?php

namespace Bale\Srikandi\Models;

use Bale\Core\Traits\LogsActivity;
use Bale\Srikandi\Support\BlindIndex;
use Bale\Wara\Models\WaraClient;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Satu akun login Srikandi + satu device Wara (spec §3.1).
 *
 * 🔴 MENGAPA MODEL INI OVERRIDE `getActivitylogOptions()`
 *
 * `Bale\Core\Traits\LogsActivity` memakai `logAll()`. Itu berarti semua kolom
 * masuk `activity_log` -- termasuk yang terenkripsi.
 *
 * Dan itu berarti kebocoran: Spatie v5 di
 * `LogsActivity::resolveAttributeValue()` memanggil `$model->getAttribute($attr)`,
 * yang MENJALANKAN cast `encrypted`. Jadi `password` yang tersimpan sebagai
 * ciphertext di `srikandi_clients` akan ditulis sebagai TEKS POLOS ke
 * `activity_log.properties` setiap kali kolom itu berubah.
 *
 * Enkripsi kolom tidak mencegah ini. `activity_log` adalah tabel terpisah,
 * tidak terenkripsi, dan dibaca lebih sering daripada tabel asalnya.
 *
 * Dua lapis proteksi di sini:
 *   1. `logOnly()` -- kredensial tidak pernah jadi bagian log. Ini yang
 *      mencegah kebocoran.
 *   2. `credentials_rotated_at` justru DI-TARUH di `logOnly()`, jadi
 *      rotasi tercatat sebagai "kredensial berubah pada jam berapa"
 *      TANPA nilai barunya.
 *
 * 🔴 KEPUTUSAN YANG AWALNYA SALAH, DAN INI SEBENARNYA.
 *
 * Pertama-tama saya mencoba suppressing log sama sekali saat rotasi, dengan
 * alasan "entri tanpa nilai baru tidak menambah informasi". Itu keliru dua
 * kali. Yang membawa informasi justru KAPAN kredensial berubah, bukan
 * password barunya. Dan secara teknis suppressing juga tidak berhasil --
 * `dontLogIfAttributesChangedOnly()` membandingkan dengan `getDirty()` PENUH,
 * sedangkan `password` ada di sana walau tidak di-log.
 *
 * Jadi hasil akhirnya tetap benar (entri ada, isinya cuma timestamp), tapi
 * alasannya salah dan konsekuensinya akan terlihat: kalau suatu saat Spatie
 * memperbaiki `dontLogIfAttributesChangedOnly` supaya hanya membandingkan
 * kolom yang di-log, entri rotasi ini akan hilang tanpa ada yang memberitahu.
 * Test di `SrikandiClientCredentialTest` mengunci perilaku yang benar.
 *
 * 🔴 KOLOM TERENKripsi TIDAK BOLEH MASUK `$fillable` SECARA BUTA
 *
 * Kolom kredensial sengaja TIDAK ada di `$fillable`. Password tidak bisa diisi
 * lewat mass-assignment dari form karena harus lewat setter yang juga menghitung
 * `credentials_rotated_at`. Kalau fillable, siapa pun yang punya objek model
 * bisa menulis password tanpa timestamp rotasi ikut diperbarui -- dan Bale
 * tidak akan tahu kredensialnya berubah, jadi scraper tidak akan diberi tahu.
 *
 * @property string $id
 * @property string $slug
 * @property string $nama
 * @property string|null $username
 * @property string|null $password
 * @property string|null $totp_secret
 * @property int $totp_digits
 * @property int $totp_period
 * @property string|null $gemini_api_key
 * @property string|null $gemini_model
 * @property string|null $gemini_fallback_model
 * @property string|null $phone
 * @property string|null $phone_index
 * @property string|null $wara_client_id
 * @property string|null $otp_purpose
 * @property bool $notify_enabled
 * @property string $notify_via
 * @property string|null $notify_phone
 * @property string|null $notify_phone_index
 * @property string|null $notify_purpose
 * @property bool $is_active
 * @property Carbon|null $credentials_rotated_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $last_naskah_at
 * @property Carbon|null $last_error_at
 * @property string|null $last_error_message
 */
class SrikandiClient extends Model
{
    use HasUuids;
    use LogsActivity;

    protected $table = 'srikandi_clients';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * 🔴 Kolom kredensial TIDAK ada di sini, dengan sengaja.
     *
     * Lihat catatan di docblock class. Kredensial hanya masuk lewat
     * {@see self::setCredentials()} supaya `credentials_rotated_at` tidak
     * pernah bisa terlewat.
     */
    protected $fillable = [
        'slug',
        'nama',
        'totp_digits',
        'totp_period',
        'gemini_model',
        'gemini_fallback_model',
        'otp_purpose',
        'notify_enabled',
        'notify_via',
        'notify_purpose',
        'is_active',
    ];

    /**
     * 🔴 Override dari trait. Lihat alasan lengkap di docblock class.
     *
     * `useAttributeRawValues()` yang dipilih untuk kolom aman TIDAK ada di sini
     * karena nilai kolom aman tidak sensitif dan membacanya sebagai nilai mentah
     * akan membocorkan tipe: `is_active` akan ternilai `1`/`0` string, bukan
     * boolean. Untuk kolom yang tidak sensitif, baca ter-dekripsi adalah
     * perilaku yang benar.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'slug',
                'nama',
                'is_active',
                'notify_enabled',
                'notify_via',
                'totp_digits',
                'totp_period',
                'gemini_model',
                'gemini_fallback_model',
                'otp_purpose',
                'notify_purpose',
                'last_login_at',
                'last_naskah_at',
                'last_error_at',
                // 🔴 Rotasi kredensial DI-TARUH di sini, bukan dikecualikan.
                //
                // Yang masuk log adalah timestamp-nya, bukan kredensialnya --
                // sehingga "kredensial client X berubah pada jam berapa" tetap
                // bisa ditelusuri tanpa menyimpan password-nya di mana pun.
                // Lihat catatan panjang di docblock class.
                'credentials_rotated_at',
            ])
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }

    protected $hidden = [
        // 🔴 Lapis kedua. `$hidden` membuat nilai tidak ikut masuk
        // `$model->toArray()` -- jadi payload Livewire, response JSON, dan
        // log `laravel.log` yang meng-JSON seluruh request tidak memuat kredensial.
        //
        // Perhatikan: ini TIDAK menggantikan `logOnly()` di atas. `toArray()`
        // dan activity log adalah dua jalur yang berbeda.
        'username',
        'password',
        'totp_secret',
        'gemini_api_key',
        'phone',
        'notify_phone',
        'phone_index',
        'notify_phone_index',
    ];

    protected $casts = [
        'username' => 'encrypted',
        'password' => 'encrypted',
        'totp_secret' => 'encrypted',
        'gemini_api_key' => 'encrypted',
        'phone' => 'encrypted',
        'notify_phone' => 'encrypted',

        'totp_digits' => 'integer',
        'totp_period' => 'integer',
        'notify_enabled' => 'boolean',
        'is_active' => 'boolean',

        'credentials_rotated_at' => 'datetime',
        'last_login_at' => 'datetime',
        'last_naskah_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    /**
     * Device Wara milik client ini.
     *
     * 🔴 Ini relasi ke `wara_clients`, BUKAN ke `wara_devices`.
     *
     * Device fisiknya di sisi Wara, di balik client itu. Srikandi tidak tahu
     * device mana yang dipakai kalau tidak lewat Wara -- itu batas tanggung
     * jawab yang disengaja (AGENTS.md: bale/wara satu-satunya yang tahu
     * spesifik GOWA).
     */
    public function waraClient(): BelongsTo
    {
        return $this->belongsTo(WaraClient::class, 'wara_client_id');
    }

    /**
     * 🔴 Set kredensial client dalam satu jalan.
     *
     * Satu-satunya jalan masuk ke kolom password/totp/gemini. Dipanggil dari
     * form edit dan dari seeder, tidak pernah dari mass-assignment -- supaya
     * `credentials_rotated_at` selalu ikut diperbarui di baris yang sama.
     *
     * Argumen yang tidak berubah dilewati: memanggil form edit tanpa mengubah
     * password tidak boleh memutar timestamp rotasi, karena scraper akan mengira
     * kredensialnya baru dan mencoba login lagi tanpa perlu.
     *
     * @param  array<string, mixed>  $attributes  Kunci: username, password,
     *                                            totp_secret, gemini_api_key,
     *                                            phone, notify_phone.
     */
    public function setCredentials(array $attributes): self
    {
        $encrypted = [
            'username',
            'password',
            'totp_secret',
            'gemini_api_key',
            'phone',
            'notify_phone',
        ];

        foreach ($encrypted as $key) {
            if (! array_key_exists($key, $attributes)) {
                continue;
            }

            $value = $attributes[$key];

            // `null` dan string kosong:TIDAK dianggap "berubah" kalau sudah null.
            // Tanpa ini, form edit yang tidak disentuh akan mengosongkan password
            // karena input HTML tidak pernah mengirim password lama.
            if ($this->isUnchanged($key, $value)) {
                continue;
            }

            $this->setAttribute($key, $value);
            $this->credentialsChanged = true;
        }

        // `phone_index` harus ikut dihitung ulang setiap kali `phone` berubah.
        // Kalau tidak, index menunjuk nilai lama dan pencarian by-nomor
        // mengembalikan client yang nomornya sudah diganti.
        if ($this->credentialsChanged) {
            $this->syncPhoneIndexes();

            if ($this->isDirty('credentials_rotated_at')) {
                // Already set by caller.
                return $this;
            }

            $this->setAttribute('credentials_rotated_at', Carbon::now());
        }

        return $this;
    }

    protected bool $credentialsChanged = false;

    /**
     * Apakah nilai kredensial ini sebenarnya tidak berubah?
     *
     * 🔴 STRING KOSONG (`''`) = "JANGAN DISENTUH". `null` = "HAPUS".
     *
     * Dua-duanya falsy di PHP, jadi sengaja dipisah. Alasannya bentuk input
     * dari form edit:
     *
     * - Field bertipe password di browser TIDAK PERNAH mengirim nilai lamanya.
     *   Yang terkirim adalah `''` (placeholder, bukan value). Jadi `''` berarti
     *   "user tidak menyentuh field ini".
     * - Menganggap `''` sebagai "hapus" akan mengosongkan password client
     *   setiap kali form disimpan tanpa perubahan, lalu memberi tahu scraper
     *   kredensialnya baru. Scraper akan terlihat "sering login ulang" dan
     *   tidak ada yang mengaitkannya dengan password yang hilang.
     * - Menganggap `null` sebagai "jangan sentuh" membuat nomor tujuan tidak
     *   bisa dikosongkan. Jadi `null` harus berarti hapus, dan pemanggil yang
     *   membatalkan nomor mengirim `null` secara eksplisit.
     */
    protected function isUnchanged(string $key, mixed $value): bool
    {
        // Form yang tidak disentuh.
        if ($value === '') {
            return true;
        }

        // Pengosongan eksplisit -- tetap dilakukan walau kolomnya sudah null,
        // supaya pemanggil boleh mengosongkan tanpa memeriksa kondisi kolom lebih dulu.
        if ($value === null) {
            return false;
        }

        $current = $this->getAttribute($key);

        return $current !== null && hash_equals((string) $current, (string) $value);
    }

    /**
     * Hitung ulang `phone_index` dan `notify_phone_index`.
     *
     * 🔴 Dipanggil setiap `phone`/`notify_phone` berubah. Kalau kolom index
     * tidak ikut ditulis, pencarian by-nomor diam-diam jadi salah -- bukan error,
     * karena query-nya tetap jalan, hanya mengembalikan client yang salah.
     */
    protected function syncPhoneIndexes(): void
    {
        $index = new BlindIndex;

        if ($this->isDirty('phone')) {
            $this->setAttribute('phone_index', $index->make($this->getAttribute('phone')) ?: null);
        }

        if ($this->isDirty('notify_phone')) {
            $this->setAttribute('notify_phone_index', $index->make($this->getAttribute('notify_phone')) ?: null);
        }
    }

    /**
     * Apakah kredensial client berubah pada operasi ini saja?
     *
     * Dipakai test dan form edit untuk menentukan perlu atau tidaknya
     * mengaktifkan tombol "simpan".
     */
    public function hasCredentialChanges(): bool
    {
        return $this->credentialsChanged;
    }

    public function isActive(): bool
    {
        // 🔴 `$this->is_active` bisa NULL pada model yang BARU dibuat dan belum
        // menyentuh database -- default kolomnya `true`, tapi default itu
        // berlaku di sisi MySQL, bukan di sisi objek PHP. Objek yang baru di
        // `create()` belum pernah membaca barisnya, jadi atributnya kosong.
        //
        // Kalau ini `return (bool) $this->is_active`, objek baru akan terbaca
        // NONAKTIF: form edit baru akan menampilkan toggle mati padahal
        // database-nya `true`, dan menyimpan tanpa perubahan lain akan mematikan
        // client tanpa ada yang menyadarinya.
        return $this->is_active === null ? true : (bool) $this->is_active;
    }

    /**
     * 🔴 Apakah kredensial ini perlu dipakai scraper?
     *
     * Tidak cukup hanya `is_active`: password NULL berarti client belum pernah
     * diisi, dan scraper yang memanggilnya akan gagal dengan 401 lalu sia-sia
     * mencoba captcha. Ini dicek di satu tempat supaya pemanggil tidak
     *eterministic lupa.
     */
    public function isReadyForScraper(): bool
    {
        return $this->isActive()
            && filled($this->username)
            && filled($this->password)
            && filled($this->phone);
    }

    /**
     * Cari client by nomor tujuan, lewat blind index.
     *
     * 🔴 `wherePhone()` yang biasa TIDAK bisa dipakai di sini: `phone` terenkripsi,
     * jadi `where('phone', '628123')` tidak akan pernah cocok -- bukan error,
     * hanya selalu kosong. Itu penyebab paling umum "kenapa cari by-nomor tidak
     * ketemu padahal nomornya benar".
     */
    public function scopeWherePhone($query, string $phone)
    {
        return $query->where('phone_index', (new BlindIndex)->make($phone));
    }

    public function scopeWhereNotifyPhone($query, string $phone)
    {
        return $query->where('notify_phone_index', (new BlindIndex)->make($phone));
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
