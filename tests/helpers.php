<?php

/*
|--------------------------------------------------------------------------
| Helper test bale/srikandi
|--------------------------------------------------------------------------
|
| Hanya berisi FUNGSI. Setup tidak ada di sini — itu hidup di trait
| `SrikandiTestSetup` dan dipasang lewat `uses()`.
|
| Fungsi global aman di-`require_once`: sekali terdefinisi berlaku untuk
| seluruh proses, tidak bergantung urutan file.
|
*/

use Bale\Api\Models\ApiToken;
use Bale\Api\Services\TokenManager;
use Bale\Srikandi\Commands\InstallCommand;
use Bale\Srikandi\Models\SrikandiOtpState;
use Bale\Srikandi\SrikandiServiceProvider;
use Bale\Srikandi\Support\OtpCode;
use Bale\Wara\Models\WaraClient;
use Bale\Wara\Models\WaraRoute;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Setup bersama, dipasang di tiap file test lewat:
 *
 *     uses(RefreshDatabase::class)->beforeEach(srikandiSetup());
 *
 * 🔴 Kenapa closure, bukan `beforeEach()` di dalam file yang di-`require`?
 *
 * `beforeEach()` yang dipanggil saat file di-`require` diikat ke file test yang
 * SEDANG diparsing. Karena file setup di-`require_once`, hanya file PERTAMA yang
 * menjalankannya — sisanya lolos tanpa setup sama sekali.
 *
 * Gejalanya sangat menyesatkan: test OTP gagal dengan `422
 * otp_delivery_failed` yang jelas-jelas bukan soal device. Penyebab sebenarnya
 * `Http::fake()` tidak pernah dipasang, jadi test menembak gateway sungguhan
 * dari `.env` lalu menunggu connection timeout.
 *
 * Trait punya masalah lain di sini: method `setUp()` bentrok dengan
 * `Pest\Concerns\Testable::setUp`.
 */
function srikandiSetup(): Closure
{
    return function () {
        $this->app->register(WaraServiceProvider::class);
        $this->app->register(SrikandiServiceProvider::class);

        // `.env` lokal menunjuk gateway sungguhan. Wajib override, dan
        // `preventStrayRequests()` membuat test GAGAL kalau ada stub yang
        // lupa — bukan diam-diam menembak device sungguhan.
        config()->set('wara.endpoint', 'http://gateway.test');
        config()->set('wara.api_key', 'user:pass');
        config()->set('wara.webhook_secret', 'rahasia-webhook');
        config()->set('wara.default_device_id', '');

        config()->set('srikandi.otp.ttl_minutes', 5);
        config()->set('srikandi.otp.max_attempts', 5);
        config()->set('srikandi.otp.code_length', 6);

        // 🔴 WAJIB, bukan opsional.
        //
        // `BlindIndex::indexKey()` sengaja TIDAK fallback ke `APP_KEY`, jadi tanpa
        // env ini setiap test yang menyentuh nomor akan melempar RuntimeException.
        // Itu perilaku yang benar di produksi -- lebih baik error eksplisit daripada
        // diam-diam memakai kunci enkripsi untuk index.
        //
        // Nilai di sini sengaja BUKAN `APP_KEY`, supaya test juga membuktikan
        // keduanya memang dipisah.
        config()->set('srikandi.index_key', 'kunci-index-test-yang-berbeda-dari-app-key');

        Http::preventStrayRequests();
        Http::fake([
            'gateway.test/*' => Http::response([
                'id' => 'msg-1',
                'status' => 'accepted',
            ], 200),
        ]);
    };
}

/**
 * 🔴 DIHAPUS: `baleUuid()`.
 *
 * Fungsi ini pernah ada untuk menghasilkan UUID `bale_lists.id` di payload
 * ingest. Sekarang `srikandi_naskah` tidak punya kolom `bale_id` sama sekali -
 * cache dari satu mailbox SRIKANDI tidak berhubungan dengan katalog tenant
 * database. Lihat catatan di `create_srikandi_naskah_table`.
 *
 * Sengaja tidak diganti jadi fungsi lain: tidak ada lagi yang perlu UUID
 * organisasi di package ini.
 */

/**
 * Token scraper dengan scope yang diminta.
 *
 * 🔴 Default-nya include SEMUA scope Srikandi yang ada, termasuk
 * `srikandi.client.credentials`.
 *
 * Itu disengaja, dan bukan berarti credential perlu longgar di produksi.
 * Justru sebaliknya: test yang memanggil endpoint kredensial tanpa
 * menyebut scope eksplisit akan gagal 403 kalau scope-nya lupa ditambahkan
 * di sini -- dan pesan errornya langsung menunjukkan scope mana yang kurang.
 *
 * Yang menguji pemisahan scope SELALU menyebut scope-nya eksplisit
 * (`scraperToken(['srikandi.client.read'])`). Kalau tidak, test pemisahan
 * scope akan lulus karena defaultnya sudah longgar, bukan karena pemisahan
 * Working dengan benar.
 *
 * @param  list<string>  $scopes
 * @return array{plain: string, model: ApiToken}
 */
function scraperToken(array $scopes = [
    'srikandi.otp.read',
    'srikandi.otp.write',
    'srikandi.naskah.write',
    'srikandi.client.read',
    'srikandi.client.credentials',
]): array
{
    return app(TokenManager::class)->issue('Scraper Srikandi', $scopes);
}

/**
 * Header Authorization untuk token scraper.
 *
 * @return array<string, string>
 */
function asScraper(?string $plain = null): array
{
    return ['Authorization' => 'Bearer '.($plain ?? scraperToken()['plain'])];
}

/**
 * Device yang siap kirim, supaya `sendOtp` lolos resolusi (wara PRD §6.5).
 *
 * 🔴 Penugasan purpose TIDAK LAGI di `WaraSession`.
 *
 * `wara_sessions.purpose` sudah dihapus. Kolom itu unik GLOBAL, jadi hanya
 * satu device di seluruh instalasi yang boleh punya satu purpose — menambah
 * klien kedua untuk `notifikasi` jadi mustahil. Penugasan pindah ke
 * `wara_routes` dengan `UNIQUE (client_id, purpose)`.
 *
 * Helper lama menulis `forceFill(['purpose' => ...])`, dan itu gagal dengan
 * `table wara_sessions has no column named purpose` — bukan karena logikanya
 * salah, tapi karena kolomnya sudah tidak ada. 26 test gagal karena itu.
 *
 * Jadi sekarang penugasan lewat client + route, sesuai skema yang sebenarnya.
 * Device sendiri tetap di `WaraSession`, hanya "purpose-nya siapa" yang pindah.
 *
 * `$jid` default-nya nomor realistis (12 digit). Nomor pendek seperti `628111`
 * membuat jalur penyamaran `phone_masked` mengambil cabang "sembunyikan penuh",
 * bukan cabang yang dipakai dunia nyata.
 */
function seedSendableDevice(
    string $deviceId = 'dev-1',
    ?string $purpose = 'otp',
    string $jid = '628000000001@s.whatsapp.net',
): void {
    $session = new WaraSession([
        'device_id' => $deviceId,
        'jid' => $jid,
        'display_name' => 'Device OTP',
        'is_present' => true,
        'is_connected' => true,
        'is_logged_in' => true,
        'last_seen_at' => now(),
    ]);

    $session->save();

    if ($purpose === null) {
        return;
    }

    // 🔴 Client harus bernama PERSIS seperti yang dicari `OtpService`.
    //
    // `sessionForPurpose()` mengambil client lewat
    // `DeviceRouter::defaultServiceClient()` - yang mengembalikan client tipe
    // `service` PERTAMA, bukan client dengan nama tertentu. Test lama memakai
    // `client-test`, dan selama `client-test` satu-satunya client itu
    // kebetulan cocok.
    //
    // Begitu test lain membuat client `service` lebih dulu, `defaultServiceClient()`
    // akan mengembalikan yang itu dan route di sini tidak pernah terlihat.
    // Gejalanya `422 no_otp_device` di test yang tidak menyangkut device sama
    // sekali - sulit ditelusuri karena tidak ada yang salah.
    $client = WaraClient::query()->firstOrCreate(
        ['name' => InstallCommand::WARA_CLIENT_NAME],
        ['type' => WaraClient::TYPE_SERVICE, 'is_active' => true],
    );

    // `client_id` sengaja di luar `WaraRoute::$fillable`, jadi harus lewat
    // forceFill - sama seperti test di package wara sendiri yang memakai
    // `makeRouteDeviceResolutionTest()`. Kalau diisi lewat `create()`, nilainya
    // dibuang diam-diam dan SQLite protes `NOT NULL constraint failed:
    // wara_routes.client_id`.
    $route = WaraRoute::query()
        ->where('client_id', $client->id)
        ->where('purpose', $purpose)
        ->first();

    if ($route === null) {
        $route = new WaraRoute;
        $route->forceFill([
            'client_id' => $client->id,
            'purpose' => $purpose,
        ]);
    }

    $route->forceFill([
        'device_id' => $deviceId,
        'outbound_enabled' => true,
    ])->save();
}

/**
 * Device yang jadi pemilik jendela OTP pada test.
 *
 * 🔴 Ini HARUS sama dengan `device_id` yang dipakai `seedIncoming()`.
 *
 * Polling `otp-pending` menyaring `wara_logs.device_id` — bukan `chat_id`.
 * `device_id` adalah PENERIMA, `chat_id` adalah PENGIRIM. Test yang menyemai
 * `chat_id` dengan nomor tujuan terlihat benar hanya karena filter lamanya
 * salah arah, dan ikut salah begitu filter diperbaiki.
 */
function otpTestDeviceId(): string
{
    return 'dev-otp';
}

/**
 * Record OTP pending dengan kode yang dipilih pemanggil.
 *
 * Kode polos dikembalikan ke TEST, bukan ke production code, supaya test bisa
 * membuktikan normalisasi dan pencocokan tanpa perlu membaca hash.
 *
 * `request_id` selalu diisi — begitu juganya record yang dibuat `OtpService`.
 * Tanpa itu, test yang memverifikasi lewat `request_id` akan memakai NULL dan
 * diam-diam jatuh ke jalur `phone`.
 *
 * `session_key` diisi `otpTestDeviceId()` karena itulah device yang memegang
 * jendela; polling membaca darinya. Kalau dibiarkan NULL, endpoint mengembalikan
 * `replies: []` dengan sengaja — bukan error, dan bukan kebocoran pesan device
 * lain.
 */
function seedPendingOtp(string $phone, string $code, array $attributes = []): SrikandiOtpState
{
    $state = new SrikandiOtpState(array_merge([
        'request_id' => (string) Str::uuid(),
        'purpose' => 'otp',
        'phone' => $phone,
        'state' => SrikandiOtpState::STATE_PENDING,
        'attempts' => 0,
        'session_key' => otpTestDeviceId(),
        'opened_at' => now(),
        'expires_at' => now()->addMinutes(5),
    ], $attributes));

    $state->setCodeHash(OtpCode::hash($code));
    $state->save();

    return $state;
}
