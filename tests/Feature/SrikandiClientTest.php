<?php

/*
 | Client Wara milik `bale/srikandi`.
 |
 | Dua hal yang diuji di sini sama-sama "gagal diam-diam" kalau rusak:
 |
 | 1. `sessionForPurpose()` mencari client lewat `defaultServiceClient()`. Kalau
 |    filter `client_id`-nya hilang, dia mengambil route milik client LAIN -
 |    dan selama hanya ada satu client, bug itu tidak terlihat sama sekali.
 |
 | 2. `srikandi:install` harus idempoten. Dua kali install tidak boleh membuat
 |    dua client, karena `defaultServiceClient()` mengembalikan yang pertama dan
 |    route-nya akan terjatuh ke client yang salah.
 */

use Bale\Srikandi\Commands\InstallCommand;
use Bale\Srikandi\Services\OtpService;
use Bale\Wara\Models\WaraClient;
use Bale\Wara\Models\WaraRoute;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

require_once __DIR__.'/../helpers.php';

beforeEach(function () {
    $this->app->register(WaraServiceProvider::class);

    // `srikandiSetup()` mengembalikan Closure yang isinya memakai `$this`
    // — jadi harus dipanggil langsung di dalam `beforeEach`, bukan disimpan
    // lalu dipanggil dari helper lain. Closure yang dijalankan di luar konteks
    // test akan kena "Using $this when not in object context".
    srikandiSetup()->call($this);
});

/**
 * Migration `move_otp_route_to_srikandi_client` yang sudah dipublish.
 *
 * 🔴 Path-nya dihitung dari `database/migrations`, bukan dari folder stub
 * package - dan itu disengaja. Yang diuji adalah file yang BENAR-BENAR dijalankan
 * di aplikasi. Testnya di package, tapi migrasinya tinggal di aplikasi.
 *
 * Kalau stub yang diuji, test bisa hijau sementara file yang jalan di
 * produksi berbeda - persis kelas kegagalan yang tidak terlihat.
 */
function otpRouteMigration(): object
{
    // 🔴 Cari file di DISK, bukan lewat tabel `migrations`.
    //
    // Test memakai sqlite `:memory:`. `RefreshDatabase` menjalankan migrasi
    // yang ada, jadi tabel `migrations` terisi - tapi setiap file migrasi yang
    // sudah ikut di-`migrate` ada di sana sebagai baris, sedangkan file
    // yang HANYA dipublish dan belum dijalankan tidak akan muncul sama sekali.
    //
    // Mengambil nama dari tabel karena itu menghasilkan "File migrasi tidak
    // ditemukan" untuk file yang benar-benar ada di disk. Nama file di sini
    // sudah LENGKAP dengan ekstensi - nilai di `migrations` adalah nama tanpa
    // ekstensi.
    $matches = glob(database_path('migrations/*_move_otp_route_to_srikandi_client.php'));

    if ($matches === []) {
        throw new RuntimeException(
            'Migrasi move_otp_route_to_srikandi_client belum dipublish. '
            .'Jalankan: php artisan srikandi:publish-migration'
        );
    }

    $instance = require $matches[0];

    if (! $instance instanceof Migration) {
        throw new RuntimeException('File tidak mengembalikan objek Migration.');
    }

    return $instance;
}

/**
 * Panggil `sessionForPurpose()` tanpa harus menyusun constructor manual.
 *
 * 🔴 `OtpService` punya dependency yang tidak bisa di-`new` manual
 * (`WaraManager`, `OtpPhone`, dan yang lain). `new OtpService($wara)` gagal
 * dengan `ArgumentCountError` yang tidak menjelaskan apa pun.
 *
 * Dipakai hanya di test. Method-nya `protected` - memanggilnya dari luar kelas
 * melalui closure `Closure::call()` adalah cara yang sah untuk menguji
 * perilaku internal, dan di sini yang diuji memang behavior internal:
 * filter `client_id` yang tidak terlihat dari luar.
 */
function resolveOtpDevice(string $purpose): mixed
{
    return (function () use ($purpose) {
        return $this->sessionForPurpose($purpose);
    })->call(app(OtpService::class));
}

/**
 * Device siap, plus route `purpose` milik client Srikandi.
 */
function seedOtpRouteForClient(string $deviceId, string $purpose = 'otp'): WaraSession
{
    $session = new WaraSession([
        'device_id' => $deviceId,
        'jid' => '628000000001@s.whatsapp.net',
        'display_name' => 'Device OTP',
        'is_present' => true,
        'is_connected' => true,
        'is_logged_in' => true,
        'last_seen_at' => now(),
    ]);

    $session->save();

    $client = WaraClient::query()->firstOrCreate(
        ['name' => InstallCommand::WARA_CLIENT_NAME],
        ['type' => WaraClient::TYPE_SERVICE, 'is_active' => true],
    );

    (new WaraRoute)->forceFill([
        'client_id' => $client->getKey(),
        'purpose' => $purpose,
        'device_id' => $deviceId,
    ])->save();

    return $session;
}

describe('client Wara milik srikandi', function () {
    it('🔴 install membuat client Srikandi (Package) tanpa route', function () {
        $this->artisan('srikandi:install')->assertSuccessful();

        $client = WaraClient::query()
            ->where('name', InstallCommand::WARA_CLIENT_NAME)
            ->first();

        expect($client)->not->toBeNull()
            ->and($client->type)->toBe(WaraClient::TYPE_SERVICE)
            // 🔴 Route KOSONG setelah install, dan itu disengaja: install tidak
            // tahu device mana yang benar. Device yang ditebak berarti pesan
            // keluar dari nomor yang tidak diminta siapa pun.
            ->and($client->routes)->toHaveCount(0)
            ->and($client->api_token_id)->toBeNull()
            ->and($client->bale_id)->toBeNull();
    });

    it('🔴 install dua kali tidak membuat client ganda', function () {
        $this->artisan('srikandi:install')->assertSuccessful();
        $this->artisan('srikandi:install')->assertSuccessful();
        $this->artisan('srikandi:install')->assertSuccessful();

        expect(WaraClient::query()
            ->where('name', InstallCommand::WARA_CLIENT_NAME)
            ->count())->toBe(1);
    });

    it('🔴 install tidak gagal saat wara_clients belum ada', function () {
        // 🔴 Urutan install dua package tidak dijamin. Kalau `bale/wara` belum
        // ter-install, `srikandi:install` harus memberi warning - bukan crash
        // dengan "table not found" yang tidak menjelaskan apa pun.
        Schema::drop('wara_clients');
        Schema::drop('wara_routes');
        Schema::drop('wara_sessions');

        $this->artisan('srikandi:install')
            ->expectsOutputToContain('wara_clients belum ada')
            ->assertSuccessful();
    });

    it('🔴 sessionForPurpose TIDAK mengambil route milik client lain', function () {
        // Dua client punya route dengan purpose sama ke device BERBEDA. Kalau
        // `client_id` tidak difilter, yang diambil adalah yang paling lama -
        // dan OTP akan dikirim dari device milik client yang salah.
        $deviceA = seedOtpRouteForClient('dev-client-a');

        $sessionB = new WaraSession([
            'device_id' => 'dev-client-b',
            'jid' => '628000000002@s.whatsapp.net',
            'display_name' => 'Device Client B',
            'is_present' => true,
            'is_connected' => true,
            'is_logged_in' => true,
            'last_seen_at' => now(),
        ]);
        $sessionB->save();

        $clientB = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE,
            'name' => 'Client Lain',
            'is_active' => true,
        ]);

        (new WaraRoute)->forceFill([
            'client_id' => $clientB->getKey(),
            'purpose' => 'otp',
            'device_id' => 'dev-client-b',
        ])->save();

        $resolved = resolveOtpDevice('otp');

        expect($resolved?->device_id)->toBe($deviceA->device_id)
            ->and($resolved?->device_id)->not->toBe('dev-client-b');
    });

    it('🔴 sessionForPurpose mengembalikan null saat client belum ada', function () {
        // Lebih baik OTP gagal dengan pesan yang menyebut perbaikannya
        // daripada terkirim dari device milik client yang bukan haknya.
        seedOtpRouteForClient('dev-ada');

        WaraClient::query()->delete();

        $resolved = resolveOtpDevice('otp');

        expect($resolved)->toBeNull();
    });

    it('🔴 migration memindahkan route otp dan menghapus client bawaan', function () {
        $legacy = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE,
            'name' => 'Wara (bawaan)',
            'is_active' => true,
        ]);

        (new WaraRoute)->forceFill([
            'client_id' => $legacy->getKey(),
            'purpose' => 'otp',
            'device_id' => 'dev-legacy',
        ])->save();

        otpRouteMigration()->up();

        expect(WaraClient::query()->where('name', 'Wara (bawaan)')->exists())->toBeFalse();

        $target = WaraClient::query()
            ->where('name', InstallCommand::WARA_CLIENT_NAME)
            ->first();

        expect($target)->not->toBeNull()
            ->and(WaraRoute::query()->where('client_id', $target->getKey())->count())->toBe(1)
            ->and(WaraRoute::query()->where('purpose', 'otp')->value('device_id'))->toBe('dev-legacy');
    });

    it('🔴 migration menghapus route notifikasi yang ownershinya sudah hilang', function () {
        $legacy = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE,
            'name' => 'Wara (bawaan)',
            'is_active' => true,
        ]);

        (new WaraRoute)->forceFill([
            'client_id' => $legacy->getKey(),
            'purpose' => 'notifikasi',
            'device_id' => 'wago prod',
        ])->save();

        otpRouteMigration()->up();

        // Device-nya TIDAK ikut terhapus - itu milik GOWA.
        expect(WaraRoute::query()->where('purpose', 'notifikasi')->exists())->toBeFalse()
            ->and(WaraClient::query()->where('name', 'Wara (bawaan)')->exists())->toBeFalse();
    });

    it('🔴 migration BERHENTI kalau ada route lain yang tidak dikenal', function () {
        // Menebak jalan untuk route yang tidak dikenal berarti menghapus
        // keputusan admin tanpa persetujuan. Migrasi harus berhenti dan
        // menyebut purpose-nya.
        $legacy = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE,
            'name' => 'Wara (bawaan)',
            'is_active' => true,
        ]);

        (new WaraRoute)->forceFill([
            'client_id' => $legacy->getKey(),
            'purpose' => 'mystery-purpose',
            'device_id' => 'dev-misterius',
        ])->save();

        expect(fn () => otpRouteMigration()->up())
            ->toThrow(RuntimeException::class, 'mystery-purpose');

        // Client bawaan harus masih ada - migrasi berhenti, bukan menghapus
        // separuh jalan.
        expect(WaraClient::query()->where('name', 'Wara (bawaan)')->exists())->toBeTrue();
    });
});
