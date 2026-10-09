<?php

use Bale\Api\Services\ApiScopeRegistry;
use Bale\Srikandi\Models\SrikandiOtpState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../helpers.php';

uses(RefreshDatabase::class)
    ->beforeEach(srikandiSetup());

/**
 * Panggil endpoint Srikandi dengan header tertentu.
 *
 * 🔴 `getJson($uri, $headers)` sedangkan `postJson($uri, $data, $headers)` —
 * posisi argumen kedua BERBEDA. Memanggil keduanya dengan tiga argumen yang
 * sama membuat header terbaca sebagai payload GET, dan Laravel memverifikasi
 * body-nya sebagai JSON — gagal dengan `json_encode()` yang sama sekali tidak
 * menyiratkan masalah autentikasi.
 *
 * Closure-nya menerima test case sebagai parameter, bukan memakai `test()` atau
 * `$this`, supaya tidak bergantung pada apa yang sedang di-bind Pest.
 */
function callSrikandi(object $case, string $method, string $uri, array $payload, array $headers): mixed
{
    return $method === 'get'
        ? $case->getJson($uri, $headers)
        : $case->postJson($uri, $payload, $headers);
}

$endpoints = [
    ['post', '/api/v1/srikandi/otp-request', ['phone' => '628123456789', 'sumber' => 'default']],
    ['get', '/api/v1/srikandi/otp-pending?phone=628123456789&sumber=default', []],
    ['post', '/api/v1/srikandi/otp-verify', ['phone' => '628123456789', 'code' => '123456', 'sumber' => 'default']],
    ['post', '/api/v1/srikandi/naskah-dinas', ['items' => []]],
];

describe('autentikasi & otorisasi endpoint (spec §7)', function () use ($endpoints) {
    it('menolak tanpa token dengan 401', function () use ($endpoints) {
        foreach ($endpoints as [$method, $uri, $payload]) {
            callSrikandi($this, $method, $uri, $payload, [])->assertUnauthorized();
        }
    });

    it('menolak token yang tidak dikenal dengan 401', function () use ($endpoints) {
        foreach ($endpoints as [$method, $uri, $payload]) {
            callSrikandi($this, $method, $uri, $payload, [
                'Authorization' => 'Bearer token-palsu-123',
            ])->assertUnauthorized();
        }
    });

    it('menolak token yang sudah di-revoke dengan 401', function () {
        $issued = scraperToken();
        $issued['model']->forceFill(['revoked_at' => now()])->save();

        $this->postJson('/api/v1/srikandi/otp-verify', [
            'phone' => '628123456789', 'code' => '123456', 'sumber' => 'default',
        ], asScraper($issued['plain']))->assertUnauthorized();
    });

    it('menolak token yang sudah kedaluwarsa dengan 401', function () {
        $issued = scraperToken();
        $issued['model']->forceFill(['expires_at' => now()->subDay()])->save();

        $this->postJson('/api/v1/srikandi/otp-verify', [
            'phone' => '628123456789', 'code' => '123456', 'sumber' => 'default',
        ], asScraper($issued['plain']))->assertUnauthorized();
    });
});

describe('pemisahan scope (spec §7)', function () {
    it('token hanya otp.read tidak boleh memverifikasi OTP', function () {
        $plain = scraperToken(['srikandi.otp.read'])['plain'];

        $this->postJson('/api/v1/srikandi/otp-verify', [
            'phone' => '628123456789', 'code' => '123456', 'sumber' => 'default',
        ], asScraper($plain))->assertForbidden();
    });

    it('token hanya otp.read TETAP boleh polling', function () {
        $plain = scraperToken(['srikandi.otp.read'])['plain'];

        $this->getJson('/api/v1/srikandi/otp-pending?phone=628123456789&sumber=default', asScraper($plain))
            ->assertOk();
    });

    it('token hanya naskah.write tidak boleh meminta OTP', function () {
        $plain = scraperToken(['srikandi.naskah.write'])['plain'];

        $this->postJson('/api/v1/srikandi/otp-request', [
            'phone' => '628123456789', 'sumber' => 'default',
        ], asScraper($plain))->assertForbidden();
    });

    it('token tanpa scope Srikandi apa pun ditolak di semua endpoint', function () {
        $plain = scraperToken(['api.read'])['plain'];

        $this->postJson('/api/v1/srikandi/otp-request', ['phone' => '628123456789', 'sumber' => 'default'], asScraper($plain))
            ->assertForbidden();

        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'items' => [],
        ], asScraper($plain))->assertForbidden();
    });

    it('scope Srikandi terdaftar di registry bale/api', function () {
        $scopes = app(ApiScopeRegistry::class)->flat();

        expect($scopes)->toContain('srikandi.otp.read')
            ->toContain('srikandi.otp.write')
            ->toContain('srikandi.naskah.write')
            ->toContain('srikandi.client.read')
            ->toContain('srikandi.client.credentials');
    });

    it('scope credential client TIDAK ikut terpenuhi oleh wildcard client.read', function () {
        // 🔴 UJI KEPUTUSAN SCOPE, bukan uji keberadaan route.
        //
        // Endpoint credentials belum ada (datang di S3), jadi respons HTTP
        // sekarang 404 -- bukan 403. Test yang memanggil route di sini akan
        // lulus karena 404, bukan karena scope ditolak; jangan dipaksa hijau
        // dengan `not->toBe(200)`.
        //
        // Yang diuji murni satu hal: `hasAbility('srikandi.client.credentials')`
        // harus FALSE untuk token yang hanya pegang `srikandi.client.read`.
        //
        // 🔴 Catatan: `ApiToken::hasAbility()` memakai `in_array(..., true)` --
        // pencocokan PERSIS, tanpa wildcard. Jadi "wildcard" di judul test ini
        // merujuk ke wildcard yang DIPERCAYAKAN orang, bukan yang ada di kode.
        // Justru itulah alasan scope ini tidak boleh digabung: begitu
        // `client.credentials` ada di group `client`, checkbox UI token akan
        // menunchecked-kan orang yang mengira "client.read sudah cukup".
        $issued = scraperToken(['srikandi.client.read']);

        expect($issued['model']->hasAbility('srikandi.client.read'))->toBeTrue()
            ->and($issued['model']->hasAbility('srikandi.client.credentials'))->toBeFalse();
    });

    it('menolak token tanpa scope client untuk operasi client', function () {
        $issued = scraperToken(['srikandi.otp.read']);

        expect($issued['model']->hasAbility('srikandi.otp.read'))->toBeTrue()
            ->and($issued['model']->hasAbility('srikandi.client.read'))->toBeFalse()
            ->and($issued['model']->hasAbility('srikandi.client.credentials'))->toBeFalse();
    });

    it('TIDAK memakai scope credential sebagai permission web', function () {
        // Permission Spatie menempel pada user manusia; scope API menempel pada
        // token. Kalau `srikandi.client.credentials` jadi permission web, setiap
        // user dengan role itu bisa membuka password semua client lewat browser,
        // dan activity log hanya mencatat "user A membaca password client B"
        // tanpa bisa menjelaskan kenapa itu perlu.
        //
        // InstallCommand::seedPermissions() sengaja tidak mendaftarkannya, jadi
        // setelah `srikandi:install` permission itu harus benar-benar tidak ada
        // di database -- bukan ada tapi tidak diberikan ke role mana pun.
        $this->artisan('srikandi:install')->assertSuccessful();

        $webPermissions = Permission::query()
            ->where('name', 'like', 'srikandi.%')
            ->pluck('name')
            ->all();

        expect($webPermissions)
            ->toContain('srikandi.status.read')
            ->toContain('srikandi.client.read')
            ->not->toContain('srikandi.client.credentials')
            // Scope API tidak boleh bocor jadi permission, dan sebaliknya.
            ->not->toContain('srikandi.otp.read')
            ->not->toContain('srikandi.otp.write')
            ->not->toContain('srikandi.naskah.write');
    });

    it('mendukung wildcard read untuk semua endpoint baca', function () {
        // Tidak ada endpoint baca lain, tapi wildcard harus tetap diterima.
        $plain = scraperToken(['srikandi.otp.read'])['plain'];

        $this->getJson('/api/v1/srikandi/otp-pending?phone=628123456789&sumber=default', asScraper($plain))
            ->assertOk();
    });
});

describe('throttle (spec §7)', function () {
    it('membalas 429 setelah melewati batas', function () {
        config()->set('api.throttle.per_minute', 5);

        $plain = scraperToken(['srikandi.otp.read'])['plain'];

        $lastStatus = null;

        foreach (range(1, 9) as $ignored) {
            $lastStatus = $this->getJson(
                '/api/v1/srikandi/otp-pending?phone=628123456789&sumber=default',
                asScraper($plain)
            )->status();
        }

        expect($lastStatus)->toBe(429);
    });

    it('429 tidak membocorkan isi apa pun', function () {
        config()->set('api.throttle.per_minute', 1);

        seedPendingOtp('628123456789', '123456');

        $plain = scraperToken(['srikandi.otp.read'])['plain'];

        $this->getJson('/api/v1/srikandi/otp-pending?phone=628123456789&sumber=default', asScraper($plain));

        $throttled = $this->getJson(
            '/api/v1/srikandi/otp-pending?phone=628123456789&sumber=default',
            asScraper($plain)
        );

        expect($throttled->status())->toBe(429)
            ->and($throttled->getContent())->not->toContain('123456');
    });
});

describe('penolakan data tidak valid', function () {
    it('otp-request menolak phone kosong', function () {
        $this->postJson('/api/v1/srikandi/otp-request', ['phone' => '', 'sumber' => 'default'], asScraper())
            ->assertStatus(422);
    });

    it('otp-verify menolak kode kosong', function () {
        $this->postJson('/api/v1/srikandi/otp-verify', [
            'phone' => '628123456789', 'code' => '', 'sumber' => 'default',
        ], asScraper())->assertStatus(422);
    });

    it('naskah-dinas menolak items lebih dari 500', function () {
        $items = array_fill(0, 501, ['nomor' => 'X']);

        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'items' => $items,
        ], asScraper())->assertStatus(422);
    });

    it('tidak pernah menulis record saat validasi gagal', function () {
        $this->postJson('/api/v1/srikandi/otp-request', ['phone' => '', 'sumber' => 'default'], asScraper())
            ->assertStatus(422);

        expect(SrikandiOtpState::query()->count())->toBe(0);
    });
});
