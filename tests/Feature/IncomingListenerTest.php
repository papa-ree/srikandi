<?php

use Bale\Srikandi\Listeners\HandleIncomingMessage;
use Bale\Srikandi\Models\SrikandiOtpState;
use Bale\Srikandi\Support\OtpPhone;
use Bale\Wara\Events\WaraIncomingMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../helpers.php';

uses(RefreshDatabase::class)
    ->beforeEach(srikandiSetup());

function incoming(
    string $body,
    string $phone = '628123456789',
    string $chatId = '628123456789@s.whatsapp.net',
    string $deviceId = 'dev-1',
    string $event = 'message',
): WaraIncomingMessage {
    return new WaraIncomingMessage(
        $deviceId,
        $event,
        'msg-in-1',
        $phone,
        $chatId,
        $body,
        json_encode(['event' => $event]),
        now()->toIso8601String(),
    );
}

describe('listener balasan masuk (spec §6.1)', function () {
    it('terverifikasi saat kode cocok', function () {
        $state = seedPendingOtp('628123456789', '123456');

        (new HandleIncomingMessage(app(OtpPhone::class)))
            ->handle(incoming('123456'));

        expect($state->refresh()->state)->toBe(SrikandiOtpState::STATE_VERIFIED)
            ->and($state->verified_at)->not->toBeNull();
    });

    it('mencocokkan kode dengan mengabaikan spasi (spec §6.1)', function () {
        $state = seedPendingOtp('628123456789', '123456');

        (new HandleIncomingMessage(app(OtpPhone::class)))
            ->handle(incoming('123 456'));

        expect($state->refresh()->state)->toBe(SrikandiOtpState::STATE_VERIFIED);
    });

    it('mencocokkan kode dengan mengabaikan tanda hubung (spec §6.1)', function () {
        $state = seedPendingOtp('628123456789', '123456');

        (new HandleIncomingMessage(app(OtpPhone::class)))
            ->handle(incoming('123-456'));

        expect($state->refresh()->state)->toBe(SrikandiOtpState::STATE_VERIFIED);
    });

    it('mencocokkan kode dengan campuran spasi, hubung, dan karakter lain', function () {
        $state = seedPendingOtp('628123456789', '123456');

        (new HandleIncomingMessage(app(OtpPhone::class)))
            ->handle(incoming('otp saya 123-456 ya'));

        expect($state->refresh()->state)->toBe(SrikandiOtpState::STATE_VERIFIED);
    });

    it('TIDAK mengarang record untuk pesan tanpa permintaan pending', function () {
        $before = SrikandiOtpState::query()->count();

        (new HandleIncomingMessage(app(OtpPhone::class)))
            ->handle(incoming('123456'));

        // Ini inti §6.1: pesan biasa harus diabaikan, bukan dibuatkan state.
        expect(SrikandiOtpState::query()->count())->toBe($before);
    });

    it('mengabaikan nomor yang berbeda', function () {
        $state = seedPendingOtp('628999999999', '123456');

        (new HandleIncomingMessage(app(OtpPhone::class)))
            ->handle(incoming('123456', '628123456789'));

        expect($state->refresh()->state)->toBe(SrikandiOtpState::STATE_PENDING);
    });

    it('mengabaikan pesan dari group chat (@g.us)', function () {
        $state = seedPendingOtp('628123456789', '123456');

        (new HandleIncomingMessage(app(OtpPhone::class)))
            ->handle(incoming('123456', '628123456789', '120363@g.us'));

        expect($state->refresh()->state)->toBe(SrikandiOtpState::STATE_PENDING);
    });

    it('mengabaikan record yang sudah kedaluwarsa', function () {
        $state = seedPendingOtp('628123456789', '123456', [
            'expires_at' => now()->subMinute(),
        ]);

        (new HandleIncomingMessage(app(OtpPhone::class)))
            ->handle(incoming('123456'));

        expect($state->refresh()->state)->toBe(SrikandiOtpState::STATE_PENDING);
    });

    it('membiarkan pending saat kode tidak cocok, tanpa menaikkan attempts', function () {
        $state = seedPendingOtp('628123456789', '123456');

        (new HandleIncomingMessage(app(OtpPhone::class)))
            ->handle(incoming('999999'));

        // attempts milik endpoint otp-verify; pesan masuk salah ketik bukan
        // percobaan verifikasi.
        expect($state->refresh()->state)->toBe(SrikandiOtpState::STATE_PENDING)
            ->and($state->attempts)->toBe(0);
    });

    it('mengabaikan pesan biasa yang bukan angka', function () {
        $state = seedPendingOtp('628123456789', '123456');

        (new HandleIncomingMessage(app(OtpPhone::class)))
            ->handle(incoming('terima kasih'));

        expect($state->refresh()->state)->toBe(SrikandiOtpState::STATE_PENDING);
    });

    it('mengabaikan record yang sudah verified saat pesan masuk lagi', function () {
        $state = seedPendingOtp('628123456789', '123456', [
            'state' => SrikandiOtpState::STATE_VERIFIED,
            'verified_at' => now(),
        ]);

        $verifiedAt = $state->verified_at->toDateTimeString();

        (new HandleIncomingMessage(app(OtpPhone::class)))
            ->handle(incoming('123456'));

        expect($state->refresh()->verified_at->toDateTimeString())->toBe($verifiedAt);
    });

    it('menormalkan nomor pengirim dari format JID', function () {
        $state = seedPendingOtp('628123456789', '123456');

        (new HandleIncomingMessage(app(OtpPhone::class)))
            ->handle(incoming('123456', '628123456789@s.whatsapp.net'));

        expect($state->refresh()->state)->toBe(SrikandiOtpState::STATE_VERIFIED);
    });

    it('menangani beberapa record pending untuk satu nomor', function () {
        $older = seedPendingOtp('628123456789', '111111');
        $this->travel(1)->minutes();
        $newer = seedPendingOtp('628123456789', '222222');

        $listener = new HandleIncomingMessage(app(OtpPhone::class));

        $listener->handle(incoming('222222'));

        expect($newer->refresh()->state)->toBe(SrikandiOtpState::STATE_VERIFIED)
            ->and($older->refresh()->state)->toBe(SrikandiOtpState::STATE_PENDING);

        $listener->handle(incoming('111111'));

        expect($older->refresh()->state)->toBe(SrikandiOtpState::STATE_VERIFIED);
    });
});

describe('pendaftaran listener (spec §6)', function () {
    it('terhubung ke WaraIncomingMessage', function () {
        $listeners = Event::getRawListeners() ?? [];

        $found = false;

        foreach ($listeners as $event => $callbacks) {
            if ($event === WaraIncomingMessage::class) {
                $found = true;
            }
        }

        expect($found)->toBeTrue();
    });

    it('menjalankan listener otomatis saat event dipancarkan', function () {
        $state = seedPendingOtp('628123456789', '123456');

        WaraIncomingMessage::dispatch(
            'dev-1',
            'message',
            'msg-1',
            '628123456789',
            '628123456789@s.whatsapp.net',
            '123456',
            '{}',
            now()->toIso8601String(),
        );

        expect($state->refresh()->state)->toBe(SrikandiOtpState::STATE_VERIFIED);
    });

    it('TIDAK memproses event selain message', function () {
        $state = seedPendingOtp('628123456789', '123456');

        WaraIncomingMessage::dispatch(
            'dev-1',
            'message.ack',
            'msg-1',
            '628123456789',
            '628123456789@s.whatsapp.net',
            '123456',
            '{}',
            now()->toIso8601String(),
        );

        expect($state->refresh()->state)->toBe(SrikandiOtpState::STATE_PENDING);
    });
});
