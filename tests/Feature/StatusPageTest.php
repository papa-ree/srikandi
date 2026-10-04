<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../helpers.php';

uses(RefreshDatabase::class)
    ->beforeEach(srikandiSetup());
/**
 * Muat `src/status.php` dari package manapun.
 *
 * Dipakai sebagai fungsi supaya path-nya hanya benar di satu tempat. Menulis
 * `__DIR__.'/../../../src/status.php'` di setiap test terlihat benar tapi
 * sebenarnya sudah satu tingkat terlalu tinggi — dan errornya ("Failed to open
 * stream") tidak pernah menyiratkan bahwa path-nya yang salah.
 */
function statusOf(string $package): array
{
    return require dirname(__DIR__, 3).DIRECTORY_SEPARATOR.$package.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'status.php';
}

/**
 * Semua item dari semua bagian.
 */
function statusItems(array $status): Collection
{
    return collect($status['sections'])->flatMap(fn ($section) => $section['items']);
}

/**
 * User yang boleh melihat kedua halaman status.
 */
function statusUser(array $permissions = ['wara.status.read', 'srikandi.status.read']): User
{
    $user = User::factory()->create();

    $role = Role::findOrCreate('status-reader', 'web');

    foreach ($permissions as $permission) {
        $role->givePermissionTo(
            Permission::findOrCreate($permission, 'web')
        );
    }

    $user->assignRole($role);

    return $user;
}

describe('halaman status bale/wara', function () {
    it('terdaftar di route wara.status.index', function () {
        expect(route('wara.status.index'))->toContain('wara/status');
    });

    it('menampilkan daftar fase yang sudah selesai', function () {
        $labels = statusItems(statusOf('wara'))->pluck('label');

        expect($labels)->toContain('Fase 0 — Rekonsiliasi repo & dokumen')
            ->toContain('Fase 9 — `WaraChannel` notifikasi Laravel')
            ->toContain('Fase 10 — Event inbound `WaraIncomingMessage`');
    });

    it('menampilkan item yang masih direncanakan', function () {
        expect(statusItems(statusOf('wara'))->where('status', 'open'))->not->toBeEmpty();
    });

    it('semua fase 0-13 berstatus done', function () {
        $fases = statusItems(statusOf('wara'))
            ->filter(fn ($item) => str_starts_with($item['label'], 'Fase '));

        expect($fases)->toHaveCount(14)
            ->and($fases->pluck('status')->unique()->all())->toBe(['done']);
    });

    it('🔴 mencatat client bawaan sebagai SUDAH TIDAK ADA', function () {
        // 🔴 Assertion ini menjaga halaman status tidak lagi-usang diam-diam.
        //
        // `Wara (bawaan)` dihapus 4 Okt 2026 dan digantikan client per package.
        // Kalau status.php masih menyebutnya, halaman terlihat bisa dipercaya
        // untuk memutuskan "apakah ini sudah selesai".
        // untuk memutuskan "apakah ini sudah selesai".
        $text = json_encode(statusOf('wara'), JSON_UNESCAPED_UNICODE);

        expect($text)->not->toContain('Wara (bawaan)');
    });

    it('🔴 mencatat breaking change scraper yang belum diselesaikan', function () {
        // Payload `POST /naskah-dinas` tidak lagi punya kolom organisasi, tapi
        // `rak-srikandi` belum menyesuaikan. Ini harus terlihat di halaman
        // status, bukan baruketahuan saat scraper gagal di lapangan.
        $items = statusItems(statusOf('wara'));

        $scraper = $items->firstWhere(
            fn ($item) => str_contains($item['label'], 'rak-srikandi')
        );

        expect($scraper)->not->toBeNull()
            ->and($scraper['status'])->toBe('open')
            ->and($scraper['meta'] ?? null)->toBe('breaking change');
    });

    it('tidak ada status yang tidak dikenal', function () {
        foreach (['wara', 'srikandi'] as $package) {
            $unknown = statusItems(statusOf($package))->pluck('status')
                ->reject(fn ($s) => in_array($s, ['done', 'open', 'blocked'], true))
                ->unique();

            expect($unknown->all())->toBe([]);
        }
    });
});

describe('halaman status bale/srikandi', function () {
    it('terdaftar di route srikandi.status.index', function () {
        expect(route('srikandi.status.index'))->toContain('srikandi/status');
    });

    it('menampilkan DoD sebagai selesai', function () {
        $labels = statusItems(statusOf('srikandi'))->pluck('label');

        expect($labels)->toContain('Kerangka package + 2 migration (digabung)')
            ->toContain('Listener balasan masuk dari `WaraIncomingMessage`');
    });

    it('mencatat pekerjaan lanjutan yang jujur', function () {
        $open = statusItems(statusOf('srikandi'))
            ->whereIn('status', ['open', 'blocked'])
            ->pluck('label');

        expect($open)->toContain('Endpoint `verified` → `consumed`')
            ->toContain('Integrasi scraper `rak-srikandi`');
    });
});

describe('struktur data status', function () {
    it('kedua package punya struktur yang sama', function () {
        expect(array_keys(statusOf('wara')))->toBe(array_keys(statusOf('srikandi')));
    });

    it('memuat judul, subjudul, dan tanggal pembaruan', function () {
        foreach (['wara', 'srikandi'] as $package) {
            $status = statusOf($package);

            expect($status['title'] ?? null)->not->toBeNull()
                ->and($status['subtitle'] ?? null)->not->toBeNull()
                ->and($status['updated_at'] ?? null)->not->toBeNull();
        }
    });

    it('setiap bagian punya judul dan minimal satu item', function () {
        foreach (['wara', 'srikandi'] as $package) {
            foreach (statusOf($package)['sections'] as $section) {
                expect($section['title'] ?? null)->not->toBeNull()
                    ->and($section['items'] ?? [])->not->toBeEmpty();
            }
        }
    });
});
