<?php

use Bale\Srikandi\Models\SrikandiOtpState;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../helpers.php';

uses(RefreshDatabase::class)
    ->beforeEach(srikandiSetup());

describe('POST /otp-verify (spec §5.3)', function () {
    it('memverifikasi kode yang benar dan memindahkan state ke verified', function () {
        $state = seedPendingOtp('628123456789', '123456');

        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'default',
            'phone' => '628123456789',
            'code' => '123456',
        ], asScraper())
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('state', 'verified');

        expect($state->refresh()->state)->toBe(SrikandiOtpState::STATE_VERIFIED)
            ->and($state->verified_at)->not->toBeNull();
    });

    it('IDEMPOTEN: kode sama diverifikasi dua kali tetap 200', function () {
        seedPendingOtp('628123456789', '123456');

        $first = $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'default',
            'phone' => '628123456789', 'code' => '123456',
        ], asScraper());

        $second = $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'default',
            'phone' => '628123456789', 'code' => '123456',
        ], asScraper());

        $first->assertOk()->assertJsonPath('state', 'verified');
        $second->assertOk()->assertJsonPath('state', 'verified');
    });

    it('verified_at diisi SEKALI saja', function () {
        $state = seedPendingOtp('628123456789', '123456');

        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'default',
            'phone' => '628123456789', 'code' => '123456',
        ], asScraper())->assertOk();

        $firstVerifiedAt = $state->refresh()->verified_at;

        $this->travel(5)->minutes();

        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'default',
            'phone' => '628123456789', 'code' => '123456',
        ], asScraper())->assertOk();

        expect($state->refresh()->verified_at->toDateTimeString())
            ->toBe($firstVerifiedAt->toDateTimeString());
    });

    it('menaikkan attempts pada kode salah', function () {
        $state = seedPendingOtp('628123456789', '123456');

        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'default',
            'phone' => '628123456789', 'code' => '999999',
        ], asScraper())
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('attempts', 1);

        expect($state->refresh()->attempts)->toBe(1)
            ->and($state->state)->toBe(SrikandiOtpState::STATE_PENDING);
    });

    it('kode salah TIDAK pernah membocorkan kode yang benar', function () {
        seedPendingOtp('628123456789', '123456');

        $response = $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'default',
            'phone' => '628123456789', 'code' => '999999',
        ], asScraper());

        expect($response->getContent())->not->toContain('123456');
    });

    it('mengembalikan 410 untuk kode kedaluwarsa', function () {
        $state = seedPendingOtp('628123456789', '123456', [
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'default',
            'phone' => '628123456789', 'code' => '123456',
        ], asScraper())
            ->assertStatus(410)
            ->assertJsonPath('ok', false);

        expect($state->refresh()->state)->toBe(SrikandiOtpState::STATE_EXPIRED);
    });

    it('pesan 410 menyebut kedaluwarsa, bukan "kode salah"', function () {
        seedPendingOtp('628123456789', '123456', ['expires_at' => now()->subMinute()]);

        $response = $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'default',
            'phone' => '628123456789', 'code' => '123456',
        ], asScraper());

        // Spec §5.3: keduanya keputusan operasional berbeda.
        expect($response->json('message'))->toContain('kedaluwarsa')
            ->and($response->json('message'))->not->toContain('tidak valid');
    });

    it('record yang sudah verified tetap idempoten meski lewat masa berlaku', function () {
        $state = seedPendingOtp('628123456789', '123456');

        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'default',
            'phone' => '628123456789', 'code' => '123456',
        ], asScraper())->assertOk();

        $this->travel(10)->minutes();

        // Idempoten menang atas waktu: scraper yang timeout lalu mencoba ulang
        // tidak boleh melihat 410 untuk kode yang sudah berhasil.
        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'default',
            'phone' => '628123456789', 'code' => '123456',
        ], asScraper())
            ->assertOk()
            ->assertJsonPath('state', 'verified');
    });

    it('memindahkan state ke expired setelah batas percobaan', function () {
        config()->set('srikandi.otp.max_attempts', 3);

        $state = seedPendingOtp('628123456789', '123456');

        foreach (range(1, 2) as $ignored) {
            $this->postJson('/api/v1/srikandi/otp-verify', [
                'sumber' => 'default',
                'phone' => '628123456789', 'code' => '999999',
            ], asScraper())->assertStatus(422);
        }

        expect($state->refresh()->state)->toBe(SrikandiOtpState::STATE_PENDING);

        // Percobaan ketiga menyentuh batas -> expired.
        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'default',
            'phone' => '628123456789', 'code' => '999999',
        ], asScraper())->assertStatus(429);

        expect($state->refresh()->state)->toBe(SrikandiOtpState::STATE_EXPIRED)
            ->and($state->attempts)->toBe(3);
    });

    it('kode benar SETELAH attempts habis tidak lagi diterima', function () {
        config()->set('srikandi.otp.max_attempts', 3);

        $state = seedPendingOtp('628123456789', '123456');

        foreach (range(1, 3) as $ignored) {
            $this->postJson('/api/v1/srikandi/otp-verify', [
                'sumber' => 'default',
                'phone' => '628123456789', 'code' => '999999',
            ], asScraper());
        }

        expect($state->refresh()->state)->toBe(SrikandiOtpState::STATE_EXPIRED);

        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'default',
            'phone' => '628123456789', 'code' => '123456',
        ], asScraper())->assertStatus(410);
    });

    it('menolak kode dengan panjang digit salah tanpa menaikkan attempts', function () {
        $state = seedPendingOtp('628123456789', '123456');

        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'default',
            'phone' => '628123456789', 'code' => '123',
        ], asScraper())->assertStatus(422);

        // Bukan percobaan verifikasi: tidak boleh menghabiskan jatah.
        expect($state->refresh()->attempts)->toBe(0);
    });

    it('menerima kode dengan spasi dan tanda hubung', function () {
        $state = seedPendingOtp('628123456789', '123456');

        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'default',
            'phone' => '628123456789', 'code' => '123 456',
        ], asScraper())
            ->assertOk()
            ->assertJsonPath('state', 'verified');
    });

    it('mengembalikan 404 bila tidak ada permintaan untuk nomor itu', function () {
        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'default',
            'phone' => '628999999999', 'code' => '123456',
        ], asScraper())->assertStatus(404);
    });

    it('menormalkan nomor sebelum mencari record', function () {
        $state = seedPendingOtp('628123456789', '123456');

        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'default',
            'phone' => '08123456789', 'code' => '123456',
        ], asScraper())->assertOk();
    });
});

describe('penetapan attempts di bawah lock (spec §5.3)', function () {
    it('dua percobaan salah bersamaan tidak menimpa attempts satu sama lain', function () {
        config()->set('srikandi.otp.max_attempts', 10);

        $state = seedPendingOtp('628123456789', '123456');

        // Verifikasi berurutan meniru dua request yang tumpang tindih; tanpa
        // `lockForUpdate` keduanya membaca attempts=0 lalu menulis 1.
        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'default',
            'phone' => '628123456789', 'code' => '999999',
        ], asScraper());

        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'default',
            'phone' => '628123456789', 'code' => '999999',
        ], asScraper());

        expect($state->refresh()->attempts)->toBe(2);
    });
});
