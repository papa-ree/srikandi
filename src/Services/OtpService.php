<?php

namespace Bale\Srikandi\Services;

use Bale\Srikandi\Exceptions\SrikandiException;
use Bale\Srikandi\Models\SrikandiOtpState;
use Bale\Srikandi\Support\OtpCode;
use Bale\Srikandi\Support\OtpPhone;
use Bale\Wara\Exceptions\WaraException;
use Bale\Wara\Models\WaraRoute;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\Support\DeviceRouter;
use Bale\Wara\WaraManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * State OTP: jendela listening, pengiriman, verifikasi (spec §5.0b, §5.1, §5.3).
 *
 * 🔴 DUA JALUR YANG SECARA SENGAJA BERBEDA.
 *
 * 1. `openWindow()` — jendela listening. TIDAK mengarang kode OTP dan TIDAK
 *    mengirim WhatsApp. Kode OTP yang benar datang dari Srikandi; Bale hanya
 *    menentukan nomor tujuan (dari device `purpose=otp`) dan membuka baca atas
 *    pesan masuk. Jalur inilah yang dipakai scraper.
 *
 * 2. `requestOtp()` — jalur lama, dipakai Bale sendiri (admin/internal). Di sini
 *    Bale memang mengarang kode lalu mengirimkannya. Dipakai kalau pemanggil
 *    menyebut `phone` secara eksplisit.
 *
 * Kenapa pemisahan ini penting: kalau Bale mengirim OTP buatan sendiri ke nomor
 * operator, operator menerima DUA kode — dari Bale dan dari Srikandi. Scraper
 * yang mengambil pesan inbound terakhir bisa mengambil yang salah dan gagal
 * login, dan `code_hash` Bale tidak akan pernah cocok dengan kode Srikandi.
 *
 * Aturan lain yang menentukan bentuk kelas ini:
 *
 * * Kode OTP tidak pernah disimpan polos dan tidak pernah dikembalikan ke
 *   scraper. Yang tersimpan hanya hash-nya.
 * * Idempoten di kedua arah. Panggilan ulang memakai record yang sudah ada dan
 *   tidak mengirim pesan kedua.
 */
class OtpService
{
    public function __construct(
        protected WaraManager $wara,
        protected OtpPhone $phones,
    ) {}

    /**
     * Buka jendela listening untuk nomor device `purpose=otp` (spec §5.0b).
     *
     * TIDAK mengarang kode, TIDAK mengirim WhatsApp. Yang dibuat hanyalah
     * batas waktu dan batas bawah pembacaan: `opened_at` menandai dari kapan
     * balasan relevan, `expires_at` menandai kapan jendela ditutup.
     *
     * Nomor tujuan diambil dari device, bukan dari pemanggil. Kalau pemanggil
     * menyebut `phone` secara eksplisit, jalur ini tetap dipakai — dan nomor itu
     * yang dipakai, supaya pemakaian internal/admin tidak bisa diam-diam dialihkan
     * ke device.
     *
     * @throws SrikandiException
     */
    public function openWindow(
        ?string $rawPhone = null,
        ?string $purpose = null,
        ?string $sessionKey = null,
    ): SrikandiOtpState {
        $purpose = $this->resolvePurpose($purpose);

        [$phone, $deviceId] = $this->resolveTargetPhone($rawPhone, $purpose);

        $existing = $this->findVerifiableByPhone($phone);

        if ($existing !== null) {
            return $existing;
        }

        $state = new SrikandiOtpState([
            'request_id' => (string) Str::uuid(),
            'purpose' => $purpose,
            'phone' => $phone,
            'state' => SrikandiOtpState::STATE_PENDING,
            'session_key' => $sessionKey ?? $deviceId,
            'attempts' => 0,
            'opened_at' => now(),
            'expires_at' => now()->addMinutes((int) config('srikandi.otp.ttl_minutes', 5)),
            'metadata' => [
                'window_opened_at' => now()->toIso8601String(),
            ],
        ]);

        // 🔴 `code_hash` sengaja TIDAK diisi. Kode belonged to Srikandi.
        $state->save();

        Log::info('Srikandi: jendela listening OTP dibuka.', [
            'state_id' => $state->id,
            'purpose' => $purpose,
            'device_id' => $deviceId,
        ]);

        return $state;
    }

    /**
     * Tentukan nomor tujuan, dan device yang menentukannya.
     *
     * Urutannya penting dan TIDAK boleh dibalik:
     *
     * 1. `phone` eksplisit -> dipakai apa adanya, tanpa menyentuh device.
     * 2. selain itu -> device `purpose=otp` looked up DI SINI, lalu nomor diambil
     *    dari `jid`-nya.
     *
     * 🔴 Kenapa device dicari sendiri dan bukan lewat `resolveDevice($purpose)`
     * langsung: `resolveDevice()` jatuh ke device default dari
     * `WARA_DEFAULT_DEVICE_ID` kalau tidak ada device bertanda purpose (§6.5.1
     * "jaring pengaman"). Untuk konteks OTP itu justru berbahaya — kode dibaca
     * dari nomor yang tidak diminta, dan tidak ada yang diberi tahu device itu
     * salah. Jadi Absence device di sini adalah KESALAHAN, bukan fallback.
     *
     * Setelah device ditemukan, `resolveDevice()` tetap dipanggil dengan
     * `deviceId` eksplisit supaya gerbang kesiapan §6.5.2 (dan pesan galatnya)
     * milik `bale/wara`, bukan ditulis ulang di sini.
     *
     * @return array{0: string, 1: string|null} [nomor ternormalisasi, device_id]
     *
     * @throws SrikandiException
     */
    protected function resolveTargetPhone(?string $rawPhone, string $purpose): array
    {
        if ($rawPhone !== null && trim($rawPhone) !== '') {
            /*
             * Nomor eksplisit tetap dipakai apa adanya, tapi device tetap
             * ikut di-resolve.
             *
             * Device ini hanya dipakai untuk log di jalur ini - `openWindow()`
             * tidak mengirim apa pun. Yang mengirim adalah `requestOtp()`, dan
             * dia me-resolve sendiri lewat `sessionForPurpose()`.
             *
             * 🔴 `SrikandiOtpState` tidak punya kolom `device_id`, jadi device
             * TIDAK bisa diambil dari state. Selama ini device hasil resolusi
             * dibuang, dan `deliver()` berakhir memakai
             * `WARA_DEFAULT_DEVICE_ID`.
             */
            return [$this->requirePhone($rawPhone), $this->sessionForPurpose($purpose)?->device_id];
        }

        $session = $this->sessionForPurpose($purpose);

        if ($session === null) {
            throw SrikandiException::noOtpDevice(
                sprintf(
                    'Client Wara "Srikandi (Package)" belum punya route untuk '
                    .'purpose "%s". Petakan di menu Wara > Client, lalu jalankan '
                    .'php artisan wara:sync-devices. Jalur Device tidak bisa '
                    .'dipakai - penugasan device sudah dihapus.',
                    $purpose
                )
            );
        }

        // Gerbang kesiapan §6.5.2 milik bale/wara: melemparkan DeviceNotReadyException
        // yang menyebut device mana yang tidak siap.
        try {
            $resolved = $this->wara->resolveDevice($purpose, $session->device_id);
        } catch (WaraException $e) {
            /*
             * 🔴 Exception dari `bale/wara` diterjemahkan, bukan diteruskan.
             *
             * Kalau dibiarkan, `WaraException` akan jatuh ke catch `\Throwable`
             * milik controller dan jadi `500` dengan pesan generik. Padahal ini
             * kondisi yang harus diperbaiki admin (device belum pairing) — dan
             * pesan aslinya justru yang paling berguna karena menyebut device,
             * JID, dan syarat yang gagal.
             *
             * Jadi pesannya dibawa utuh, statusnya yang diganti ke 422.
             */
            throw SrikandiException::noOtpDevice($e->getMessage());
        }

        $phone = $this->phones->normalizeOrNull($this->stripJid((string) $resolved->jid));

        if ($phone === null) {
            throw SrikandiException::noOtpDevice(
                sprintf(
                    'Device "%s" (purpose "%s") belum punya JID, jadi nomornya tidak diketahui. '
                    .'Pasang dulu di gateway, lalu jalankan: php artisan wara:sync-devices',
                    $resolved->display_name ?? '(tanpa nama)',
                    $purpose
                )
            );
        }

        return [$phone, $resolved->device_id];
    }

    /**
     * Device yang melayani sebuah purpose, dicari lewat `wara_routes` milik
     * client Srikandi.
     *
     * 🔴 `wara_sessions.purpose` SUDAH DIHAPUS. Kolom itu unik global, jadi hanya
     * satu device di seluruh instalasi yang boleh punya satu purpose - dan begitu
     * ada client kedua, keduanya berebut device yang sama tanpa ada cara tahu
     * siapa berhak atas apa. Penugasan sekarang pindah ke `wara_routes` dengan
     * `UNIQUE (client_id, purpose)`.
     *
     * 🔴 `client_id` WAJIB ikut difilter. Tanpa itu, query ini mengembalikan route
     * PERTAMA yang cocok - termasuk milik client lain. Itu persis bug yang sudah
     * dibongkar di `SendClientResolver`: pemanggil dari satu client diam-diam
     * memakai device milik client lain. Selama hanya ada satu client, bug-nya
     * tidak terlihat; begitu `srikandi:install` membuat client kedua, dia muncul.
     *
     * Client diambil dari `DeviceRouter::defaultServiceClient()`. Method itu
     * sebelumnya TIDAK pernah dipanggil siapa pun di seluruh package - dan justru
     * sekarang barulah punya gunanya: ia adalah cara resmi mendapatkan "client
     * internal yang tidak punya token sendiri".
     *
     * Kalau client tidak ditemukan, hasilnya `null` - bukan route milik siapa pun.
     * Itu pilihan yang benar: lebih baik OTP gagal dengan pesan yang menyebut
     * penyebabnya daripada terkirim dari device yang bukan haknya.
     */
    protected function sessionForPurpose(string $purpose): ?WaraSession
    {
        $client = app(DeviceRouter::class)->defaultServiceClient();

        if ($client === null) {
            return null;
        }

        $route = WaraRoute::query()
            ->where('client_id', $client->getKey())
            ->where('purpose', $purpose)
            ->where('outbound_enabled', true)
            ->orderBy('created_at')
            ->first();

        if ($route === null) {
            return null;
        }

        return WaraSession::query()
            ->where('device_id', $route->device_id)
            ->first();
    }

    /**
     * Buang sufiks JID WhatsApp.
     */
    protected function stripJid(string $jid): string
    {
        $at = strpos($jid, '@');

        return $at === false ? $jid : substr($jid, 0, $at);
    }

    /**
     * Minta OTP untuk satu nomor (spec §5.1).
     *
     * 🔴 Jalur LAMA — Bale mengarang kodenya sendiri lalu mengirimkannya lewat
     * WhatsApp. Untuk login Srikandi ini salah, karena kode yang benar datang dari
     * Srikandi (lihat uraikan kelas). Dipakai hanya kalau pemanggil menyebut
     * `phone` eksplisit; scraper memakai `openWindow()`.
     *
     * Kalau sudah ada record `pending` yang belum kedaluwarsa, record itu
     * dikembalikan apa adanya dan TIDAK ada pesan kedua yang dikirim — kirim
     * ulang tanpa alasan adalah pemicu restriksi akun yang paling sering terjadi
     * di Srikandi.
     *
     * @throws SrikandiException
     */
    public function requestOtp(string $rawPhone, ?string $purpose = null, ?string $sessionKey = null): SrikandiOtpState
    {
        $phone = $this->requirePhone($rawPhone);
        $purpose = $this->resolvePurpose($purpose);

        $existing = $this->findVerifiableByPhone($phone);

        if ($existing !== null) {
            return $existing;
        }

        $code = OtpCode::generate((int) config('srikandi.otp.code_length', 6));

        $state = new SrikandiOtpState([
            'request_id' => (string) Str::uuid(),
            'purpose' => $purpose,
            'phone' => $phone,
            'state' => SrikandiOtpState::STATE_PENDING,
            'session_key' => $sessionKey,
            'attempts' => 0,
            'opened_at' => now(),
            'expires_at' => now()->addMinutes((int) config('srikandi.otp.ttl_minutes', 5)),
            'metadata' => [
                'requested_at' => now()->toIso8601String(),
            ],
        ]);

        $state->setCodeHash(OtpCode::hash($code));

        $state->save();

        // 🔴 Jalur lama ini tidak pernah resolve device, padahal
        // `openWindow()` melakukannya. Akibatnya `deliver()` memanggil
        // `sendOtp()` tanpa `deviceId` dan `resolveDevice()` jatuh ke
        // `WARA_DEFAULT_DEVICE_ID` - yang kosong di test dan sering salah di
        // produksi. Hasilnya 422 `otp_delivery_failed` padahal device
        // `purpose=otp` ada dan siap kirim.
        //
        // Device diambil dari route purpose yang sama seperti `openWindow()`,
        // lalu diteruskan sebagai argumen - bukan disimpan di state, karena
        // `SrikandiOtpState` tidak punya kolom `device_id`.
        $this->deliver($state, $code, $this->sessionForPurpose($purpose)?->device_id);

        return $state;
    }

    /**
     * Kirim kode lewat WhatsApp (spec §5.1).
     *
     * Kegagalan kirim akan meninggalkan record `pending` yang mengunci nomor itu
     * sampai TTL habis tanpa kode yang pernah sampai — dan karena `requestOtp`
     * bersifat idempoten, panggilan berikutnya akan mengembalikan record itu
     * tanpa mengirim. Jadi record langsung dikexpiredkan: retry tetap mungkin,
     * dan jejaknya tetap ada untuk dibaca.
     *
     * @throws SrikandiException
     */
    protected function deliver(SrikandiOtpState $state, string $code, ?string $deviceId = null): void
    {
        try {
            $this->wara->sendOtp(
                $state->phone,
                $code,
                $state->purpose,
                // 🔴 Device hasil resolusi HARUS diteruskan di sini.
                //
                // `SrikandiOtpState` tidak punya kolom `device_id` - nilainya
                // selalu `null` - jadi device tidak boleh diambil dari state.
                // Kalau diteruskan `null`, `resolveDevice()` memakai
                // `WARA_DEFAULT_DEVICE_ID`, yang di test selalu kosong, dan
                // hasilnya 422 padahal device `purpose=otp` ada dan siap.
                $deviceId,
                sessionKey: $state->session_key,
            );
        } catch (\Throwable $e) {
            $state->forceFill([
                'state' => SrikandiOtpState::STATE_EXPIRED,
                'metadata' => array_merge($state->metadata ?? [], [
                    'delivery_failed_at' => now()->toIso8601String(),
                    'delivery_error' => $e->getMessage(),
                ]),
            ])->save();

            Log::error('Srikandi: gagal mengirim OTP lewat WhatsApp.', [
                'state_id' => $state->id,
                'error' => $e->getMessage(),
            ]);

            throw SrikandiException::otpDeliveryFailed(
                'OTP gagal dikirim. Periksa koneksi device WhatsApp.',
                ['reason' => class_basename($e)],
            );
        }
    }

    /**
     * Verifikasi kode (spec §5.3).
     *
     * Idempoten: kode yang sama diverifikasi dua kali tetap `200`. Scraper boleh
     * mencoba ulang setelah timeout tanpa perlu gagal — dan memang seharusnya,
     * karena yang timeout adalah scraper, bukan Bale yang keliru.
     *
     * 🔴 Jendela listening TIDAK bisa diverifikasi lewat sini. Kode OTP-nya milik
     * Srikandi dan Bale tidak pernah mengetahuinya, jadi `code_hash`-nya NULL.
     * Percobaan memverifikasi jendela ditolak dengan pesan yang jelas, bukan
     * diperlakukan sebagai "kode salah" — perlakuan itu menghabiskan jatah
     * percobaan dan menyamarkan OTP Srikandi yang sebenarnya sudah benar.
     *
     * Identitas dicari lewat `request_id` lebih dulu; kalau tidak ketemu, fallback
     * ke `phone` supaya konsumen lama yang belum memakai kontrak v2 tetap jalan.
     *
     * @throws SrikandiException
     */
    public function verifyOtp(
        string $rawCode,
        ?string $rawPhone = null,
        ?string $requestId = null,
    ): SrikandiOtpState {
        $expectedLength = (int) config('srikandi.otp.code_length', 6);

        if (OtpCode::digitCount($rawCode) !== $expectedLength) {
            throw SrikandiException::invalidRequest(
                'Kode OTP tidak valid.',
                ['expected_digits' => $expectedLength],
            );
        }

        /*
         * 🔴 Transaction TIDAK boleh melempar.
         *
         * Melempar exception dari dalam closure akan me-rollback transaksinya —
         * termasuk kenaikan `attempts` dan transisi ke `expired`. Akibatnya
         * batas percobaan jadi tidak pernah tercapai: setiap kode salah
         * mengembalikan 422 dengan `attempts` yang tetap 0, dan kode bisa
         * ditebak tanpa henti.
         *
         * Jadi closure mengembalikan hasilnya, dan exception dilempar DI LUAR
         * transaksi — setelah perubahannya sudah ter-commit.
         */
        $outcome = DB::transaction(function () use ($rawCode, $rawPhone, $requestId) {
            $state = $this->lockLatestFor($rawPhone, $requestId);

            if ($state === null) {
                return ['error' => SrikandiException::notFound(
                    'Tidak ada permintaan OTP untuk identitas ini.',
                )];
            }

            if ($state->isWindow()) {
                return ['error' => SrikandiException::windowNotVerifiable([
                    'state' => $state->state,
                ])];
            }

            $matches = OtpCode::verify($rawCode, (string) $state->getAttribute('code_hash'));

            /*
             * Record yang sudah `verified`/`consumed` diperiksa lebih dulu,
             * SEBELUM cek kedaluwarsa.
             *
             * Idempoten harus menang atas kondisi waktu: scraper yang timeout
             * lalu mencoba ulang tidak boleh melihat `410` untuk kode yang
             * sudah berhasil. Itu membuat sukses terlihat seperti kegagalan,
             * dan scraper lalu membuang percobaan login Srikandi yang
             * sebenarnya sudah berhasil.
             */
            if ($state->state === SrikandiOtpState::STATE_VERIFIED
                || $state->state === SrikandiOtpState::STATE_CONSUMED) {
                return $matches
                    ? ['state' => $state]
                    : ['error' => SrikandiException::invalidRequest('Kode OTP tidak valid.', [
                        'state' => $state->state,
                    ])];
            }

            if ($state->isExpired()) {
                $this->expire($state);

                return ['error' => SrikandiException::otpExpired([
                    'state' => $state->state,
                ])];
            }

            if ($matches) {
                return ['state' => $this->markVerified($state)];
            }

            return ['error' => $this->failAttempt($state)];
        });

        if (isset($outcome['error'])) {
            throw $outcome['error'];
        }

        return $outcome['state'];
    }

    /**
     * Tandai terverifikasi. `verified_at` diisi SEKALI saja (spec §5.3).
     */
    protected function markVerified(SrikandiOtpState $state): SrikandiOtpState
    {
        if ($state->state !== SrikandiOtpState::STATE_VERIFIED) {
            $state->forceFill([
                'state' => SrikandiOtpState::STATE_VERIFIED,
                'verified_at' => $state->verified_at ?? now(),
            ])->save();
        }

        return $state->refresh();
    }

    /**
     * Kode salah: naikkan `attempts`, dan `expired` kalau sudah habis.
     *
     * 🔴 `attempts` dinaikkan DI DALAM transaksi yang mengunci baris. Tanpa
     * lock, dua permintaan salah yang datang bersamaan akan sama-sama membaca
     * `attempts = 0` lalu sama-sama menulis `1` — batas percobaan jadi tidak
     * berarti, dan scraper bisa menebak kode tanpa henti.
     *
     * 🔴 Method ini MENGEMBALIKAN exception, bukan melemparnya. Melempar dari
     * dalam transaksi akan me-rollback kenaikan `attempts` yang baru saja
     * ditulis, dan batas percobaan tidak akan pernah tercapai.
     */
    protected function failAttempt(SrikandiOtpState $state): SrikandiException
    {
        $attempts = $state->attempts + 1;

        $max = (int) config('srikandi.otp.max_attempts', 5);

        $attributes = ['attempts' => $attempts];

        if ($attempts >= $max) {
            $attributes['state'] = SrikandiOtpState::STATE_EXPIRED;
        }

        $state->forceFill($attributes)->save();

        /*
         * 🔴 Yang dicatat TIDAK memuat kode OTP (spec §7) — hanya `attempts`
         * dan `state`. `code_hash` sengaja tidak ikut: memuatnya di log berarti
         * isi kode tinggal diambil dari sana.
         */
        Log::warning('Srikandi: percobaan verifikasi OTP gagal.', [
            'state_id' => $state->id,
            'phone' => $state->phone,
            'attempts' => $attempts,
            'state' => $state->state,
            'session_key' => $state->session_key,
        ]);

        if ($attempts >= $max) {
            return SrikandiException::tooManyAttempts(
                'Batas percobaan habis. Kode OTP tidak berlaku lagi.',
                ['attempts' => $attempts],
            );
        }

        return SrikandiException::invalidRequest(
            'Kode OTP tidak valid.',
            ['attempts' => $attempts, 'remaining' => $max - $attempts],
        );
    }

    protected function expire(SrikandiOtpState $state): void
    {
        if ($state->state !== SrikandiOtpState::STATE_EXPIRED) {
            $state->forceFill(['state' => SrikandiOtpState::STATE_EXPIRED])->save();
        }
    }

    /**
     * Record `pending` yang masih hidup untuk nomor ini.
     *
     * Dipakai untuk menemukan jendela aktif yang cocok, sehingga permintaan
     * kedua memakai jendela yang sama alih-alih membuka yang baru.
     */
    public function findVerifiableByPhone(string $phone): ?SrikandiOtpState
    {
        return SrikandiOtpState::query()
            ->where('phone', $phone)
            ->where('state', SrikandiOtpState::STATE_PENDING)
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Record hidup berdasarkan `request_id` (jalur kontrak v2).
     */
    public function findByRequestId(string $requestId): ?SrikandiOtpState
    {
        return SrikandiOtpState::query()
            ->where('request_id', $requestId)
            ->first();
    }

    /**
     * Record hidup yang cocok dengan identitas mana pun yang diberikan.
     *
     * `request_id` didahulukan: kalau keduanya ada, identitas buram yang
     * menang — scraper modern memegangnya, sementara nomor hanya jalur
     * kompatibilitas.
     *
     * Record yang sudah lewat `expires_at` sengaja ikut dipertimbangkan di sini
     * supaya `otp-pending` bisa membedakan "tidak ada" dari "sudah tutup",
     * dan `otp-verify` bisa mengembalikan `410` lengkap dengan perubahan state-nya.
     */
    public function findVerifiable(?string $rawPhone = null, ?string $requestId = null): ?SrikandiOtpState
    {
        if ($requestId !== null && trim($requestId) !== '') {
            $byRequestId = $this->findByRequestId(trim($requestId));

            if ($byRequestId !== null) {
                return $byRequestId;
            }
        }

        if ($rawPhone !== null && trim($rawPhone) !== '') {
            return SrikandiOtpState::query()
                ->where('phone', $this->requirePhone($rawPhone))
                ->orderByDesc('created_at')
                ->first();
        }

        return null;
    }

    /**
     * Row paling relevan untuk diverifikasi, terkunci untuk pembaruan.
     */
    protected function lockLatestFor(?string $rawPhone, ?string $requestId): ?SrikandiOtpState
    {
        $query = SrikandiOtpState::query();

        if ($requestId !== null && trim($requestId) !== '') {
            $state = $query->where('request_id', trim($requestId))
                ->lockForUpdate()
                ->first();

            if ($state !== null) {
                return $state;
            }
        }

        if ($rawPhone !== null && trim($rawPhone) !== '') {
            return SrikandiOtpState::query()
                ->where('phone', $this->requirePhone($rawPhone))
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();
        }

        return null;
    }

    protected function requirePhone(string $rawPhone): string
    {
        $phone = $this->phones->normalizeOrNull($rawPhone);

        if ($phone === null) {
            throw SrikandiException::invalidRequest('Nomor telepon tidak valid.');
        }

        return $phone;
    }

    protected function resolvePurpose(?string $purpose): string
    {
        $purpose = $purpose !== null ? trim($purpose) : '';

        if ($purpose === '') {
            $purpose = (string) config('srikandi.otp.default_purpose', 'otp');
        }

        return mb_substr($purpose, 0, 32);
    }
}
