<?php

use Bale\Srikandi\Models\SrikandiOtpState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../helpers.php';

uses(RefreshDatabase::class)
    ->beforeEach(srikandiSetup());

describe('POST /otp-request (spec §5.1)', function () {
    it('membuat record pending dan mengirim pesan', function () {
        seedSendableDevice();

        $response = $this->postJson('/api/v1/srikandi/otp-request', [
            'sumber' => 'default',
            'phone' => '628123456789',
        ], asScraper());

        $response->assertCreated()->assertJsonPath('ok', true)
            ->assertJsonPath('state', 'pending');

        expect(SrikandiOtpState::query()->count())->toBe(1)
            ->and(SrikandiOtpState::query()->first()->phone)->toBe('628123456789');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/send/message'));
    });

    it('IDEMPOTEN: dua kali panggil menghasilkan satu record dan satu pesan', function () {
        seedSendableDevice();

        $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'phone' => '628123456789'], asScraper())
            ->assertCreated();

        $second = $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'phone' => '628123456789'], asScraper());

        $second->assertOk();

        expect(SrikandiOtpState::query()->count())->toBe(1);

        // Ini inti dari §5.1: tidak ada pesan kedua.
        Http::assertSentCount(1);
    });

    it('mengembalikan record yang sama pada panggilan kedua, bukan record baru', function () {
        seedSendableDevice();

        $first = $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'phone' => '628123456789'], asScraper());
        $second = $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'phone' => '628123456789'], asScraper());

        expect($first->json('state_id'))->toBe($second->json('state_id'));
    });

    it('TIDAK pernah mengembalikan kode OTP di respons', function () {
        seedSendableDevice();

        $response = $this->postJson('/api/v1/srikandi/otp-request', [
            'sumber' => 'default',
            'phone' => '628123456789',
        ], asScraper());

        $body = $response->getContent();

        // Kode dikirim lewat WhatsApp; tidak boleh muncul di respons HTTP.
        expect($body)->not->toContain('code')
            ->and(array_keys($response->json()))->not->toContain('code');
    });

    it('menolak nomor tidak valid dengan 422', function () {
        $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'phone' => 'abc'], asScraper())
            ->assertStatus(422);

        expect(SrikandiOtpState::query()->count())->toBe(0);
    });

    it('menormalkan nomor lokal 08xx ke format internasional', function () {
        seedSendableDevice();

        $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'phone' => '08123456789'], asScraper())
            ->assertCreated();

        expect(SrikandiOtpState::query()->first()->phone)->toBe('628123456789');
    });

    it('mengexpiredkan record dan gagal bila device tidak bisa kirim', function () {
        // Tidak ada device sama sekali -> resolusi gagal.
        $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'phone' => '628123456789'], asScraper())
            ->assertStatus(422);

        // Record TIDAK boleh tertinggal `pending`: itu akan mengunci nomor
        // sampai TTL habis tanpa kode yang pernah sampai.
        $state = SrikandiOtpState::query()->first();

        expect($state)->not->toBeNull()
            ->and($state->state)->toBe(SrikandiOtpState::STATE_EXPIRED);
    });

    it('menuliskan session_key yang diberikan scraper', function () {
        seedSendableDevice();

        $this->postJson('/api/v1/srikandi/otp-request', [
            'sumber' => 'default',
            'phone' => '628123456789',
            'purpose' => 'otp',
            'session_key' => 'wago-prod',
        ], asScraper())->assertCreated();

        $state = SrikandiOtpState::query()->first();

        expect($state->session_key)->toBe('wago-prod')
            ->and($state->purpose)->toBe('otp');
    });
});

describe('penyimpanan kode OTP (spec §5.1, §7)', function () {
    it('menyimpan kode sebagai hash, bukan polos', function () {
        seedSendableDevice();

        $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'phone' => '628123456789'], asScraper())
            ->assertCreated();

        $hash = (string) SrikandiOtpState::query()->first()->getAttribute('code_hash');

        expect($hash)->not->toBe('')
            ->and($hash)->toStartWith('$2y$');

        // Hash tidak boleh cocok dengan bentuk polos 6 digit mana pun.
        expect(preg_match('/^\d{6}$/', $hash))->toBe(0);
    });

    it('TIDAK memuat kode OTP di log, bahkan saat verifikasi gagal', function () {
        // Arahkan log ke file sementara supaya isinya bisa dibaca balik.
        $logPath = storage_path('logs/srikandi-otp-test.log');
        @unlink($logPath);

        config()->set('logging.channels.srikandi_test', [
            'driver' => 'single',
            'path' => $logPath,
        ]);
        config()->set('logging.default', 'srikandi_test');

        seedSendableDevice();

        $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'phone' => '628123456789'], asScraper())
            ->assertCreated();

        // Ambil kode yang benar-benar terkirim ke gateway.
        $sent = '';

        Http::assertSent(function ($request) use (&$sent) {
            $sent = json_encode($request->data());

            return true;
        });

        preg_match('/\b(\d{6})\b/', (string) $sent, $m);
        $code = $m[1] ?? null;

        expect($code)->not->toBeNull();

        // Kode yang salah, supaya jalur pencatatan log ikut berjalan.
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'default',
            'phone' => '628123456789',
            'code' => $wrong,
        ], asScraper())->assertStatus(422);

        $logContent = is_file($logPath) ? (string) file_get_contents($logPath) : '';

        // Bukti bahwa log benar-benar ditulis — kalau tidak, test ini hampa.
        expect($logContent)->not->toBe('')
            ->and($logContent)->not->toContain($code);
    });

    it('menyembunyikan code_hash dari serialisasi model', function () {
        seedSendableDevice();

        $this->postJson('/api/v1/srikandi/otp-request', ['sumber' => 'default', 'phone' => '628123456789'], asScraper())
            ->assertCreated();

        $array = SrikandiOtpState::query()->first()->toArray();

        expect(array_key_exists('code_hash', $array))->toBeFalse();
    });
});
