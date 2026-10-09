<?php

/*
 | ---------------------------------------------------------------------------
 | Endpoint client Srikandi (S3)
 | ---------------------------------------------------------------------------
 |
 | 🔴 Test di file ini menguji hal yang paling berisiko kalau salah: endpoint
 | yang mengembalikan kredensial plaintext.
 |
 | Yang diuji, dan kenapa:
 |
 | 1. Scope terpisah. `client.read` tidak boleh cukup untuk `credentials`. Kalau
 |    iya, token yang cuma perlu melihat daftar client (untuk tear-down) ikut
 |    bisa mengambil password semua client.
 |
 | 2. Audit setiap pembacaan. Tanpa jejak, "kredensial ini bocor dari mana"
 |    tidak punya jawaban sama sekali.
 |
 | 3. Client nonaktif = 403, bukan 404. Kalau disamarkan, scraper akan
 |    menyimpulkan slug-nya salah lalu mencoba slug lain.
 |
 | 4. `credentials_rotated_at` tidak boleh bisa ditulis scraper.
 |
 */

use Bale\Srikandi\Models\SrikandiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../helpers.php';

uses(RefreshDatabase::class)
    ->beforeEach(srikandiSetup());

function clientRow(string $slug = 'uji-client', array $credentials = [], array $extra = []): SrikandiClient
{
    $client = SrikandiClient::query()->create(array_merge([
        'slug' => $slug,
        'nama' => 'Client '.strtoupper($slug),
    ], $extra));

    if ($credentials !== []) {
        $client->setCredentials($credentials)->save();
    }

    return $client;
}

describe('GET /clients', function () {
    it('menampilkan daftar client tanpa kredensial', function () {
        clientRow('satker-a', ['username' => 'user-a', 'password' => 'pass-a', 'phone' => '628111111111']);
        clientRow('satker-b', ['username' => 'user-b', 'password' => 'pass-b', 'phone' => '628222222222']);

        $response = $this->getJson('/api/v1/srikandi/clients', asScraper());

        $response->assertOk()->assertJsonPath('ok', true);

        $body = $response->json();

        // `srikandiSetup()` sudah membuat client `default` supaya `sumber`
        // pada endpoint OTP lolos `Rule::exists`. Client itu juga nyata di
        // database, jadi dia juga HARUS muncul di daftar -- disappear di sini
        // berarti endpoint menyembunyikan client yang aktif.
        expect($body['clients'])->toHaveCount(3)
            ->and(array_column($body['clients'], 'slug'))->toBe(['default', 'satker-a', 'satker-b']);

        // 🔴 Ini assertion yang paling penting di endpoint ini: nomor tujuan dan
        // kredensial TIDAK BOLEH ikut. Scraper tidak perlu tahu ke mana OTP
        // dikirim, dan memuatnya berarti satu tempat lebih untuk bocor.
        $raw = json_encode($body);

        expect($raw)
            ->not->toContain('pass-a')
            ->not->toContain('user-a')
            ->not->toContain('628111111111')
            ->not->toContain('phone_index');
    });

    it('menandai client yang belum siap untuk scraper', function () {
        // Client aktif tapi kredensialnya kosong akan gagal login dengan 401 lalu
        // sia-sia mencoba captcha. Scraper perlu tahu itu SEBELUM mulai.
        clientRow('siap', ['username' => 'u', 'password' => 'p', 'phone' => '628111111111']);
        clientRow('belum');

        $body = $this->getJson('/api/v1/srikandi/clients', asScraper())->json();

        $bySlug = collect($body['clients'])->keyBy('slug');

        expect($bySlug['siap']['ready'])->toBeTrue()
            ->and($bySlug['belum']['ready'])->toBeFalse();
    });

    it('client nonaktif tetap muncul sebagai ready=false', function () {
        clientRow('mati', ['username' => 'u', 'password' => 'p', 'phone' => '628111111111'], [
            'is_active' => false,
        ]);

        $body = $this->getJson('/api/v1/srikandi/clients', asScraper())->json();

        $row = collect($body['clients'])->firstWhere('slug', 'mati');

        expect($row)->not->toBeNull()
            ->and($row['is_active'])->toBeFalse()
            ->and($row['ready'])->toBeFalse();
    });

    it('menolak token tanpa scope client.read', function () {
        clientRow();

        $this->getJson('/api/v1/srikandi/clients', asScraper(scraperToken(['srikandi.otp.read'])['plain']))
            ->assertForbidden();
    });

    it('menolak tanpa token dengan 401', function () {
        $this->getJson('/api/v1/srikandi/clients')->assertUnauthorized();
    });
});

describe('GET /clients/{slug}/credentials', function () {
    it('mengembalikan kredensial dalam plaintext', function () {
        clientRow('satker-a', [
            'username' => 'user-a',
            'password' => 'PasswordRahasia123',
            'totp_secret' => 'JBSWY3DPEHPK3PXP',
            'gemini_api_key' => 'AIzaSySecretKey',
            'phone' => '628111111111',
        ]);

        $response = $this->getJson(
            '/api/v1/srikandi/clients/satker-a/credentials',
            asScraper(scraperToken(['srikandi.client.credentials'])['plain'])
        );

        $response->assertOk()->assertJsonPath('ok', true);

        $client = $response->json('client');

        expect($client['username'])->toBe('user-a')
            ->and($client['password'])->toBe('PasswordRahasia123')
            ->and($client['totp_secret'])->toBe('JBSWY3DPEHPK3PXP')
            ->and($client['gemini_api_key'])->toBe('AIzaSySecretKey')
            ->and($client['totp_digits'])->toBe(6)
            ->and($client['totp_period'])->toBe(30);
    });

    it('TIDAK mengembalikan nomor tujuan', function () {
        clientRow('satker-a', ['username' => 'u', 'password' => 'p', 'phone' => '628111111111']);

        $raw = $this->getJson(
            '/api/v1/srikandi/clients/satker-a/credentials',
            asScraper(scraperToken(['srikandi.client.credentials'])['plain'])
        )->getContent();

        // Nomor tujuan itu urusan Bale. Mengembalikannya berarti kredensial yang
        // dilepas lebih banyak dari yang scraper butuhkan.
        expect($raw)->not->toContain('628111111111')
            ->and($raw)->not->toContain('phone');
    });

    it('MENOLAK client.read di endpoint kredensial -- ini yang paling penting', function () {
        clientRow('satker-a', ['username' => 'user-a', 'password' => 'PasswordRahasia123']);

        $response = $this->getJson(
            '/api/v1/srikandi/clients/satker-a/credentials',
            asScraper(scraperToken(['srikandi.client.read'])['plain'])
        );

        // 🔴 Kalau test ini berubah jadi `assertOk()`, berarti scope credential
        // sudah tidak lagi dipisah -- dan setiap token yang bisa melihat daftar
        // client jadi bisa mengambil password semua client.
        $response->assertForbidden();

        // Dan pastikan kredensialnya benar-benar tidak ikut di body error.
        expect($response->getContent())->not->toContain('PasswordRahasia123');
    });

    it('MENOLAK token tanpa scope Srikandi apa pun', function () {
        clientRow('satker-a', ['password' => 'PasswordRahasia123']);

        $this->getJson(
            '/api/v1/srikandi/clients/satker-a/credentials',
            asScraper(scraperToken(['api.read'])['plain'])
        )->assertForbidden();
    });

    it('memberi 404 untuk slug yang tidak ada', function () {
        $this->getJson(
            '/api/v1/srikandi/clients/tidak-ada/credentials',
            asScraper(scraperToken(['srikandi.client.credentials'])['plain'])
        )->assertNotFound();
    });

    it('memberi 403 -- bukan 404 -- untuk client yang nonaktif', function () {
        clientRow('mati', ['username' => 'u', 'password' => 'p', 'phone' => '628111111111'], [
            'is_active' => false,
        ]);

        $response = $this->getJson(
            '/api/v1/srikandi/clients/mati/credentials',
            asScraper(scraperToken(['srikandi.client.credentials'])['plain'])
        );

        // 🔴 403, bukan 404. Kalau 404, scraper menyimpulkan slug-nya salah lalu
        // mencoba slug lain -- dan operator melihat tidak ada error sama sekali.
        $response->assertForbidden()
            ->assertJsonPath('code', 'client_inactive');

        expect($response->getContent())->not->toContain('u"')
            ->and($response->getContent())->toContain('nonaktif');
    });

    it('MENCATAT setiap pembacaan kredensial', function () {
        clientRow('satker-a', ['username' => 'user-a', 'password' => 'PasswordRahasia123']);

        $before = Activity::query()
            ->where('log_name', 'srikandi.credentials')
            ->count();

        $plain = scraperToken(['srikandi.client.credentials'])['plain'];

        $this->getJson('/api/v1/srikandi/clients/satker-a/credentials', asScraper($plain))->assertOk();
        $this->getJson('/api/v1/srikandi/clients/satker-a/credentials', asScraper($plain))->assertOk();

        $entries = Activity::query()
            ->where('log_name', 'srikandi.credentials')
            ->get();

        expect($entries)->toHaveCount($before + 2);

        $props = $entries->last()->properties;

        expect($props['slug'])->toBe('satker-a')
            ->and($props['token_id'])->not->toBeNull();
    });

    it('entri audit TIDAK memuat kredensial', function () {
        clientRow('satker-a', [
            'username' => 'user-a',
            'password' => 'PasswordRahasia123',
            'gemini_api_key' => 'AIzaSySecretKey',
        ]);

        $this->getJson(
            '/api/v1/srikandi/clients/satker-a/credentials',
            asScraper(scraperToken(['srikandi.client.credentials'])['plain'])
        )->assertOk();

        $audit = Activity::query()
            ->where('log_name', 'srikandi.credentials')
            ->get();

        // Description maupun properties tidak boleh memuat kredensial. Kalau
        // audit entri ini dibuat lewat trait LogsActivity, nilai kolom yang
        // berubah ikut masuk dan kredensial plaintext-nya ikut tercatat.
        foreach ($audit as $entry) {
            $teks = $entry->description.' '.$entry->properties->toJson();

            expect($teks)
                ->not->toContain('PasswordRahasia123')
                ->not->toContain('AIzaSySecretKey')
                ->not->toContain('user-a');
        }
    });
});

describe('POST /clients/{slug}/heartbeat', function () {
    it('mencatat waktu login dan mengembalikan needs_login', function () {
        clientRow('satker-a', ['username' => 'u', 'password' => 'p', 'phone' => '628111111111']);

        $response = $this->postJson('/api/v1/srikandi/clients/satker-a/heartbeat', [
            'login_at' => now()->toIso8601String(),
        ], asScraper(scraperToken(['srikandi.naskah.write'])['plain']));

        $response->assertOk()->assertJsonPath('ok', true);

        // Rotasi kredensial terjadi sebelum heartbeat, jadi Bale sudah punya
        // kredensial yang belum pernah dicoba.
        expect($response->json('needs_login'))->toBeFalse();

        $client = SrikandiClient::query()->where('slug', 'satker-a')->firstOrFail();

        expect($client->last_login_at)->not->toBeNull();
    });

    it('needs_login=true saat rotasi lebih baru dari login terakhir', function () {
        $client = clientRow('satker-a', ['username' => 'u', 'password' => 'p', 'phone' => '628111111111']);

        // Login dulu, THEN putar kredensial.
        $client->forceFill(['last_login_at' => now()->subHour()])->save();

        $this->postJson('/api/v1/srikandi/clients/satker-a/heartbeat', [], asScraper())
            ->assertOk()
            ->assertJsonPath('needs_login', true);
    });

    it('TIDAK mengizinkan scraper menulis credentials_rotated_at', function () {
        $client = clientRow('satker-a', ['username' => 'u', 'password' => 'p', 'phone' => '628111111111']);
        $rotasiAwal = $client->credentials_rotated_at;

        $response = $this->postJson('/api/v1/srikandi/clients/satker-a/heartbeat', [
            'credentials_rotated_at' => now()->toIso8601String(),
        ], asScraper());

        // Field-nya tidak dideklarasikan, jadi Laravel mengabaikannya diam-diam.
        // Ini yang kita mau: scraper tidak boleh/report kapan kredensial berubah.
        // Kalau scraper boleh menulisnya, satu siklus dengan urutan salah bisa
        // membuat Bale menampilkan banner "kredensial diganti" padahal tidak ada
        // yang diganti -- dan scraper sendiri terus diberi tahu untuk login ulang.
        expect($response->status())->not->toBe(422)
            ->and($response->status())->toBe(200);

        $fresh = SrikandiClient::query()->findOrFail($client->id);

        expect($fresh->credentials_rotated_at->timestamp)->toBe($rotasiAwal->timestamp);
    });

    it('mencatat error scraper', function () {
        clientRow('satker-a', ['username' => 'u', 'password' => 'p', 'phone' => '628111111111']);

        $this->postJson('/api/v1/srikandi/clients/satker-a/heartbeat', [
            'error_at' => now()->toIso8601String(),
            'error_message' => 'Login gagal: password salah.',
        ], asScraper())->assertOk();

        $client = SrikandiClient::query()->where('slug', 'satker-a')->firstOrFail();

        expect($client->last_error_at)->not->toBeNull()
            ->and($client->last_error_message)->toBe('Login gagal: password salah.');
    });

    it('hanya mengisi field yang dikirim, tidak mereset yang lain', function () {
        $client = clientRow('satker-a', ['username' => 'u', 'password' => 'p', 'phone' => '628111111111']);

        $client->forceFill([
            'last_login_at' => now()->subDay(),
            'last_error_at' => now()->subDay(),
            'last_error_message' => 'Error lama.',
        ])->save();

        // Hanya `naskah_at` yang dikirim. `array_filter` tanpa nilai null
        // pastikan `last_login_at` dan `last_error_at` TIDAK ikut di-reset jadi
        // null -- kalau iya, satu heartbeat yang isiannya tidak lengkap akan
        // menghapus jejak error sebelumnya yang justru paling dibutuhkan.
        $this->postJson('/api/v1/srikandi/clients/satker-a/heartbeat', [
            'naskah_at' => now()->toIso8601String(),
        ], asScraper())->assertOk();

        $fresh = SrikandiClient::query()->findOrFail($client->id);

        expect($fresh->last_naskah_at)->not->toBeNull()
            ->and($fresh->last_login_at)->not->toBeNull()
            ->and($fresh->last_error_at)->not->toBeNull()
            ->and($fresh->last_error_message)->toBe('Error lama.');
    });

    it('memberi 404 untuk slug yang tidak ada', function () {
        $this->postJson('/api/v1/srikandi/clients/tidak-ada/heartbeat', [], asScraper())
            ->assertNotFound();
    });

    it('memberi 403 untuk client nonaktif', function () {
        clientRow('mati', ['username' => 'u', 'password' => 'p', 'phone' => '628111111111'], [
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/srikandi/clients/mati/heartbeat', [], asScraper())
            ->assertForbidden()
            ->assertJsonPath('code', 'client_inactive');
    });

    it('menolak token tanpa scope naskah.write', function () {
        clientRow('satker-a', ['username' => 'u', 'password' => 'p', 'phone' => '628111111111']);

        $this->postJson(
            '/api/v1/srikandi/clients/satker-a/heartbeat',
            [],
            asScraper(scraperToken(['srikandi.client.read'])['plain'])
        )->assertForbidden();
    });
});
