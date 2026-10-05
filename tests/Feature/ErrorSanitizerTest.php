<?php

/*
 | ---------------------------------------------------------------------------
 | Sanitasi pesan error dari scraper
 | ---------------------------------------------------------------------------
 |
 | 🔴 Test ini menguji kebocoran yang nyata: `last_error_message` adalah kolom
 | `text` yang TIDAK terenkripsi dan dibaca dari halaman UI. Pesan error Playwright
 | bisa memuat URL dengan kredensial di query string, jadi tanpa sanitasi satu
 | siklus yang gagal akan menuliskan password Srikandi ke database.
 |
 */

use Bale\Srikandi\Models\SrikandiClient;
use Bale\Srikandi\Support\ErrorSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../helpers.php';

uses(RefreshDatabase::class)
    ->beforeEach(srikandiSetup());

function sanitizerClient(): SrikandiClient
{
    $client = SrikandiClient::query()->create([
        'slug' => 'default',
        'nama' => 'Client Uji',
    ]);

    $client->setCredentials([
        'username' => 'u',
        'password' => 'p',
        'phone' => '628123456789',
    ])->save();

    return $client;
}

describe('ErrorSanitizer', function () {
    it('menyensor password di query string URL', function () {
        // Bentuk yang paling sering muncul di error Playwright.
        $pesan = 'net::ERR_ABORTED at https://srikandi.go.id/login'
            .'?user=admin&password=Rahasia123&next=/home';

        $hasil = (new ErrorSanitizer)->sanitize($pesan);

        expect($hasil)->not->toContain('Rahasia123')
            ->and($hasil)->toContain('password=[REDACTED]')
            ->and($hasil)->toContain('user=admin')
            ->and($hasil)->toContain('next=/home');
    });

    it('menyensor password di JSON', function () {
        // Bentuk yang lebih sering dari `password=`. Tanpa pola `:` di regex,
        // bentuk ini justru lolos.
        $pesan = '{"user":"admin","password":"RahasiaJSON123"}';

        $hasil = (new ErrorSanitizer)->sanitize($pesan);

        expect($hasil)->not->toContain('RahasiaJSON123')
            ->and($hasil)->toContain('"user":"admin"')
            ->and($hasil)->toContain('password');
    });

    it('menyensor token Bearer di header', function () {
        $pesan = 'Request failed: Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.abc';

        $hasil = (new ErrorSanitizer)->sanitize($pesan);

        expect($hasil)->not->toContain('eyJhbGciOiJIUzI1NiJ9.abc')
            ->and($hasil)->toContain('Bearer [REDACTED]');
    });

    it('menyensor berbagai nama field kredensial', function () {
        foreach (['password', 'passwd', 'pwd', 'secret', 'token', 'api_key', 'apiKey', 'apikey'] as $field) {
            $hasil = (new ErrorSanitizer)->sanitize("Gagal: {$field}=NILAI-RAHASIA");

            expect($hasil)->not->toContain('NILAI-RAHASIA', "gagal untuk field: {$field}");
        }
    });

    it('MEMBUKTIKAN penyensoran adalah best-effort, bukan jaminan', function () {
        // 🔴 Test pengakuan, bukan test yang berharap JWT-nya tertangkap.
        //
        // Kredensial yang tidak berbentuk `nama=nilai` atau `nama: nilai` TIDAK
        // akan tertangkap. Ini tidak bisa diperbaiki dengan regex — harus dengan
        // aturan di sisi scraper.
        //
        // Test ini ada supaya tidak ada yang nanti mengubah komentar "sanitasi
        // adalah lapisan kedua" jadi "sanitasi melindungi kredensial" dan
        // lalu menghapus aturan di sisi scraper sebagai "sudah di-sanitasi".
        $base64 = base64_encode('user=admin&password=RahasiaBase64');

        $hasil = (new ErrorSanitizer)->sanitize("Payload: {$base64}");

        expect($hasil)->toContain(base64_encode('user=admin&password=RahasiaBase64'));
    });

    it('TIDAK menyensor teks error biasa', function () {
        // 🔴 Sanitasi yang terlalu agresif membuat pesan error tidak berguna.
        // Kalau "Password salah" ikut berubah, operator kehilangan kemampuan
        // membaca apa yang sebenarnya terjadi.
        foreach ([
            'Password salah',
            'Login gagal: username tidak terdaftar',
            'Captcha tidak terbaca, coba lagi',
            'Timeout 30000ms exceeded',
        ] as $pesan) {
            expect((new ErrorSanitizer)->sanitize($pesan))->toBe($pesan);
        }
    });

    it('mempertahankan tanda kutip agar JSON tetap valid', function () {
        $hasil = (new ErrorSanitizer)->sanitize('{"password":"Rahasia123","user":"admin"}');

        // Kalau tanda kutip ikut hilang, JSON-nya rusak dan siapa pun yang
        // mem-parse log itu akan dapat hasil yang salah.
        expect($hasil)->toBeString()
            ->and(json_decode($hasil))->not->toBeNull()
            ->and(json_decode($hasil)->user)->toBe('admin')
            ->and(json_decode($hasil)->password)->toBe('[REDACTED]');
    });

    it('memotong pesan yang terlalu panjang dan menandainya', function () {
        $panjang = str_repeat('a', 5000);

        $hasil = (new ErrorSanitizer)->sanitizeAndTruncate($panjang, 100);

        expect(mb_strlen($hasil))->toBeLessThanOrEqual(100)
            // Tanpa penanda, operator bisa salah baca bagian yang tersisa
            // sebagai pesan lengkap.
            ->and($hasil)->toContain('[dipotong]');
    });

    it('mempertahankan pesan pendek apa adanya', function () {
        $hasil = (new ErrorSanitizer)->sanitizeAndTruncate('Gagal login', 2000);

        expect($hasil)->toBe('Gagal login');
    });

    it('menangani null dan string kosong', function () {
        $s = new ErrorSanitizer;

        expect($s->sanitize(null))->toBeNull()
            ->and($s->sanitize(''))->toBe('')
            ->and($s->sanitize('   '))->toBe('   ');
    });
});

describe('sanitasi di heartbeat', function () {
    it('TIDAK menyimpan kredensial yang ada di pesan error', function () {
        $client = sanitizerClient();

        $this->postJson('/api/v1/srikandi/clients/default/heartbeat', [
            'error_at' => now()->toIso8601String(),
            'error_message' => 'Gagal: https://srikandi.go.id/login?password=RahasiaDariScraper123',
        ], asScraper())->assertOk();

        $tersimpan = SrikandiClient::query()->findOrFail($client->id)->last_error_message;

        // 🔴 Ini yang diuji: kolom `text` yang tidak terenkripsi tidak boleh
        // memuat password client.
        expect($tersimpan)->not->toContain('RahasiaDariScraper123')
            ->and($tersimpan)->toContain('[REDACTED]')
            // Bagian yang bukan kredensial harus tetap terbaca, kalau tidak
            // operator tidak tahu error apa yang terjadi.
            ->and($tersimpan)->toContain('srikandi.go.id/login');
    });

    it('TIDAK mengirim pesan error mentah di response heartbeat', function () {
        sanitizerClient();

        $response = $this->postJson('/api/v1/srikandi/clients/default/heartbeat', [
            'error_at' => now()->toIso8601String(),
            'error_message' => 'Gagal: ?token=RahasiaToken123',
        ], asScraper());

        expect($response->assertOk()->getContent())->not->toContain('RahasiaToken123');
    });
});
