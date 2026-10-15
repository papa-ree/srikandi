<?php

/*
 | ---------------------------------------------------------------------------
 | Halaman Log Sesi Srikandi (landlord)
 | ---------------------------------------------------------------------------
 |
 | Halaman read-only untuk snapshot diagnostik per sesi scraper. Test di sini
 | mengunci hal yang tidak kelihatan dari model:
 |
 |   - permission TERPISAH `srikandi.sesi.read` (bukan `.read` naskah) benar-
 |     benar menjaga route
 |   - filter `sumber` dibangun dari data, `status` tetap success/failed
 |   - header memakai definisi "gagal" yang sama dengan badge baris
 |   - `snapshots` dari luar sistem di-render ter-escape (bukan Stored XSS)
 |
 | 🔴 `snapshots` bisa berisi `<script>` karena datang dari sistem luar. Satu
 | raw echo di view akan menjadikannya XSS di halaman Bale, dan gejalanya baru
 | terlihat kalau sumbernya memang mengirim HTML.
 |
 */

use App\Models\User;
use Bale\Srikandi\Livewire\Pages\Landlord\Sesi\Detail as SesiDetail;
use Bale\Srikandi\Livewire\Pages\Landlord\Sesi\Section\SesiHeader;
use Bale\Srikandi\Livewire\Pages\Landlord\Sesi\Section\SesiTable;
use Bale\Srikandi\Models\SrikandiSessionSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

require_once __DIR__.'/../helpers.php';

beforeEach(function () {
    srikandiSetup()->call($this);

    Permission::firstOrCreate(['name' => 'srikandi.sesi.read', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'srikandi.naskah.read', 'guard_name' => 'web']);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('srikandi.sesi.read');

    $this->actingAs($this->user);
    Livewire::actingAs($this->user);
});

/**
 * Satu baris sesi scraper.
 */
function sesiSnapshot(array $overrides = []): SrikandiSessionSnapshot
{
    return SrikandiSessionSnapshot::create(array_merge([
        'sumber' => 'default',
        'started_at' => '2026-10-14 10:00:00',
        'finished_at' => '2026-10-14 10:12:30',
        'status' => 'success',
        'error_code' => null,
        'login_at' => '2026-10-14 10:00:05',
        'naskah_at' => '2026-10-14 10:11:00',
        'snapshots' => [
            ['jenis' => 'naskah masuk', 'fetched' => 10, 'baru' => 3, 'cursorExhausted' => true],
        ],
    ], $overrides));
}

describe('Log Sesi — akses', function () {
    it('menolak user tanpa permission srikandi.sesi.read', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/srikandi/sesi')
            ->assertForbidden();
    });

    it('TIDAK cukup permission srikandi.naskah.read untuk membuka log sesi', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('srikandi.naskah.read');

        $this->actingAs($user)
            ->get('/srikandi/sesi')
            ->assertForbidden();
    });

    it('terdaftar sebagai route bernama srikandi.sesi.index', function () {
        expect(route('srikandi.sesi.index', absolute: false))->toBe('/srikandi/sesi');
    });

    it('merender halaman untuk user berizin', function () {
        $this->withoutVite()
            ->get('/srikandi/sesi')
            ->assertOk()
            ->assertSee('Log Sesi Srikandi');
    });
});

describe('Log Sesi — daftar', function () {
    it('menampilkan baris sesi', function () {
        sesiSnapshot(['sumber' => 'satker-b', 'started_at' => '2026-10-14 09:00:00', 'finished_at' => '2026-10-14 09:05:00']);

        Livewire::test(SesiTable::class)
            ->assertSee('satker-b');
    });

    it('membangun opsi filter sumber dari data', function () {
        sesiSnapshot(['sumber' => 'default']);
        sesiSnapshot(['sumber' => 'satker-b', 'started_at' => '2026-10-14 11:00:00', 'finished_at' => '2026-10-14 11:05:00']);

        Livewire::test(SesiTable::class)
            ->assertViewHas('sumberFilter', fn (array $options): bool => array_key_exists('default', $options)
                && array_key_exists('satker-b', $options));
    });
});

describe('Log Sesi — header', function () {
    it('menghitung total, sukses, gagal, dan sesi terakhir', function () {
        sesiSnapshot(['status' => 'success', 'started_at' => '2026-10-14 08:00:00', 'finished_at' => '2026-10-14 08:05:00']);
        sesiSnapshot(['status' => 'success', 'started_at' => '2026-10-14 09:00:00', 'finished_at' => '2026-10-14 09:05:00']);
        sesiSnapshot([
            'status' => 'failed',
            'error_code' => 'LOGIN_FAILED',
            'started_at' => '2026-10-14 10:00:00',
            'finished_at' => '2026-10-14 10:01:00',
        ]);

        $component = Livewire::test(SesiHeader::class)
            ->assertViewHas('total', 3)
            ->assertViewHas('success', 2)
            ->assertViewHas('failed', 1);

        expect($component->viewData('last')->finished_at->format('Y-m-d H:i:s'))
            ->toBe('2026-10-14 10:01:00');
    });
});

describe('Log Sesi — detail', function () {
    it('merender detail sesi', function () {
        $record = sesiSnapshot(['sumber' => 'satker-c']);

        $this->withoutVite()
            ->get('/srikandi/sesi/'.$record->getKey())
            ->assertOk()
            ->assertSee('satker-c');
    });

    it('menjawab 404 untuk id yang tidak ada', function () {
        $this->withoutVite()
            ->get('/srikandi/sesi/'.Str::uuid())
            ->assertNotFound();
    });

    it('me-render snapshot ter-escape, BUKAN sebagai HTML', function () {
        $record = sesiSnapshot([
            'snapshots' => [
                ['jenis' => 'naskah masuk', 'raw' => '<script>alert(1)</script>'],
            ],
        ]);

        $component = Livewire::test(SesiDetail::class, ['snapshot' => $record]);

        expect($component->html())->not->toContain('<script>alert(1)</script>');
        expect($component->html())->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
    });
});
