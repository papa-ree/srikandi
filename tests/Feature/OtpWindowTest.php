<?php

use Bale\Srikandi\Commands\InstallCommand;
use Bale\Srikandi\Models\SrikandiOtpState;
use Bale\Srikandi\Support\PhoneMask;
use Bale\Wara\Events\WaraIncomingMessage;
use Bale\Wara\Models\WaraClient;
use Bale\Wara\Models\WaraLog;
use Bale\Wara\Models\WaraRoute;
use Bale\Wara\Models\WaraSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../helpers.php';

uses(RefreshDatabase::class)
    ->beforeEach(srikandiSetup());

/**
 * Pesan masuk untuk device tertentu, seperti webhook GOWA.
 *
 * 🔴 `$phone` adalah PENGIRIM (`chat_id`), `$deviceId` adalah PENERIMA.
 * Default `dev-otp` karena itu device yang dipakai `seedSendableDevice('dev-otp')`
 * di mayoritas test — dan `otp-pending` menyaring `device_id`, bukan `chat_id`.
 *
 * Dulu helper ini hardcode `dev-1` sementara jendela dibuka di `dev-otp`, dan
 * test tetap hijau - karena `chat_id` diperiksa lebih dulu sehingga device yang
 * salah tidak pernah terlihat. Setelah `otp-pending` menyaring `device_id`,
 * ketidakcocokan itu jadi muncul.
 */
function incomingBefore(string $phone, string $body, $at = null, string $deviceId = 'dev-otp'): WaraLog
{
    $log = WaraLog::query()->create([
        'direction' => 'in',
        'event' => 'message',
        'device_id' => $deviceId,
        'chat_id' => $phone.'@s.whatsapp.net',
        'phone' => $phone,
        'message_id' => 'msg-'.uniqid(),
        'body' => $body,
        'status' => 'received',
    ]);

    if ($at !== null) {
        // `created_at` dipakai sebagai batas bawah jendela saat `opened_at` null.
        WaraLog::query()->whereKey($log->id)->update([
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    return $log->fresh();
}

describe('kontrak v2: jendela listening (spec §5.0b)', function () {
    it('1. tanpa phone -> 201, ada request_id, phone dari device purpose=otp', function () {
        seedSendableDevice('dev-otp', 'otp');

        $response = $this->postJson('/api/v1/srikandi/otp-request', [
            'sumber' => 'default',
            'purpose' => 'otp',
        ], asScraper());

        $response->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('state', 'pending')
            ->assertJsonPath('reused', false);

        $state = SrikandiOtpState::query()->firstOrFail();

        expect($state->request_id)->not->toBeNull()
            ->and($state->request_id)->toBe($response->json('request_id'))
            // Nomor diambil dari `jid` device: 628111 + @s.whatsapp.net.
            ->and($state->phone)->toBe('628000000001')
            ->and($state->code_hash)->toBeNull();
    });

    it('2. tanpa phone dan tanpa device purpose=otp -> error yang menyebut device', function () {
        // Tidak ada device sama sekali.
        $response = $this->postJson('/api/v1/srikandi/otp-request', [
            'sumber' => 'default',
            'purpose' => 'otp',
        ], asScraper());

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'no_otp_device');

        expect($response->json('message'))->toContain('purpose');

        // 🔴 Tidak boleh diam-diam memakai device default.
        expect(SrikandiOtpState::query()->count())->toBe(0);
    });

    it('2b. device purpose=otp ada tapi tidak siap -> error menyebut DEVICE itu', function () {
        // Device tercatat tapi belum login -> harus gagal keras, bukan fallback.
        $session = new WaraSession([
            'device_id' => 'dev-offline',
            'jid' => '628999@s.whatsapp.net',
            'display_name' => 'Device Mati',
            'is_present' => true,
            'is_connected' => true,
            'is_logged_in' => false,
            'last_seen_at' => now(),
        ]);

        $session->save();

        // 🔴 Penugasan purpose tidak lagi ke `WaraSession`. Kolom itu unik
        // global dan sudah dihapus; sekarang ada di `wara_routes` dengan
        // `UNIQUE (client_id, purpose)`. `client_id` di luar `$fillable`, jadi
        // lewat `forceFill`.
        // 🔴 Client HARUS milik Srikandi, bukan client generik.
        //
        // `OtpService::sessionForPurpose()` mencari client lewat
        // `DeviceRouter::defaultServiceClient()` dan route-nya di-filter
        // `client_id`. Route yang dibuat di client lain tidak akan pernah terlihat -
        // gejalanya test gagal dengan 422 padahal device-nya sudah ada dan route-nya
        // sudah dibuat.
        $client = WaraClient::query()->firstOrCreate(
            ['name' => InstallCommand::WARA_CLIENT_NAME],
            ['type' => WaraClient::TYPE_SERVICE, 'is_active' => true],
        );

        $route = new WaraRoute;
        $route->forceFill([
            'client_id' => $client->id,
            'purpose' => 'otp',
            'device_id' => 'dev-offline',
            'outbound_enabled' => true,
        ])->save();

        $response = $this->postJson('/api/v1/srikandi/otp-request', [
            'sumber' => 'default',
            'purpose' => 'otp',
        ], asScraper());

        $response->assertStatus(422);

        expect($response->getContent())->toContain('Device Mati')
            ->and(SrikandiOtpState::query()->count())->toBe(0);
    });

    it('3. dua kali -> jendela sama, 200 + reused:true', function () {
        seedSendableDevice('dev-otp', 'otp');

        $first = $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'purpose' => 'otp'], asScraper());
        $first->assertCreated();

        $second = $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'purpose' => 'otp'], asScraper());

        $second->assertOk()
            ->assertJsonPath('reused', true)
            ->assertJsonPath('state', 'pending');

        expect($second->json('request_id'))->toBe($first->json('request_id'))
            ->and(SrikandiOtpState::query()->count())->toBe(1);
    });

    it('4. TIDAK ada pesan WhatsApp yang dikirim saat membuka jendela', function () {
        seedSendableDevice('dev-otp', 'otp');

        $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'purpose' => 'otp'], asScraper())
            ->assertCreated();

        // 🔴 Ini assertion terpenting dari seluruh kontrak v2. Kalau Bale
        // mengirim OTP buatan sendiri, operator menerima dua kode dan scraper
        // bisa mengambil yang salah.
        Http::assertNothingSent();
    });

    it('4b. jendela tidak pernah mengarang kode OTP', function () {
        seedSendableDevice('dev-otp', 'otp');

        $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'purpose' => 'otp'], asScraper())
            ->assertCreated();

        $state = SrikandiOtpState::query()->firstOrFail();

        expect($state->isWindow())->toBeTrue()
            ->and($state->getAttribute('code_hash'))->toBeNull();
    });
});

describe('kontrak v2: otp-pending dibatasi opened_at (spec §5.0b)', function () {
    it('5. pesan sebelum opened_at TIDAK dikembalikan, walau tanpa after', function () {
        seedSendableDevice('dev-otp', 'otp');

        // Pesan lama dari siklus sebelumnya, 10 menit lalu.
        incomingBefore('628000000001', '999111', now()->subMinutes(10));

        $this->travel(2)->minutes();

        $window = $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'purpose' => 'otp'], asScraper())
            ->assertCreated()
            ->json('request_id');

        // Pesan baru, sesudah jendela dibuka.
        incomingBefore('628000000001', '123456');

        $response = $this->getJson(
            '/api/v1/srikandi/otp-pending?sumber=default&request_id='.$window,
            asScraper()
        );

        $response->assertOk();

        $bodies = collect($response->json('replies'))->pluck('body');

        expect($bodies)->toContain('123456')
            ->and($bodies)->not->toContain('999111');
    });

    it('6. request_id valid -> hanya pesan jendela itu', function () {
        seedSendableDevice('dev-otp', 'otp');

        $windowA = $this->postJson('/api/v1/srikandi/otp-request', [
            'sumber' => 'default',
            'purpose' => 'otp', 'session_key' => 'a',
        ], asScraper())->json('request_id');

        // Jendela kedua untuk nomor/device lain yang sudah lewat.
        $this->travel(10)->minutes();

        seedSendableDevice('dev-otp-2', 'otp-2');
        $this->travel(1)->minutes();

        // 🔴 Device `dev-otp-2` SECARA EKSPLISIT. Kalau device-nya dibiarkan
        // default (`dev-otp`), pesannya masuk ke device yang SAMA dengan jendela
        // `windowA` dan test ini tidak lagi menguji apa yang diklaimnya.
        incomingBefore('628000000001', '111111', null, 'dev-otp-2');

        $response = $this->getJson(
            '/api/v1/srikandi/otp-pending?sumber=default&request_id='.$windowA,
            asScraper()
        );

        $response->assertOk();

        expect(collect($response->json('replies'))->pluck('body'))->not->toContain('111111');
    });

    it('7. request_id tak dikenal -> [] , bukan 500, bukan bocor', function () {
        seedSendableDevice('dev-otp', 'otp');

        $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'purpose' => 'otp'], asScraper())
            ->assertCreated();

        incomingBefore('628000000001', '123456');

        $this->getJson(
            '/api/v1/srikandi/otp-pending?sumber=default&request_id='.Str::uuid(),
            asScraper()
        )
            ->assertOk()
            ->assertJsonPath('replies', []);
    });

    it('7b. request_id milik jendela yang sudah kedaluwarsa -> []', function () {
        seedSendableDevice('dev-otp', 'otp');

        $window = $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'purpose' => 'otp'], asScraper())
            ->json('request_id');

        incomingBefore('628000000001', '123456');

        $this->travel(10)->minutes();

        $this->getJson('/api/v1/srikandi/otp-pending?sumber=default&request_id='.$window, asScraper())
            ->assertOk()
            ->assertJsonPath('replies', []);
    });
});

describe('kontrak v2: backward compatibility', function () {
    it('8. phone eksplisit -> 201 dan TETAP mengirim (jalur lama utuh)', function () {
        seedSendableDevice();

        $this->postJson('/api/v1/srikandi/otp-request', [
            'sumber' => 'default',
            'phone' => '628123456789',
        ], asScraper())->assertCreated();

        // Jalur lama: Bale mengarang kode dan mengirimnya.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/send/message'));

        expect(SrikandiOtpState::query()->firstOrFail()->getAttribute('code_hash'))
            ->not->toBeNull();
    });

    it('8b. phone eksplisit kedua kali -> 200, tidak kirim ulang', function () {
        seedSendableDevice();

        $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'phone' => '628123456789'], asScraper())
            ->assertCreated();
        $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'phone' => '628123456789'], asScraper())
            ->assertOk();

        Http::assertSentCount(1);
    });

    it('9. otp-verify dengan phone masih jalan', function () {
        seedPendingOtp('628123456789', '123456');

        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'default',
            'phone' => '628123456789',
            'code' => '123456',
        ], asScraper())
            ->assertOk()
            ->assertJsonPath('state', 'verified');
    });

    it('9b. otp-verify dengan request_id juga jalan', function () {
        $state = seedPendingOtp('628123456789', '123456');

        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'default',
            'request_id' => $state->request_id,
            'code' => '123456',
        ], asScraper())
            ->assertOk()
            ->assertJsonPath('state', 'verified');
    });

    it('9c. otp-verify tanpa identitas ditolak', function () {
        $this->postJson('/api/v1/srikandi/otp-verify', ['sumber' => 'default', 'code' => '123456'], asScraper())
            ->assertStatus(422);
    });
});

describe('kontrak v2: verifikasi jendela ditolak dengan jelas', function () {
    it('10. jendela tanpa kode_hash ditolak jelas, tidak crash, tidak naikkan attempts', function () {
        seedSendableDevice('dev-otp', 'otp');

        $window = $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'purpose' => 'otp'], asScraper())
            ->json('request_id');

        $state = SrikandiOtpState::query()->where('request_id', $window)->firstOrFail();

        $response = $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'default',
            'request_id' => $window,
            'code' => '123456',
        ], asScraper());

        $response->assertStatus(422)
            ->assertJsonPath('code', 'window_not_verifiable');

        // Tidak boleh diperlakukan sebagai "kode salah": jatah percobaan utuh.
        expect($state->refresh()->attempts)->toBe(0)
            ->and($state->state)->toBe(SrikandiOtpState::STATE_PENDING);
    });

    it('10b. pesan dengan code_hash null tidak merusak listener', function () {
        seedSendableDevice('dev-otp', 'otp');

        $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'purpose' => 'otp'], asScraper())
            ->assertCreated();

        $state = SrikandiOtpState::query()->firstOrFail();

        // Inbound dengan isi yang kebetulan 6 digit TIDAK boleh memverifikasi.
        WaraIncomingMessage::dispatch(
            'dev-otp',
            'message',
            'msg-1',
            '628000000001',
            '628000000001@s.whatsapp.net',
            '123456',
            '{}',
            now()->toIso8601String(),
        );

        expect($state->refresh()->state)->toBe(SrikandiOtpState::STATE_PENDING);
    });
});

describe('kontrak v2: request_id tidak membocorkan apa pun', function () {
    it('11. request_id adalah UUID acak, tidak berisi digit nomor', function () {
        seedSendableDevice('dev-otp', 'otp');

        $first = $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'purpose' => 'otp'], asScraper())
            ->json('request_id');

        $state = SrikandiOtpState::query()->firstOrFail();

        // UUID v4: bentuk standar, tanpa digit nomor di dalamnya.
        expect($first)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
            ->and($first)->not->toContain('628000000001');

        $digits = preg_replace('/\D+/', '', $state->request_id) ?? '';

        // Nomor tujuan 6 digit tidak boleh muncul utuh di request_id.
        expect($digits)->not->toContain('628000000001');
    });

    it('11b. dua jendela untuk nomor sama punya request_id berbeda', function () {
        seedSendableDevice('dev-otp', 'otp');

        $a = $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'purpose' => 'otp'], asScraper())
            ->json('request_id');

        // Paksa jendela pertama tutup supaya yang kedua benar-benar baru.
        SrikandiOtpState::query()->update(['state' => 'expired']);

        $b = $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'purpose' => 'otp'], asScraper())
            ->json('request_id');

        expect($a)->not->toBe($b);
    });

    it('12. phone_masked tidak pernah memuat nomor penuh', function () {
        seedSendableDevice('dev-otp', 'otp');

        $masked = $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'purpose' => 'otp'], asScraper())
            ->json('phone_masked');

        expect($masked)->not->toBeNull()
            ->and($masked)->toContain('…')
            ->and(PhoneMask::leaksFullNumber($masked, '628000000001'))->toBeFalse();
    });

    it('12b. nomor penuh tidak pernah ada di respons mana pun', function () {
        seedSendableDevice('dev-otp', 'otp');

        $response = $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'purpose' => 'otp'], asScraper());

        // `628111` muncul di phone_masked sebagai 4 digit depan — itu memang
        // disengaja. Yang dilarang adalah nomor penuh sebagai nilai tersendiri.
        expect($response->json())->not->toHaveKey('phone')
            ->and($response->json('phone_masked'))->not->toBe('628000000001');
    });

    it('12c. penyamaran tidak bocor untuk nomor pendek', function () {
        // Nomor 6 digit: empat digit depan + empat digit belakang akan
        // mengembalikan seluruh nomor, jadi harus disembunyikan penuh.
        $short = '628111';

        expect(PhoneMask::mask($short))
            ->toBe('******')
            ->and(PhoneMask::leaksFullNumber(
                PhoneMask::mask($short),
                $short
            ))->toBeFalse();
    });

    it('12d. nomor realistic: empat digit depan dan belakang saja', function () {
        $masked = PhoneMask::mask('628000000001');

        expect($masked)->toBe('6280…0001')
            ->and(PhoneMask::leaksFullNumber($masked, '628000000001'))
            ->toBeFalse();
    });
});
