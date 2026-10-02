<?php

use App\Models\User;
use Bale\Srikandi\SrikandiServiceProvider;
use Bale\Wara\Livewire\Pages\Landlord\Status\Index;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(WaraServiceProvider::class);
    $this->app->register(SrikandiServiceProvider::class);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/**
 * Beri permission ke user yang sedang login.
 */
function grantStatus(string ...$permissions): User
{
    $role = Role::findOrCreate('status-reader', 'web');

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    test()->user->assignRole($role);

    return test()->user;
}

/**
 * Daftar URL item menu untuk satu group di `src/menu.php` package.
 *
 * `dirname(__DIR__, 3)` dari `tests/Feature` naik sampai ke `packages/`.
 */
function menuUrls(string $package, string $group): Collection
{
    $file = dirname(__DIR__, 3).DIRECTORY_SEPARATOR
        .$package.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'menu.php';

    $menu = require $file;

    $groups = collect($menu['groups'] ?? []);
    $items = $groups->firstWhere('key', $group)['items'] ?? [];

    return collect($items)->pluck('url');
}

describe('render halaman status bale/wara', function () {
    it('merender komponen checklist tanpa error', function () {
        grantStatus('wara.status.read');

        Livewire::test(Index::class)
            ->assertOk()
            ->assertSee('Status bale/wara');
    });

    it('menampilkan progres dan ringkasan jumlah', function () {
        grantStatus('wara.status.read');

        Livewire::test(Index::class)
            ->assertOk()
            ->assertSee('dari')
            ->assertSee('selesai')
            ->assertSee('direncanakan');
    });

    it('menampilkan label fase di halaman', function () {
        grantStatus('wara.status.read');

        Livewire::test(Index::class)
            ->assertOk()
            ->assertSee('Fase 0 — Rekonsiliasi repo & dokumen')
            ->assertSee('Fase 10 — Event inbound');
    });

    it('menampilkan catatan item yang masih direncanakan', function () {
        grantStatus('wara.status.read');

        Livewire::test(Index::class)
            ->assertOk()
            ->assertSee('Runbook lockout');
    });

    it('menolak user tanpa permission lewat HTTP', function () {
        $this->get('/wara/status')->assertForbidden();
    });
});

describe('render halaman status bale/srikandi', function () {
    it('merender komponen checklist tanpa error', function () {
        grantStatus('srikandi.status.read');

        Livewire::test(Bale\Srikandi\Livewire\Pages\Landlord\Status\Index::class)
            ->assertOk()
            ->assertSee('Status bale/srikandi');
    });

    it('menampilkan pekerjaan lanjutan yang jujur', function () {
        grantStatus('srikandi.status.read');

        Livewire::test(Bale\Srikandi\Livewire\Pages\Landlord\Status\Index::class)
            ->assertOk()
            ->assertSee('Endpoint `verified` → `consumed`');
    });

    it('menampilkan daftar penyimpangan dari spesifikasi', function () {
        grantStatus('srikandi.status.read');

        Livewire::test(Bale\Srikandi\Livewire\Pages\Landlord\Status\Index::class)
            ->assertOk()
            ->assertSee('bale/api')
            ->assertSee('code_hash');
    });

    it('menolak user tanpa permission lewat HTTP', function () {
        $this->get('/srikandi/status')->assertForbidden();
    });
});

describe('halaman status lewat HTTP', function () {
    it('wara/status menolak tamu', function () {
        auth()->logout();

        $this->get('/wara/status')->assertRedirect();
    });

    it('srikandi/status menolak tamu', function () {
        auth()->logout();

        $this->get('/srikandi/status')->assertRedirect();
    });

    it('menu sidebar memuat kedua halaman status', function () {
        expect(menuUrls('srikandi', 'srikandi'))->toContain('srikandi/status')
            ->and(menuUrls('wara', 'wara'))->toContain('wara/status');
    });
});
