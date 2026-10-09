<?php

namespace Bale\Srikandi\Livewire\Pages\Landlord\Client;

use Bale\Srikandi\Models\SrikandiClient;
use Bale\Srikandi\Support\OtpPhone;
use Bale\Wara\Models\WaraClient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Formulir client Srikandi: identitas, kredensial, dan konfigurasi OTP.
 *
 * 🔴 KOLOM SECRET TIDAK PERNAH KEMBALI KE BROWSER.
 *
 * `username`, `password`, `totp_secret`, `gemini_api_key`, dan `notify_phone`
 * tidak pernah diisi ulang ke property publik, tidak pernah jadi nilai default
 * input, dan tidak pernah masuk `toArray()` model — `SrikandiClient` sudah
 * menaruhnya di `$hidden` sebagai lapis kedua. Yang dirender hanya "terisi" atau
 * "belum", jadi begitu secret ada di HTML, ia terekspos ke siapa pun yang bisa
 * membuka halaman ini.
 *
 * 🔴 DUA CHECKBOX PER KOLOM SECRET, BUKAN SATU.
 *
 * `SrikandiClient::isUnchanged()` membedakan `''` ("jangan sentuh" — input
 * `type=password` di browser tidak pernah mengirim nilai lamanya, yang terkirim
 * adalah string kosong) dari `null` ("hapus"). Kalau hanya ada satu tombol
 * "ganti", maka `notify_phone` tidak akan PERNAH bisa dikosongkan: input kosong
 * terkirim sebagai string kosong yang dibaca "admin tidak menyentuh field ini".
 * Karena itu tiap kolom secret punya "ganti nilai tersimpan" dan "hapus nilai
 * tersimpan", dan keduanya punya test sendiri.
 *
 * 🔴 SLUG DIKUNCI SAAT EDIT, TANPA SYARAT.
 *
 * `slug` adalah nilai `sumber` yang sudah tertulis permanen di
 * `srikandi_naskah`. Versi awal hanya menguncinya kalau naskah sudah ada, supaya
 * client tanpa naskah masih bisa dibetulkan; itu menambah satu query, satu
 * cabang UI, dan tetap menyisakan inkonsistensi — slug yang berhasil diubah
 * setelah client punya naskah akan terus menunjuk `sumber` lama. Mengunci
 * selalu lebih murah daripada menutup dua kasus parsial.
 *
 * 🔴 NOMOR DINORMALISASI SEBELUM DISIMPAN.
 *
 * Lewat `OtpPhone::normalize()`, yang memakai `PhoneNumber::toInternational()`
 * milik wara. Alasannya bukan tampilan: kalau satu client tersimpan dengan awalan
 * lokal dan yang lain bentuk internasional, keduanya menghasilkan `phone_index`
 * yang sama tetapi ciphertext berbeda bentuknya — pencarian tetap jalan, tapi
 * kolom "nomor tujuan" akan menampilkan beberapa bentuk berbeda untuk nomor
 * yang sama. Aturan normalisasi di daftar dan di form juga tidak boleh berbeda;
 * nomor yang cocok di satu tempat tapi tidak cocok di yang lain punya gejala
 * "pencarian selalu kosong".
 */
#[Layout('core::layouts.app')]
#[Title('Form Client Srikandi')]
class Form extends Component
{
    use AuthorizesRequests;

    public ?SrikandiClient $client = null;

    public string $nama = '';

    public string $slug = '';

    public ?string $waraClientId = null;

    public ?string $otpPurpose = null;

    // --- Kredensial (input baru; nilai lama tidak pernah dimuat ke sini) ---
    public string $username = '';

    public string $password = '';

    public string $totpSecret = '';

    public int $totpDigits = 6;

    public int $totpPeriod = 30;

    public string $geminiApiKey = '';

    public ?string $geminiModel = null;

    public ?string $geminiFallbackModel = null;

    // --- OTP ---
    public string $phone = '';

    // --- Notifikasi ---
    public bool $notifyEnabled = false;

    public string $notifyVia = 'none';

    public string $notifyPhone = '';

    public ?string $notifyPurpose = null;

    // --- Status ---
    public bool $isActive = true;

    /**
     * Dua aksi per kolom secret: ganti / hapus.
     *
     * 🔴 Dua checkbox, bukan satu kontrol yang dibelah dua. Keduanya dibaca
     * terpisah di `credentialAction()`; kalau keduanya aktif, "hapus" menang
     * karena admin yang mencentang "hapus" jelas tidak bermaksud menyetor nilai
     * baru di kolom yang sama.
     *
     * @var array<string, array{replace: bool, clear: bool}>
     */
    public array $secretActions = [];

    /**
     * Client wara yang bisa dipilih, dengan penanda sudah terpakai.
     *
     * @var array<int, array{id: string, label: string, available: bool}>
     */
    public array $waraClients = [];

    /**
     * 🔴 Status "terisi" per kolom secret. Hanya boolean, bukan nilai.
     *
     * @var array<string, bool>
     */
    public array $secretSet = [];

    /**
     * Apakah kredensial berubah pada operasi ini saja.
     *
     * Dipakai test untuk memastikan "ganti tanpa input" tidak memutar
     * `credentials_rotated_at` — kalau iya, scraper akan mengira kredensialnya
     * baru setiap kali form disimpan tanpa perubahan.
     */
    #[Locked]
    public bool $credentialsChanged = false;

    public function mount(?SrikandiClient $client = null): void
    {
        $this->authorize('srikandi.client.update');

        $this->client = $client?->exists ? $client : null;

        $this->waraClients = $this->loadWaraClients();

        if ($this->client === null) {
            $this->resetForm();

            return;
        }

        $this->nama = (string) $this->client->nama;
        $this->slug = (string) $this->client->slug;
        $this->waraClientId = $this->client->wara_client_id;
        $this->otpPurpose = $this->client->otp_purpose;

        // 🔴 Kolom secret SENGAJA dikosongkan di sini, bukan diisi dengan nilai
        // lama. `mount()` tidak pernah memuat kredensial ke property publik.
        $this->username = '';
        $this->password = '';
        $this->totpSecret = '';
        $this->geminiApiKey = '';
        $this->phone = '';
        $this->notifyPhone = '';

        $this->totpDigits = (int) ($this->client->totp_digits ?? 6);
        $this->totpPeriod = (int) ($this->client->totp_period ?? 30);
        $this->geminiModel = $this->client->gemini_model;
        $this->geminiFallbackModel = $this->client->gemini_fallback_model;

        $this->notifyEnabled = (bool) $this->client->notify_enabled;
        $this->notifyVia = (string) ($this->client->notify_via ?? 'none');
        $this->notifyPurpose = $this->client->notify_purpose;

        $this->isActive = $this->client->isActive();

        // Yang tampil hanya "terisi / belum".
        $this->secretSet = [
            'username' => filled($this->client->username),
            'password' => filled($this->client->password),
            'totpSecret' => filled($this->client->totp_secret),
            'geminiApiKey' => filled($this->client->gemini_api_key),
            'phone' => filled($this->client->phone),
            'notifyPhone' => filled($this->client->notify_phone),
        ];

        $this->secretActions = array_fill_keys(array_keys($this->secretSet), [
            'replace' => false,
            'clear' => false,
        ]);
    }

    public function resetForm(): void
    {
        $this->nama = '';
        $this->slug = '';
        $this->waraClientId = null;
        $this->otpPurpose = null;

        $this->username = '';
        $this->password = '';
        $this->totpSecret = '';
        $this->geminiApiKey = '';
        $this->phone = '';

        $this->totpDigits = 6;
        $this->totpPeriod = 30;
        $this->geminiModel = null;
        $this->geminiFallbackModel = null;

        $this->notifyEnabled = false;
        $this->notifyVia = 'none';
        $this->notifyPhone = '';
        $this->notifyPurpose = null;

        $this->isActive = true;

        $this->secretSet = [];
        $this->secretActions = [];
    }

    /**
     * Client wara yang bisa dipilih, yang sudah dipakai ditandai dan nonaktif.
     *
     * 🔴 Client yang dipakai TETAP ditampilkan, hanya `@disabled`.
     *
     * `wara_client_id` UNIQUE karena satu wara client untuk dua akun Srikandi
     * berarti dua akun bisa saling membaca OTP. Kalau yang terpakai disembunyikan
     * begitu saja, admin yang sedang memperbaiki client lama akan melihat
     * dropdown kosong dan menyimpulkan device-nya hilang. Polanya sama dengan
     * `loadApiTokens()` di form client wara.
     *
     * `WaraClient` dicek dengan `class_exists` supaya form tetap bisa dirender di
     * instalasi tanpa `bale/wara` terpasang penuh.
     *
     * @return array<int, array{id: string, label: string, available: bool}>
     */
    protected function loadWaraClients(): array
    {
        if (! class_exists(WaraClient::class)) {
            return [];
        }

        // Peta pemilik device dalam satu query. Loop `find()` per baris adalah
        // N+1 yang tidak terlihat: daftar client wara tumbuh, dan setiap baris
        // menambah satu query ke database.
        $ownerByWaraClient = SrikandiClient::query()
            ->whereNotNull('wara_client_id')
            ->get(['id', 'nama', 'wara_client_id'])
            ->keyBy('wara_client_id');

        return WaraClient::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(function (WaraClient $wara) use ($ownerByWaraClient): array {
                $owner = $ownerByWaraClient->get($wara->getKey());
                $label = (string) $wara->name;

                if (! $wara->is_active) {
                    $label .= ' — '.__('nonaktif');
                }

                if ($owner !== null) {
                    $label .= ' — '.__('dipakai client: :name', ['name' => $owner->nama]);
                }

                return [
                    'id' => (string) $wara->getKey(),
                    'label' => $label,
                    // 🔴 Client yang sedang diedit dikecualikan. Tanpa itu, admin
                    // tidak akan pernah bisa menyimpan client yang sudah punya
                    // device — device-nya sendiri termasuk daftar "dipakai client
                    // lain", dan form menolak nilai yang tidak berubah sama sekali.
                    'available' => $owner === null || $owner->getKey() === $this->client?->getKey(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 🔴 `wara_client_id` milik client lain — pengaman kedua.
     *
     * Validasi ini bukan pengganti UNIQUE di database. UNIQUE hanya menolak
     * saat INSERT; validasi menolak lebih awal dengan pesan yang bisa dibaca.
     * Dua-duanya sengaja ada.
     *
     * @return list<string>
     */
    protected function waraClientsOwnedByOthers(): array
    {
        return SrikandiClient::query()
            ->whereNotNull('wara_client_id')
            ->when(
                $this->client?->exists,
                fn ($q) => $q->whereKeyNot($this->client->getKey())
            )
            ->pluck('wara_client_id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    /**
     * Aksi ganti/hapus untuk satu kolom secret.
     *
     * "Hapus" menang kalau keduanya aktif — mencentang "hapus" sambil mengetik
     * nilai baru di kolom yang sama itu kontradiksi, dan mengabaikannya berarti
     * nilai barunya tersimpan diam-diam padahal admin yakin mengosongkannya.
     *
     * @return 'replace'|'clear'|null
     */
    protected function credentialAction(string $key): ?string
    {
        if (! $this->client?->exists) {
            return 'replace';
        }

        $action = $this->secretActions[$key] ?? ['replace' => false, 'clear' => false];

        if ($action['clear']) {
            return 'clear';
        }

        if ($action['replace']) {
            return 'replace';
        }

        // Kalau belum pernah terisi sama sekali di database dan user mengetik sesuatu,
        // otomatis anggap sebagai 'replace' walaupun checkbox belum sempat dicentang.
        $storedValue = match ($key) {
            'username' => $this->client->username,
            'password' => $this->client->password,
            'totpSecret' => $this->client->totp_secret,
            'geminiApiKey' => $this->client->gemini_api_key,
            'phone' => $this->client->phone,
            'notifyPhone' => $this->client->notify_phone,
            default => null,
        };

        if (blank($storedValue) && filled($this->{$key})) {
            return 'replace';
        }

        return null;
    }

    /**
     * 🔴 Nomor tujuan dan nomor notifikasi dinormalisasi SEBELUM DISIMPAN.
     *
     * @return array{phone: string|null, notifyPhone: string|null}
     */
    protected function normalizedPhones(): array
    {
        $normalizer = app(OtpPhone::class);

        return [
            'phone' => $normalizer->normalizeOrNull($this->phone),
            'notifyPhone' => $normalizer->normalizeOrNull($this->notifyPhone),
        ];
    }

    public function save(): void
    {
        $validated = $this->validate([
            'nama' => ['required', 'string', 'max:191'],
            // 🔴 SLUG WAJIB pada create, dan WAJIB TETAP SAMA pada edit.
            //
            // Edit → slug tidak boleh beda. Aturan ini berlaku tanpa syarat:
            // slug adalah nilai `sumber` di `srikandi_naskah`, jadi mengubahnya
            // setelah ada naskah akan membuat naskah lama menunjuk sumber yang
            // sudah tidak ada.
            'slug' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('srikandi_clients', 'slug')->ignore($this->client?->getKey()),
            ],
            'waraClientId' => [
                'nullable',
                'uuid',
                Rule::notIn($this->waraClientsOwnedByOthers()),
            ],
            'otpPurpose' => ['nullable', 'string', 'max:32'],

            // Kredensial opsional di level form: nilai kosong berarti "jangan
            // sentuh" atau "hapus" — mana yang menentukan adalah checkbox.
            'username' => ['nullable', 'string', 'max:191'],
            'password' => ['nullable', 'string', 'max:1024'],
            'totpSecret' => ['nullable', 'string', 'max:255'],
            'totpDigits' => ['required', 'integer', 'min:6', 'max:8'],
            'totpPeriod' => ['required', 'integer', 'min:15', 'max:90'],
            'geminiApiKey' => ['nullable', 'string', 'max:1024'],
            'geminiModel' => ['nullable', 'string', 'max:191'],
            'geminiFallbackModel' => ['nullable', 'string', 'max:191'],

            'phone' => ['nullable', 'string', 'max:32'],
            'notifyEnabled' => ['boolean'],
            'notifyVia' => ['required', 'in:none,whatsapp'],
            'notifyPhone' => ['nullable', 'string', 'max:32'],
            'notifyPurpose' => ['nullable', 'string', 'max:32'],
            'isActive' => ['boolean'],

            'secretActions' => ['array'],
            'secretActions.*.replace' => ['boolean'],
            'secretActions.*.clear' => ['boolean'],
        ]);

        // 🔴 SLUG TIDAK BOLEH BERUBAH SAAT EDIT — dicek di server, bukan cuma UI.
        //
        // Kolomnya `readonly` di form, tapi `readonly` adalah petunjuk visual:
        // Livewire menerima state dari browser. Kalau pengecekan ini hilang,
        // `slug` bisa terkirim berbeda dan naskah lama menunjuk sumber yang sudah
        // tidak ada — dan tidak ada yang melihat.
        if ($this->client?->exists && $validated['slug'] !== $this->client->slug) {
            $this->addError('slug', __('Slug client sudah jadi sumber naskah dan tidak bisa diubah.'));

            return;
        }

        $client = $this->client ?? new SrikandiClient;

        $client->fill([
            'nama' => $validated['nama'],
            'slug' => $this->client?->exists ? $this->client->slug : $validated['slug'],
            'otp_purpose' => $validated['otpPurpose'] ?? null,
            'gemini_model' => $validated['geminiModel'] ?? null,
            'gemini_fallback_model' => $validated['geminiFallbackModel'] ?? null,
            'notify_enabled' => (bool) $validated['notifyEnabled'],
            'notify_via' => $validated['notifyVia'],
            'notify_purpose' => $validated['notifyPurpose'] ?? null,
            'is_active' => (bool) $validated['isActive'],
            'totp_digits' => (int) $validated['totpDigits'],
            'totp_period' => (int) $validated['totpPeriod'],
        ]);

        // 🔴 `wara_client_id` di luar $fillable, jadi lewat forceFill.
        //
        // Kolom ini menentukan client ini memakai device siapa — menentukan OTP
        // masuk ke akun mana. Tidak boleh datang dari mass-assignment bebas.
        $client->forceFill([
            'wara_client_id' => $validated['waraClientId'] ?? null,
        ]);

        $credentials = $this->collectCredentials();

        // 🔴 Nomor SELALU lewat `setCredentials()`.
        //
        // `phone_index` dan `notify_phone_index` hanya dihitung ulang di
        // `syncPhoneIndexes()`, yang dipanggil dari `setCredentials()`. Kalau
        // `phone` diisi lewat `fill()` atau `forceFill()`, index-nya diam-diam
        // menunjuk nilai lama — pencarian by-nomor mengembalikan client yang
        // nomornya sudah diganti, tanpa error.
        $this->applyPhonesToCredentials($credentials, $this->normalizedPhones());

        $client->setCredentials($credentials);

        try {
            $client->save();
        } catch (UniqueConstraintViolationException) {
            // 🔴 Ditangkap jadi error form, bukan exception yang lepas ke user.
            //
            // UNIQUE pada `wara_client_id` menolak save dengan pesan SQL yang
            // tidak akan pernah dibaca admin. Yang penting client tersimpan
            // dengan device yang tidak diklaim client lain.
            $this->addError('waraClientId', __('Client wara itu sudah dipakai client Srikandi lain.'));

            return;
        }

        $this->credentialsChanged = $client->hasCredentialChanges();

        // Mount ulang supaya "terisi / belum" mencerminkan yang baru disimpan, dan
        // supaya nilai yang baru diketik tidak tertinggal di property publik —
        // input kredensial harus kembali kosong setelah save.
        $this->mount($client);

        $this->dispatch('toast', message: __('Client Srikandi berhasil disimpan.'), type: 'success');
        $this->redirect(route('srikandi.client.index'), navigate: true);
    }

    /**
     * Susun array kredensial dari input form dan checkbox ganti/hapus.
     *
     * 🔴 Aturan per kolom secret:
     *   - centang "hapus"    → null (benar-benar mengosongkan kolom)
     *   - centang "ganti"    → nilai input baru (string kosong berarti no-op
     *                          lewat `isUnchanged()`)
     *   - tidak ada centang  → string kosong = "jangan sentuh"
     *
     * Untuk create, kolom secret yang tidak diisi tetap string kosong → no-op,
     * dan kolom tetap NULL karena model baru tidak punya nilai lama.
     *
     * @return array<string, mixed>
     */
    protected function collectCredentials(): array
    {
        $map = [
            'username' => 'username',
            'password' => 'password',
            'totpSecret' => 'totp_secret',
            'geminiApiKey' => 'gemini_api_key',
        ];

        $credentials = [];

        foreach ($map as $property => $column) {
            $action = $this->credentialAction($property);

            if ($action === 'clear') {
                $credentials[$column] = null;

                continue;
            }

            if ($action === 'replace') {
                $credentials[$column] = $this->{$property};

                continue;
            }

            // Tidak ada centang ganti → string kosong = "jangan sentuh".
            $credentials[$column] = '';
        }

        return $credentials;
    }

    /**
     * Nomor tujuan dan nomor notifikasi, menghormati aksi ganti/hapus.
     *
     * Sama seperti kolom secret biasa: "hapus" → null, "ganti" → nilai
     * ternormalisasi yang baru, tanpa centang → string kosong (no-op).
     *
     * @param  array<string, mixed>  $credentials
     * @param  array{phone: string|null, notifyPhone: string|null}  $phones
     */
    protected function applyPhonesToCredentials(array &$credentials, array $phones): void
    {
        foreach (['phone' => 'phone', 'notifyPhone' => 'notify_phone'] as $property => $column) {
            $action = $this->credentialAction($property);

            if ($action === 'clear') {
                $credentials[$column] = null;

                continue;
            }

            if ($action === 'replace') {
                $credentials[$column] = $phones[$property];

                continue;
            }

            $credentials[$column] = '';
        }
    }

    public function render()
    {
        return view('srikandi::livewire.pages.landlord.client.form', [
            'client' => $this->client,
            'secretSet' => $this->secretSet,
            'secretActions' => $this->secretActions,
            'waraClients' => $this->waraClients,
        ]);
    }
}
