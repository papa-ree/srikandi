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
use Illuminate\Database\Eloquent\Builder;
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
     * 🔴 `$sumber` WAJIB, dan sengaja jadi parameter PERTAMA.
     *
     * Kolom `srikandi_otp_states.sumber` sudah `NOT NULL` tanpa default, jadi
     * nullable di sini hanya berarti ada jalur PHP yang bisa membangun state
     * yatim. Default `'default'` sengaja dihapus supaya tidak ada lagi record OTP
     * yang tidak diketahui pemiliknya; itu juga alasan parameter ini tidak
     * boleh punya nilai bawaan.
     *
     * @throws SrikandiException
     */
    public function openWindow(
        string $sumber,
        ?string $rawPhone = null,
        ?string $purpose = null,
        ?string $sessionKey = null,
    ): SrikandiOtpState {
        $purpose = $this->resolvePurpose($purpose);

        [$phone, $deviceId] = $this->resolveTargetPhone($rawPhone, $purpose);

        $existing = $this->findVerifiableByPhone($phone, $sumber);

        if ($existing !== null) {
            return $existing;
        }

        $state = new SrikandiOtpState([
            'sumber' => $sumber,
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
     * 🔴 `$sumber` WAJIB dan jadi parameter PERTAMA, alasan yang sama seperti di
     * `openWindow()`.
     *
     * @throws SrikandiException
     */
    public function requestOtp(
        string $sumber,
        string $rawPhone,
        ?string $purpose = null,
        ?string $sessionKey = null,
    ): SrikandiOtpState {
        $phone = $this->requirePhone($rawPhone);
        $purpose = $this->resolvePurpose($purpose);

        $existing = $this->findVerifiableByPhone($phone, $sumber);

        if ($existing !== null) {
            return $existing;
        }

        $code = OtpCode::generate((int) config('srikandi.otp.code_length', 6));

        $state = new SrikandiOtpState([
            'sumber' => $sumber,
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
     * 🔴 `$sumber` WAJIB dan jadi parameter PERTAMA, alasan yang sama seperti di
     * `openWindow()`. Kode OTP milik client lain tidak boleh diverifikasi hanya
     * karena pemanggil tahu nomornya.
     *
     * @throws SrikandiException
     */
    public function verifyOtp(
        string $sumber,
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
        $outcome = DB::transaction(function () use ($rawCode, $rawPhone, $requestId, $sumber) {
            $state = $this->lockLatestFor($sumber, $rawPhone, $requestId);

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
    public function findVerifiableByPhone(string $phone, string $sumber): ?SrikandiOtpState
    {
        $query = SrikandiOtpState::query()
            ->wherePhone($phone)
            ->where('state', SrikandiOtpState::STATE_PENDING)
            ->where('expires_at', '>', now());

        /*
         * 🔴 FILTER `sumber` BUKAN OPSIONAL. Ini yang mencegah dua akun SRIKANDI
         * yang kebetulan memakai nomor sama saling menimpa.
         *
         * Tanpa filter ini, permintaan OTP kedua yang datang dengan nomor yang
         * sama tapi client berbeda akan menemukan record milik client pertama,
         * lalu `requestOtp()` mengembalikan record itu apa adanya karena
         * "sudah ada yang pending". Efeknya: client kedua diam-diam tidak
         * pernah menerima kode, dan tidak ada satu pun error yang muncul --
         * karena memang tidak ada yang gagal, hanya tidak ada yang terjadi.
         *
         * Gejalanya di lapangan adalah "OTP-nya tidak pernah sampai" untuk satu
         * akun saja, sementara akun lain normal. Itu symptom yang mahal untuk
         * didiagnosis karena tidak ada jejak kegagalan sama sekali.
         */
        $this->restrictToSumber($query, $sumber);

        return $query
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Record hidup berdasarkan `request_id` (jalur kontrak v2).
     *
     * 🔴 `request_id` TIDAK sendirinya sudah cukup, dan itu tidak bisa diasumsikan.
     *
     * Argumen "UUID-nya unik, jadi tidak perlu dicek" itu benar secara
     * probabilitas tapi salah untuk lapisan ini. `request_id` datang lewat
     * jaringan; selama token belum terikat ke client (itu pekerjaan S3
     * berikutnya), siapa pun yang memegang token Srikandi bisa mengirim
     * `request_id` milik client lain bersama `sumber` miliknya sendiri.
     *
     * Kalau slug tidak ikut dicek, tidak ada yang memberi tahu: pemanggil melihat
     * `200` atau `verified` untuk jendela yang bukan miliknya, dan isi `replies`
     * dari device orang lain ikut terbawa. Unik tidak berarti tidak bisa
     * diambil orang -- yang menentukan adalah apakah PEMILIK-nya cocok, bukan
     * apakah ID-nya unik.
     *
     * Jadi `request_id` dan `sumber` selalu dievaluasi BERSAMA. Slug yang tidak
     * cocok diperlakukan sebagai "tidak ditemukan", bukan error: dari sisi
     * pemanggil itu memang tidak ada jendela miliknya, dan membocorkan
     * keberadaan jendela orang lain lewat pesan error sudah jadi kebocoran.
     */
    public function findByRequestId(string $requestId, string $sumber): ?SrikandiOtpState
    {
        return SrikandiOtpState::query()
            ->where('request_id', $requestId)
            ->where('sumber', $sumber)
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
    public function findVerifiable(string $sumber, ?string $rawPhone = null, ?string $requestId = null): ?SrikandiOtpState
    {
        if ($requestId !== null && trim($requestId) !== '') {
            $byRequestId = $this->findByRequestId(trim($requestId), $sumber);

            if ($byRequestId !== null) {
                return $byRequestId;
            }

            /*
             * 🔴 `request_id` ada tapi slug-nya tidak cocok: JANGAN jatuh ke jalur
             * `phone` di bawah dengan diam-diam.
             *
             * Dua client sah bisa saja punya nomor yang sama persis. Kalau
             * `request_id` milik client A dikirim bersama `sumber` client B,
             * lalu pencarian lanjut ke nomor, hasilnya record A yang aktif --
             * dan pemanggil B ikut membaca `replies` dari device A. Dia mungkin
             * tidak merasa memakai `request_id` itu, tapi jalan tetap salah.
             *
             * Ketidakcocokan di sini berarti pemanggil salah mengetikkan
             * `request_id` miliknya sendiri, jadi "tidak ditemukan" adalah
             * jawaban yang benar.
             */
            return null;
        }

        if ($rawPhone !== null && trim($rawPhone) !== '') {
            $query = SrikandiOtpState::query()->wherePhone($this->requirePhone($rawPhone));

            $this->restrictToSumber($query, $sumber);

            return $query->orderByDesc('created_at')->first();
        }

        return null;
    }

    /**
     * Row paling relevan untuk diverifikasi, terkunci untuk pembaruan.
     */
    protected function lockLatestFor(string $sumber, ?string $rawPhone, ?string $requestId): ?SrikandiOtpState
    {
        if ($requestId !== null && trim($requestId) !== '') {
            // 🔴 Filter `sumber` berlaku di jalur `lockForUpdate()` juga, dengan
            // alasan yang sama seperti di `findVerifiable()`. Kalau tidak, locks
            // masih bisa diambil untuk record client lain dan `attempts`-nya
            // naik untuk jendela yang bukan miliknya.
            $state = SrikandiOtpState::query()
                ->where('request_id', trim($requestId))
                ->where('sumber', $sumber)
                ->lockForUpdate()
                ->first();

            if ($state !== null) {
                return $state;
            }

            return null;
        }

        if ($rawPhone !== null && trim($rawPhone) !== '') {
            $phoneQuery = SrikandiOtpState::query()->wherePhone($this->requirePhone($rawPhone));

            $this->restrictToSumber($phoneQuery, $sumber);

            return $phoneQuery->orderByDesc('created_at')->lockForUpdate()->first();
        }

        return null;
    }

    /**
     * 🔴 Batasi query ke satu client, dan JANGAN diam-diam jadi tanpa filter.
     *
     * Helper ini ada supaya tidak ada jalur yang bisa "lupa" memfilter sumber.
     * Karena `$sumber` sudah wajib dan tidak bisa `null`, tidak ada lagi
     * kondisi yang harus diterjemahkan: setiap query dijaga tepat satu client.
     *
     * Kolomnya `NOT NULL` tanpa default sejak penghapusan default `'default'`,
     * jadi di tabel production semua baris punya sumber dan tidak ada record
     * lama yang bisa masuk lewat filter yang longgar.
     */
    protected function restrictToSumber(Builder $query, string $sumber): void
    {
        $query->where('sumber', $sumber);
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
