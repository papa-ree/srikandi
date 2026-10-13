<?php

/*
 | ---------------------------------------------------------------------------
 | Halaman Naskah Keluar Srikandi (landlord)
 | ---------------------------------------------------------------------------
 |
 | Halaman read-only untuk cache Naskah Keluar. Test di sini mengunci hal yang
 | tidak kelihatan dari model:
 |
 |   - permission TERPISAH `srikandi.naskah.keluar.read` (bukan `.read` Naskah
 |     Masuk) benar-benar menjaga route
 |   - opsi filter dibangun dari data (`distinct`), termasuk tahun `0`
 |   - snapshot dari luar sistem di-render ter-escape (bukan Stored XSS)
 |   - berkas ditandai lewat `file_path`, bukan `status_berkas`
 |
 | 🔴 Snapshot bisa berisi `<script>` karena datang dari sistem luar. Satu raw
 | echo di view akan menjadikannya XSS di halaman Bale, dan gejalanya baru
 | terlihat kalau sumbernya memang mengirim HTML.
 |
 */

use App\Models\User;
use Bale\Srikandi\Livewire\Pages\Landlord\NaskahKeluar\Detail as NaskahKeluarDetail;
use Bale\Srikandi\Livewire\Pages\Landlord\NaskahKeluar\Section\NaskahKeluarHeader;
use Bale\Srikandi\Livewire\Pages\Landlord\NaskahKeluar\Section\NaskahKeluarTable;
use Bale\Srikandi\Models\SrikandiNaskahKeluar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

require_once __DIR__.'/../helpers.php';

beforeEach(function () {
    srikandiSetup()->call($this);

    Permission::firstOrCreate(['name' => 'srikandi.naskah.keluar.read', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'srikandi.naskah.read', 'guard_name' => 'web']);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('srikandi.naskah.keluar.read');

    $this->actingAs($this->user);
    Livewire::actingAs($this->user);
});

/**
 * Satu baris Naskah Keluar.
 */
function naskahKeluar(array $overrides = []): SrikandiNaskahKeluar
{
    return SrikandiNaskahKeluar::create(array_merge([
        'sumber' => 'default',
        'nomor_naskah' => 'X/1/2026',
        'tahun' => 2026,
        'tanggal_naskah' => '2026-08-05',
        'hal' => 'Undangan rapat',
        'tujuan_naskah' => 'Dinas Arsip',
        'status_tindak_lanjut' => 'KIRIM',
        'row_hash' => 'md5:abc',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));
}

describe('Naskah Keluar — akses', function () {
    it('menolak user tanpa permission srikandi.naskah.keluar.read', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/srikandi/naskah-keluar')
            ->assertForbidden();
    });

    it('TIDAK cukup permission srikandi.naskah.read untuk membuka naskah keluar', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('srikandi.naskah.read');

        $this->actingAs($user)
            ->get('/srikandi/naskah-keluar')
            ->assertForbidden();
    });

    it('terdaftar sebagai route bernama srikandi.naskah-keluar.index', function () {
        expect(route('srikandi.naskah-keluar.index', absolute: false))->toBe('/srikandi/naskah-keluar');
    });

    it('merender halaman untuk user berizin', function () {
        $this->withoutVite()
            ->get('/srikandi/naskah-keluar')
            ->assertOk()
            ->assertSee('Naskah Keluar Srikandi');
    });
});

describe('Naskah Keluar — daftar', function () {
    it('menampilkan baris naskah keluar', function () {
        naskahKeluar(['nomor_naskah' => 'X/9/2026', 'tujuan_naskah' => 'Dinas Komunikasi']);

        Livewire::test(NaskahKeluarTable::class)
            ->assertSee('X/9/2026')
            ->assertSee('Dinas Komunikasi');
    });

    it('membangun opsi tahun dari data, termasuk tahun 0', function () {
        naskahKeluar(['nomor_naskah' => 'A/1', 'tahun' => 2026, 'tanggal_naskah' => '2026-08-01']);
        naskahKeluar(['nomor_naskah' => 'A/2', 'tahun' => 0, 'tanggal_naskah' => null]);

        Livewire::test(NaskahKeluarTable::class)
            ->assertViewHas('tahunFilter', fn (array $options): bool => array_key_exists(0, $options)
                && array_key_exists(2026, $options));
    });

    it('membangun opsi status tindak lanjut dari distinct, bukan konstanta', function () {
        naskahKeluar(['nomor_naskah' => 'A/1', 'status_tindak_lanjut' => 'KIRIM']);
        naskahKeluar(['nomor_naskah' => 'A/2', 'status_tindak_lanjut' => 'BELUM']);
        naskahKeluar(['nomor_naskah' => 'A/3', 'status_tindak_lanjut' => 'STATUS_BARU_X']);

        Livewire::test(NaskahKeluarTable::class)
            ->assertViewHas('statusFilter', fn (array $options): bool => array_key_exists('KIRIM', $options)
                && array_key_exists('BELUM', $options)
                && array_key_exists('STATUS_BARU_X', $options));
    });
});

describe('Naskah Keluar — header', function () {
    it('menghitung total, segar, basi, dan baris berberkas (via file_path)', function () {
        naskahKeluar(['nomor_naskah' => 'A/1', 'file_path' => 'naskah-keluar/org/2026/a.pdf', 'last_seen_at' => now()]);
        naskahKeluar(['nomor_naskah' => 'A/2', 'file_path' => null, 'last_seen_at' => now()]);
        naskahKeluar(['nomor_naskah' => 'A/3', 'file_path' => null, 'last_seen_at' => now()->subDays(3)]);

        Livewire::test(NaskahKeluarHeader::class)
            ->assertViewHas('total', 3)
            ->assertViewHas('fresh', 2)
            ->assertViewHas('stale', 1)
            ->assertViewHas('withBerkas', 1);
    });
});

describe('Naskah Keluar — detail', function () {
    it('merender detail naskah keluar', function () {
        $record = naskahKeluar(['nomor_naskah' => 'X/77/2026', 'tujuan_naskah' => 'Bagian Umum']);

        $this->withoutVite()
            ->get('/srikandi/naskah-keluar/'.$record->getKey())
            ->assertOk()
            ->assertSee('X/77/2026')
            ->assertSee('Bagian Umum');
    });

    it('menjawab 404 untuk id yang tidak ada', function () {
        $this->withoutVite()
            ->get('/srikandi/naskah-keluar/'.Str::uuid())
            ->assertNotFound();
    });

    it('me-render snapshot ter-escape, BUKAN sebagai HTML', function () {
        $record = naskahKeluar([
            'nomor_naskah' => 'X/88/2026',
            'snapshot' => ['nomor' => 'X/88/2026', 'raw' => '<script>alert(1)</script>'],
        ]);

        $component = Livewire::test(NaskahKeluarDetail::class, ['naskahKeluar' => $record]);

        // Teks asli tetap terbaca (ter-escape), tapi tag mentahnya tidak pernah
        // ada di HTML.
        expect($component->html())->not->toContain('<script>alert(1)</script>');
        expect($component->html())->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
    });
});
