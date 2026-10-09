<?php

/*
 | ---------------------------------------------------------------------------
 | Proteksi kredensial pada `SrikandiClient`
 | ---------------------------------------------------------------------------
 |
 | 🔴 Test di file ini menjaga terhadap kebocoran yang NYATA dan yang
 | sudah terjadi di kode, bukan kemungkinan teoritis.
 |
 | 1. `Bale\Core\Traits\LogsActivity` memakai `logAll()`. Spatie v5 di
 |    `resolveAttributeValue()` memanggil `$model->getAttribute($attr)`, yang
 |    MENJALANKAN cast `encrypted`. Tanpa override, setiap rotasi password
 |    menulis password TEKS POLOS ke `activity_log.properties` -- di tabel
 |    terpisah yang tidak terenkripsi dan dibaca lebih sering dari aslinya.
 |
 | 2. Kolom terenkripsi tidak bisa dicari dengan `where()` biasa. Kalau ada yang
 |    menulis `where('phone', '628123')`, query-nya jalan tanpa error dan
 |    selalu mengembalikan kosong. Itu kegagalan yang paling sulit dilihat.
 |
 | 3. `phone_index` harus ikut ditulis setiap kali `phone` berubah. Kalau tidak,
 |    pencarian by-nomor mengembalikan client yang salah -- bukan client yang
 |    tidak ada.
 |
 */

use Bale\Srikandi\Models\SrikandiClient;
use Bale\Srikandi\Support\BlindIndex;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

require_once __DIR__.'/../helpers.php';

beforeEach(function () {
    $this->app->register(WaraServiceProvider::class);
    srikandiSetup()->call($this);

    // 🔴 `SRIKANDI_INDEX_KEY` wajib diisi. `BlindIndex` sengaja tidak fallback
    // ke `APP_KEY`, jadi tanpa ini semua test yang menyentuh `phone` akan
    // melempar RuntimeException -- dan itu memang perilaku yang benar, tapi
    // di sini kita isi supaya test menguji hal lain.
    config()->set('srikandi.index_key', 'kunci-index-untuk-test-yang-berbeda-dari-app-key');

    runClientsMigration();
});

/**
 * Jalankan stub `create_srikandi_clients_table` terhadap sqlite in-memory.
 *
 * 🔴 Stub `.php.stub` tidak pernah di-migrate otomatis (konvensi repo: stub
 * dipublish lewat `srikandi:publish-migration`). Kalau test ikut menguji stub,
 * yang teruji bisa jadi bukan file yang benar-benar jalan di aplikasi.
 *
 * Tapi untuk tabel BARU ini belum ada file yang dipublish, jadi stub adalah
 * satu-satunya sumber kebenaran -- dan menolak mengujinya berarti tabel ini
 * bisa salah bentuk tanpa ada yang tahu. Test ini ikut berlaku begitu
 * migration dipublish: begitu file-nya ada di `database/migrations`, helper ini
 * otomatis memakai file itu, bukan stub.
 */
/**
 * Gabungkan seluruh `attributes` yang tercatat di `activity_log` untuk satu model.
 *
 * 🔴 MENGAPA BUKAN `properties`?
 *
 * Nilai kolom yang berubah disimpan di kolom `attributes`. Kolom `properties`
 * hanya berisi metadata yang ditambahkan `beforeActivityLogged()` (tenant,
 * logged_by, ip_address, user_agent) -- isinya tidak pernah memuat nilai kolom.
 *
 * Menguji `properties` akan selalu hijau, termasuk di kondisi yang paling
 * perlu diwaspadai: kredensial bocor ke log. Test ini sempat salah di sini dan
 * hijau selama `logOnly()` masih belum diuji -- sekarang kolom yang dibaca
 * memang kolom yang menyimpan datanya.
 */
function activityAttributes(string $subjectType): string
{
    return Activity::query()
        ->where('subject_type', $subjectType)
        ->get()
        // 🔴 Baca `attribute_changes` dari DATA MENTAH, bukan cast `$attributes`.
        //
        // Model `Activity` di repo ini tidak punya cast `attributes => collection`,
        // jadi `$activity->attributes` NULL -- dan karena `attributes` adalah
        // nama accessor Eloquent, itu juga nama accessor yang membingungkan.
        // Getter `$activity->attributes` mengembalikan cast, yang di sini null.
        //
        // Nilai kolom yang berubah disimpan sebagai JSON mentah dengan bentuk:
        //   {"attributes": { ... nilai baru ... }, "old": { ... nilai lama ... }}
        // `old` sengaja ikut dimasukkan: kalau ada kebocoran, nilai LAMA bisa
        // ikut bocor dan nilai baru masih terlihat bersih.
        ->map(fn (Activity $activity) => (string) $activity->getRawOriginal('attribute_changes'))
        ->filter()
        ->implode(' ');
}

function runClientsMigration(): void
{
    // 🔴 IDEMPOTEN, dan itu jadi wajib begitu migrasinya dipublish.
    //
    // Stub `.php.stub` tidak pernah di-migrate otomatis (konvensi repo: stub
    // dipublish lewat `srikandi:publish-migration`), jadi test lama memanggil
    // `up()` sendiri supaya tabel ada walau file-nya belum dipublish.
    //
    // Sekarang file-nya sudah ada di `database/migrations`, jadi
    // `RefreshDatabase` ikut menjalankannya -- dan pemanggilan kedua gagal
    // dengan "table already exists". Gejalanya 19 test gagal dengan pesan
    // yang sama sekali tidak menyinggung migrasi.
    if (Schema::hasTable('srikandi_clients')) {
        return;
    }

    $published = glob(database_path('migrations/*_create_srikandi_clients_table.php'));

    $source = $published !== [] ? $published[0] : __DIR__.'/../../database/migrations/create_srikandi_clients_table.php.stub';

    // 🔴 `require`, bukan `include`: anonymous class di dalam file migrasi akan
    // didaftarkan ulang kalau file-nya dimuat dua kali. Test dengan
    // `RefreshDatabase` menjalankan migrasi per test, jadi ini dipanggil
    // banyak kali dalam satu proses.
    $migration = require $source;

    $migration->up();
}

describe('enkripsi kolom kredensial', function () {
    it('menyimpan kredensial sebagai ciphertext, bukan teks polos', function () {
        $client = SrikandiClient::query()->create([
            'slug' => 'uji-kredensial',
            'nama' => 'Client Uji',
        ]);

        $client->setCredentials([
            'username' => 'user-srikandi',
            'password' => 'PasswordRahasia123',
            'totp_secret' => 'JBSWY3DPEHPK3PXP',
            'gemini_api_key' => 'AIzaSySecretKey',
            'phone' => '628123456789',
        ])->save();

        // Yang dibaca lewat getAttributes() adalah nilai yang BENAR-BENAR ada
        // di kolom -- yaitu ciphertext.
        $raw = $client->getAttributes();

        expect($raw['password'])->not->toBe('PasswordRahasia123')
            ->and($raw['username'])->not->toBe('user-srikandi')
            ->and($raw['totp_secret'])->not->toBe('JBSWY3DPEHPK3PXP')
            ->and($raw['gemini_api_key'])->not->toBe('AIzaSySecretKey')
            ->and($raw['phone'])->not->toBe('628123456789');

        // ...samba kredensialnya TIDAK hilang: model tetap bisa membacanya.
        $fresh = SrikandiClient::query()->findOrFail($client->id);

        expect($fresh->password)->toBe('PasswordRahasia123')
            ->and($fresh->username)->toBe('user-srikandi')
            ->and($fresh->phone)->toBe('628123456789');
    });

    it('TIDAK menyertakan kredensial di toArray()', function () {
        $client = SrikandiClient::query()->create(['slug' => 'uji-kredensial', 'nama' => 'Uji']);
        $client->setCredentials([
            'username' => 'user-srikandi',
            'password' => 'PasswordRahasia123',
            'phone' => '628123456789',
        ])->save();

        $array = $client->toArray();

        expect(array_keys($array))
            ->toContain('slug', 'nama')
            ->not->toContain('username')
            ->not->toContain('password')
            ->not->toContain('phone')
            ->not->toContain('phone_index');
    });
});

describe('proteksi activity log', function () {
    it('TIDAK menulis kredensial ke activity_log saat client disimpan', function () {
        $client = SrikandiClient::query()->create([
            'slug' => 'uji-kredensial',
            'nama' => 'Client Uji',
        ]);

        $client->setCredentials([
            'username' => 'user-srikandi',
            'password' => 'PasswordRahasia123',
            'gemini_api_key' => 'AIzaSySecretKey',
            'phone' => '628123456789',
        ])->save();

        // 🔴 Yang diuji kolom ttributes, BUKAN properties.
        //
        // properties cuma metadata yang ditambahkan eforeActivityLogged()
        // (tenant, logged_by, ip, user_agent). Nilai kolom yang berubah
        // disimpan di kolom terpisah, ttributes. Menguji properties akan
        // selalu hijau -- termasuk kalau kredensialnya bocor.
        $log = activityAttributes(SrikandiClient::class);

        expect($log)->not->toBe('');

        // Ini assertion yang paling penting di file ini.
        expect($log)
            ->not->toContain('PasswordRahasia123')
            ->not->toContain('user-srikandi')
            ->not->toContain('AIzaSySecretKey')
            ->not->toContain('628123456789');
    });

    it('TIDAK menulis kredensial meski nilainya SAMA dengan nilai sebelumnya', function () {
        $client = SrikandiClient::query()->create(['slug' => 'uji-kredensial', 'nama' => 'Uji']);
        $client->setCredentials(['password' => 'PasswordRahasia123'])->save();

        $client->setCredentials(['password' => 'PasswordRahasia123'])->save();

        $allLogs = activityAttributes(SrikandiClient::class);

        expect($allLogs)->not->toContain('PasswordRahasia123');
    });

    it('MENCATAT rotasi kredensial sebagai timestamp, tanpa nilai kredensialnya', function () {
        $client = SrikandiClient::query()->create(['slug' => 'uji-kredensial', 'nama' => 'Uji']);
        $client->setCredentials(['password' => 'PasswordLama'])->save();

        $client->setCredentials(['password' => 'PasswordBaru'])->save();

        $entries = Activity::query()
            ->where('subject_type', SrikandiClient::class)
            ->get();

        $latest = activityAttributes(SrikandiClient::class);

        // 🔴 Rotasi kredensial BOLEH dan SEHARUSNYA menghasilkan entri log.
        //
        // Yang tercatat adalah KAPAN kredensial berubah. Itu informasi yang
        // berguna: tanpa itu, tidak ada cara menjawab "kenapa scraper tiba-tiba
        // tidak bisa login" selain menebak.
        //
        // Yang tidak boleh masuk entri itu adalah nilai kredensialnya -- dan
        // itulah yang dijamin assertion di sini.
        expect($entries)->not->toBeEmpty()
            ->and($latest)->toContain('credentials_rotated_at')
            ->and($latest)->not->toContain('PasswordBaru')
            ->and($latest)->not->toContain('PasswordLama');
    });

    it('TETAP mencatat perubahan yang memang berguna', function () {
        $client = SrikandiClient::query()->create(['slug' => 'uji-kredensial', 'nama' => 'Uji']);

        $client->update(['nama' => 'Nama Baru']);

        $log = activityAttributes(SrikandiClient::class);

        // `logOnly()` bukan berarti mematikan logging. Kalau sampai sini kita
        // mematikan semua, proteksi kebocoran berubah jadi tidak ada jejak sama
        // sekali -- dan penyimpangan antara "nama client" dan nama di UI tidak
        // bisa ditelusuri.
        expect($log)->toContain('nama');
    });
});

describe('blind index untuk pencarian nomor', function () {
    it('wherePhone() menemukan client berdasarkan nomor ternormalisasi', function () {
        $client = SrikandiClient::query()->create(['slug' => 'uji-kredensial', 'nama' => 'Uji']);
        $client->setCredentials(['phone' => '628123456789'])->save();

        // Ketiga bentuk ini harus menemukan client yang sama.
        foreach (['628123456789', '08123456789', '+62 812-3456-789'] as $variant) {
            expect(SrikandiClient::query()->wherePhone($variant)->count())
                ->toBe(1, "gagal untuk bentuk: {$variant}");
        }
    });

    it("where('phone', ...) biasa selalu kosong -- dan itu bukan bug", function () {
        $client = SrikandiClient::query()->create(['slug' => 'uji-kredensial', 'nama' => 'Uji']);
        $client->setCredentials(['phone' => '628123456789'])->save();

        // 🔴 Test ini mengunci KEBALIKAN dari asumsi yang salah.
        //
        // `phone` terenkripsi, jadi `where('phone', '628123456789')` tidak akan
        // pernah cocok -- query-nya tidak error, hanya selalu kosong. Kalau
        // suatu saat ada yang menulis ini di production, hasilnya "client tidak
        // ketemu" dengan HTTP 404, dan tidak ada yang mengira penyebabnya
        // enkripsi.
        $viaPlainWhere = SrikandiClient::query()
            ->where('phone', '628123456789')
            ->count();

        $viaIndex = SrikandiClient::query()
            ->wherePhone('628123456789')
            ->count();

        expect($viaPlainWhere)->toBe(0)
            ->and($viaIndex)->toBe(1);
    });

    it('phone_index ikut diperbarui setiap kali nomor diganti', function () {
        $client = SrikandiClient::query()->create(['slug' => 'uji-kredensial', 'nama' => 'Uji']);
        $client->setCredentials(['phone' => '628123456789'])->save();

        $indexAwal = SrikandiClient::query()->findOrFail($client->id)->phone_index;

        expect($indexAwal)->not->toBeNull()
            ->and($indexAwal)->not->toContain('628123456789');

        $client->setCredentials(['phone' => '628999999999'])->save();

        $indexAkhir = SrikandiClient::query()->findOrFail($client->id)->phone_index;

        // Nomor lama TIDAK boleh masih ketemu -- kalau iya, Bale mengirim OTP
        // ke nomor yang sudah tidak dipakai client itu.
        expect($indexAkhir)->not->toBe($indexAwal)
            ->and(SrikandiClient::query()->wherePhone('628123456789')->count())->toBe(0)
            ->and(SrikandiClient::query()->wherePhone('628999999999')->count())->toBe(1);
    });

    it('TIDAK memberi index untuk kolom kosong', function () {
        $client = SrikandiClient::query()->create(['slug' => 'uji-kredensial', 'nama' => 'Uji']);

        // Phone kosong harusnya tidak punya index. Kalau index-nya dihitung
        // untuk string kosong, semua client tanpa nomor akan saling dianggap
        // nomor yang sama.
        expect($client->phone_index)->toBeNull();

        $client->setCredentials(['phone' => '628123456789'])->save();
        $client->setCredentials(['phone' => null])->save();

        expect(SrikandiClient::query()->findOrFail($client->id)->phone_index)->toBeNull();
    });
});

describe('rotasi kredensial', function () {
    it('menandai credentials_rotated_at saat kredensial benar-benar berubah', function () {
        $client = SrikandiClient::query()->create(['slug' => 'uji-kredensial', 'nama' => 'Uji']);

        expect($client->credentials_rotated_at)->toBeNull();

        $client->setCredentials(['password' => 'PasswordBaru'])->save();

        expect(SrikandiClient::query()->findOrFail($client->id)->credentials_rotated_at)
            ->not->toBeNull();
    });

    it('TIDAK menandai rotasi saat form edit mengirim password kosong', function () {
        $client = SrikandiClient::query()->create(['slug' => 'uji-kredensial', 'nama' => 'Uji']);
        $client->setCredentials(['password' => 'PasswordAsli'])->save();

        $rotasiAwal = SrikandiClient::query()->findOrFail($client->id)->credentials_rotated_at;

        // 🔴 Form edit browser TIDAK PERNAH mengirim nilai lama untuk field
        // password -- field itu placeholder, bukan value. Kalau string kosong
        // dianggap "berubah", setiap penyimpanan form akan:
        //   1. mengosongkan password client, dan
        //   2. memberi tahu scraper kredensialnya baru.
        // Dua-duanya merusak, dan yang kedua lebih sulit ketahuan karena
        // scraper cuma terlihat "sering login ulang".
        $rotasiSebelum = (string) $rotasiAwal?->format('Y-m-d H:i:s');

        $client->setCredentials(['password' => '', 'nama' => 'Uji'])->save();

        $fresh = SrikandiClient::query()->findOrFail($client->id);

        expect($fresh->password)->toBe('PasswordAsli')
            ->and((string) $fresh->credentials_rotated_at?->format('Y-m-d H:i:s'))
            ->toBe($rotasiSebelum);
    });

    it('TIDAK bisa diisi lewat mass-assignment', function () {
        $client = SrikandiClient::query()->create([
            'slug' => 'uji-kredensial',
            'nama' => 'Uji',
            // Kolom kredensial sengaja tidak ada di $fillable, jadi ketiganya di sini
            // harus diabaikan diam-diam.
            'password' => 'PasswordLangsung',
            'username' => 'user-langsung',
        ]);

        expect($client->password)->toBeNull()
            ->and($client->username)->toBeNull();
    });
});

describe('kesiapan client untuk scraper', function () {
    it('butuh username, password, DAN nomor tujuan', function () {
        $client = SrikandiClient::query()->create(['slug' => 'uji-kredensial', 'nama' => 'Uji']);

        // Belum ada apa-apa.
        expect($client->isReadyForScraper())->toBeFalse();

        $client->setCredentials(['username' => 'user'])->save();
        expect($client->isReadyForScraper())->toBeFalse();

        $client->setCredentials(['password' => 'pass'])->save();
        expect($client->isReadyForScraper())->toBeFalse();

        $client->setCredentials(['phone' => '628123456789'])->save();
        expect($client->isReadyForScraper())->toBeTrue();
    });

    it('client nonaktif tidak pernah siap walau kredensialnya lengkap', function () {
        $client = SrikandiClient::query()->create(['slug' => 'uji-kredensial', 'nama' => 'Uji']);
        $client->setCredentials([
            'username' => 'user',
            'password' => 'pass',
            'phone' => '628123456789',
        ])->save();

        expect($client->isReadyForScraper())->toBeTrue();

        $client->update(['is_active' => false]);

        expect(SrikandiClient::query()->findOrFail($client->id)->isReadyForScraper())->toBeFalse();
    });
});

describe('BlindIndex', function () {
    it('menolak jalan tanpa SRIKANDI_INDEX_KEY, dan tidak diam-diam pakai APP_KEY', function () {
        config()->set('srikandi.index_key', null);

        // 🔴 Ini yang membuat pemisahan kunci berarti. Kalau di sini fallback
        // ke APP_KEY, maka siapa pun yang punya kunci enkripsi juga bisa
        // menghitung index -- dan dump `srikandi_clients` jadi cukup untuk
        // mencoba mendekripsi kolomnya.
        expect(fn () => app(BlindIndex::class)->make('628123456789'))
            ->toThrow(RuntimeException::class, 'SRIKANDI_INDEX_KEY');
    });

    it('menghasilkan index yang sama untuk nomor dengan format berbeda', function () {
        $index = new BlindIndex;

        $a = $index->make('628123456789');
        $b = $index->make('08123456789');
        $c = $index->make('+62 812-3456-789');

        expect($a)->toBe($b)->toBe($c)
            ->and($a)->toHaveLength(64);
    });

    it('menghasilkan index berbeda untuk nomor berbeda', function () {
        $index = new BlindIndex;

        expect($index->make('628123456789'))
            ->not->toBe($index->make('628999999999'));
    });

    it('matches() membandingkan dengan constant-time', function () {
        $index = new BlindIndex;

        $stored = $index->make('628123456789');

        expect($index->matches('628123456789', $stored))->toBeTrue()
            ->and($index->matches('08123456789', $stored))->toBeTrue()
            ->and($index->matches('628999999999', $stored))->toBeFalse()
            ->and($index->matches('628123456789', null))->toBeFalse();
    });
});
