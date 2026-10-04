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
        $text = json_encode(statusOf('wara'), JSON_UNESCAPED_UNICODE);

        expect($text)->not->toContain('Wara (bawaan)');
    });

    it('🔴 mencatat breaking change scraper yang belum diselesaikan', function () {
        // Payload `POST /naskah-dinas` tidak lagi punya kolom organisasi, tapi
        // `rak-srikandi` belum menyesuaikan. Ini harus terlihat di halaman
        // status, bukan baru diketahui saat scraper gagal di lapangan.
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

/*
 * 🔴 Klaim halaman status tentang kode WARA sendiri harus bisa dibuktikan.
 *
 * Empat item di bawah semuanya arose dari pola yang sama: `status.php` meng klaim
 * sesuatu belum selesai (atau sudah) sementara kodenya sudah selesai (atau belum),
 * dan tidak ada yang mengetahuinya karena perbedaan itu tidak terlihat dari mana
 * pun selain dengan membuka filenya. Halaman status justru dipakai untuk
 * memutuskan "apakah ini sudah selesai" — jadi klaim yang basi di sana membuat
 * keputusan yang salah terlihat beralasan.
 *
 * Test mengunci HASIL yang diverifikasi terhadap kode, bukan string status.php
 * itu sendiri: kalau nanti device alert di-refactor dan note-nya ditulis ulang,
 * test ini harus tetap hijau selama perilakunya memang sudah ada.
 */
describe('klaim status wara cocok dengan kodenya', function () {
    it('🔴 peringatan status device tercatat selesai karena memang ada', function () {
        // Fakta: `device-header.blade.php` merender amber "Device perlu dipasang
        // ulang" dan abu "Device sedang tidak siap"; `DeviceAlertTest.php`
        // menutup 7 kasus termasuk render. Item ini sempat `open` dan kembali
        // diubah ke `open` lagi tanpa cek kalau kodenya masih ada.
        $item = statusItems(statusOf('wara'))
            ->firstWhere(fn ($i) => str_contains($i['label'], 'Peringatan status device'));

        expect($item)->not->toBeNull()
            ->and($item['status'])->toBe('done');

        // Lapisan kedua: pastikan alert-nya benar-benar ter-render, supaya
        // "done" tidak cuma berdasarkan note yang ditulis ulang.
        $view = file_get_contents(dirname(__DIR__, 3)
            .'/wara/resources/views/livewire/pages/landlord/device/section/device-header.blade.php');

        expect($view)->toContain('Device perlu dipasang ulang')
            ->and($view)->toContain('Device sedang tidak siap');
    });

    it('🔴 item status tidak mengklaim punya endpoint milik package lain', function () {
        // `OtpService` + endpoint `otp-*` milik `packages/srikandi`, bukan wara.
        // Halaman status Wara boleh membawa berita keputusannya, tapi tidak
        // boleh mengklaim endpoint itu punya wara.
        //
        // 🔴 Catatan: jangan pakai `json_encode()` untuk ini. Tanpa
        // `JSON_UNESCAPED_SLASHES` PHP mengubah `bale/srikandi` jadi
        // `bale\/srikandi`, jadi `toContain('bale/srikandi')` gagal padahal
        // teksnya benar. Digabung dari nilai array supaya tidak melewati
        // encoding apa pun.
        $text = collect(statusItems(statusOf('wara')))
            ->flatMap(fn ($item) => [$item['label'] ?? '', $item['note'] ?? ''])
            ->implode(' ');

        expect($text)->toContain('bale/srikandi')
            ->and($text)->not->toContain('Wara (bawaan)');
    });

    it('🔴 catatan install jujur tentang tidak adanya client bawaan', function () {
        // InstallCommand hanya men-seed 7 permission. Kalau halaman status tidak
        // menyebut itu, admin akan menginstal lalu mengira gateway siap dipakai.
        $labels = statusItems(statusOf('wara'))->pluck('label');

        expect($labels->implode(' '))->toContain('wara:install');
    });

    it('🔴 tidak ada teks korup di note mana pun', function () {
        // Blok teks korup yang ditemukan 4 Okt 2026 lolos `garbled-scan.php`
        // karena skrip itu hanya mendeteksi CJK. Kata "Challenger" adalah sisa
        // lompatan dari dokumen lain; "Modules problem" adalah potongan kalimat.
        $patterns = ['Challenger', 'Modules problem', 'akan_push', 'regretted', 'byasa'];

        foreach (['wara', 'srikandi'] as $package) {
            $text = json_encode(statusOf($package), JSON_UNESCAPED_UNICODE);

            foreach ($patterns as $pattern) {
                expect($text)->not->toContain($pattern);
            }
        }
    });

    it('🔴 teks korup tidak ada di file status pun, bukan cuma isinya', function () {
        // Karena note dirender dengan `{!! !!}`, teks apa pun yang lolos ke sini
        // jadi HTML. Guard di atas hanya membaca array hasil `require`; yang ini
        // membaca filenya, jadi termasuk bagian yang tidak lagi terpakai.
        //
        // 🔴 Dan ini juga satu-satunya alasan file `status.php` ikut diperiksa:
        // kalau nanti key `doc` dibuang atau komponen `x-core::checklist`
        // berubah bentuk datanya, blok ini tetap berlaku karena tidak bergantung
        // pada struktur array sama sekali.
        foreach (['wara', 'srikandi'] as $package) {
            $raw = file_get_contents(dirname(__DIR__, 3)
                .DIRECTORY_SEPARATOR.$package.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'status.php');

            foreach (['Challenger', 'Modules problem', 'akan_push', 'regretted', 'byasa'] as $pattern) {
                expect($raw)->not->toContain($pattern);
            }
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
