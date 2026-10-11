<?php

use Bale\Srikandi\Models\SrikandiClient;
use Bale\Srikandi\Notifications\NaskahBaruDiterima;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../helpers.php';

uses(RefreshDatabase::class)
    ->beforeEach(function () {
        srikandiSetup();

        // Pastikan client default ada
        SrikandiClient::query()->firstOrCreate(
            ['slug' => 'default'],
            ['nama' => 'Client Default'],
        );
    });

/**
 * Seed client dengan konfigurasi notifikasi.
 */
function srikandiNotifyClient(array $overrides = []): SrikandiClient
{
    $client = SrikandiClient::query()->where('slug', 'default')->firstOrFail();

    $client->forceFill(array_merge([
        'notify_enabled' => true,
        'notify_via' => 'whatsapp',
        'notify_phone' => '+628000000001',
        'notify_baseline_at' => null,
        'notify_pending_count' => 0,
    ], $overrides))->save();

    return $client->refresh();
}

/**
 * Item naskah untuk ingest (disederhanakan dari NaskahIngestTest).
 */
function naskahItemNotify(array $overrides = []): array
{
    return array_merge([
        'nomor' => '800/UA.2026/001',
        'tanggal' => '2026-10-01',
        'hal' => 'Undangan',
        'ringkasan' => 'Undangan rapat',
        'nama_pengirim' => 'Sekretariat',
        'jabatan_pengirim' => 'Kabid',
        'status_baca' => 'BELUM',
        'status_tindak' => 'TINDAK LANJUT',
        'status_berkas' => null,
        'hash_value' => 'a1'.md5(uniqid('', true)),
    ], $overrides);
}

/**
 * Kirim satu batch `naskah-dinas` (satu POST, satu bagian siklus).
 *
 * @param  array<int, array<string, mixed>>  $items
 */
function kirimNaskah(array $items, string $slug = 'default')
{
    return test()->postJson('/api/v1/srikandi/naskah-dinas', [
        'sumber' => $slug,
        'items' => $items,
    ], asScraper())->assertOk();
}

/**
 * Akhiri satu siklus dengan heartbeat (batas agregasi, KONTRAK §3.4/§8).
 */
function akhiriSiklus(string $slug = 'default')
{
    return test()->postJson("/api/v1/srikandi/clients/{$slug}/heartbeat", [], asScraper())->assertOk();
}

describe('Notifikasi naskah baru (S5.2)', function () {
    it('mengakumulasi + mencatat baseline pada siklus pertama, flush diredam', function () {
        Notification::fake();

        $client = srikandiNotifyClient();
        expect($client->notify_baseline_at)->toBeNull();

        kirimNaskah([naskahItemNotify(), naskahItemNotify(['nomor' => '800/UA.2026/002'])])
            ->assertJsonPath('inserted', 2);

        // Fase 1: terakumulasi, baseline dicatat, TIDAK ada kirim.
        $client->refresh();
        expect($client->notify_baseline_at)->not->toBeNull()
            ->and($client->notify_pending_count)->toBe(2);
        Notification::assertNothingSent();

        // Fase 2 (flush): diredam, tapi counter tetap di-reset.
        akhiriSiklus();
        $client->refresh();
        expect($client->notify_pending_count)->toBe(0);
        Notification::assertNothingSent();
    });

    it('mengirim satu notifikasi pada siklus kedua setelah grace', function () {
        Notification::fake();
        Config::set('srikandi.notify.baseline_grace_minutes', 15);

        $client = srikandiNotifyClient();

        // Siklus 1: harvest pertama -> teredam.
        kirimNaskah([naskahItemNotify()])->assertJsonPath('inserted', 1);
        akhiriSiklus();
        Notification::assertNothingSent();

        $this->travel(16)->minutes();

        // Siklus 2: satu naskah baru.
        kirimNaskah([
            naskahItemNotify(),
            naskahItemNotify(['nomor' => '800/UA.2026/003']),
        ])->assertJsonPath('inserted', 1);
        akhiriSiklus();

        Notification::assertSentTo(
            Notification::route('wara', '+628000000001'),
            NaskahBaruDiterima::class,
            function ($notification) {
                return $notification->jumlah === 1;
            }
        );
    });

    it('menggabungkan banyak POST dalam satu siklus menjadi SATU notifikasi', function () {
        Notification::fake();
        Config::set('srikandi.notify.baseline_grace_minutes', 0);

        $client = srikandiNotifyClient(['notify_baseline_at' => now()->subHour()]);

        // Satu harvest dipecah 3 POST (batas 500 item).
        kirimNaskah([
            naskahItemNotify(['nomor' => '800/UA.2026/201']),
            naskahItemNotify(['nomor' => '800/UA.2026/202']),
        ])->assertJsonPath('inserted', 2);

        kirimNaskah([
            naskahItemNotify(['nomor' => '800/UA.2026/203']),
            naskahItemNotify(['nomor' => '800/UA.2026/204']),
            naskahItemNotify(['nomor' => '800/UA.2026/205']),
        ])->assertJsonPath('inserted', 3);

        kirimNaskah([
            naskahItemNotify(['nomor' => '800/UA.2026/206']),
            naskahItemNotify(['nomor' => '800/UA.2026/207']),
            naskahItemNotify(['nomor' => '800/UA.2026/208']),
            naskahItemNotify(['nomor' => '800/UA.2026/209']),
            naskahItemNotify(['nomor' => '800/UA.2026/210']),
        ])->assertJsonPath('inserted', 5);

        // Belum ada kirim selama siklus berjalan.
        Notification::assertNothingSent();
        expect($client->refresh()->notify_pending_count)->toBe(10);

        // Flush: satu pesan, jumlah = 10.
        akhiriSiklus();
        Notification::assertSentTimes(NaskahBaruDiterima::class, 1);
        Notification::assertSentTo(
            Notification::route('wara', '+628000000001'),
            NaskahBaruDiterima::class,
            function ($notification) {
                return $notification->jumlah === 10
                    && str_contains($notification->toWara(null), '10 naskah baru belum dibaca');
            }
        );
    });

    it('me-reset counter supaya siklus berikutnya tidak dobel', function () {
        Notification::fake();
        Config::set('srikandi.notify.baseline_grace_minutes', 0);

        $client = srikandiNotifyClient(['notify_baseline_at' => now()->subHour()]);

        // Siklus 1: 2 naskah baru -> 1 pesan jumlah 2.
        kirimNaskah([
            naskahItemNotify(['nomor' => '800/UA.2026/301']),
            naskahItemNotify(['nomor' => '800/UA.2026/302']),
        ]);
        akhiriSiklus();

        Notification::assertSentTo(
            Notification::route('wara', '+628000000001'),
            NaskahBaruDiterima::class,
            fn ($notification) => $notification->jumlah === 2
        );

        // Reset fake supaya assertion siklus 2 bersih.
        Notification::fake();

        // Siklus 2: hanya 1 naskah baru (yang lama dikirim ulang = unchanged).
        kirimNaskah([
            naskahItemNotify(['nomor' => '800/UA.2026/301']),
            naskahItemNotify(['nomor' => '800/UA.2026/303']),
        ])->assertJsonPath('inserted', 1);
        akhiriSiklus();

        Notification::assertSentTo(
            Notification::route('wara', '+628000000001'),
            NaskahBaruDiterima::class,
            fn ($notification) => $notification->jumlah === 1
        );
    });

    it('TIDAK mengirim kalau inserted <= 0', function () {
        Notification::fake();
        Config::set('srikandi.notify.baseline_grace_minutes', 0);

        srikandiNotifyClient(['notify_baseline_at' => now()->subHour()]);

        $item = naskahItemNotify();

        // Siklus 1: 1 naskah baru -> kirim.
        kirimNaskah([$item])->assertJsonPath('inserted', 1);
        akhiriSiklus();
        Notification::assertSentTimes(NaskahBaruDiterima::class, 1);

        Notification::fake();

        // Siklus 2: naskah sama -> unchanged, tidak ada kirim.
        kirimNaskah([$item])
            ->assertJsonPath('inserted', 0)
            ->assertJsonPath('unchanged', 1);
        akhiriSiklus();

        Notification::assertNothingSent();
    });

    it('TIDAK mengirim kalau notify_enabled false', function () {
        Notification::fake();
        Config::set('srikandi.notify.baseline_grace_minutes', 0);

        srikandiNotifyClient([
            'notify_enabled' => false,
            'notify_baseline_at' => now()->subHour(),
        ]);

        kirimNaskah([
            naskahItemNotify(['nomor' => '800/UA.2026/401']),
            naskahItemNotify(['nomor' => '800/UA.2026/402']),
        ])->assertJsonPath('inserted', 2);
        akhiriSiklus();

        Notification::assertNothingSent();
    });

    it('TIDAK mengirim kalau notify_via bukan whatsapp', function () {
        Notification::fake();
        Config::set('srikandi.notify.baseline_grace_minutes', 0);

        srikandiNotifyClient([
            'notify_via' => 'none',
            'notify_baseline_at' => now()->subHour(),
        ]);

        kirimNaskah([
            naskahItemNotify(['nomor' => '800/UA.2026/501']),
            naskahItemNotify(['nomor' => '800/UA.2026/502']),
        ])->assertJsonPath('inserted', 2);
        akhiriSiklus();

        Notification::assertNothingSent();
    });

    it('Fallback ke phone kalau notify_phone kosong', function () {
        Notification::fake();
        Config::set('srikandi.notify.baseline_grace_minutes', 0);

        srikandiNotifyClient([
            'notify_phone' => null,
            'phone' => '+628123456789',
            'notify_baseline_at' => now()->subHour(),
        ]);

        kirimNaskah([naskahItemNotify()])->assertJsonPath('inserted', 1);
        akhiriSiklus();

        Notification::assertSentTo(
            Notification::route('wara', '+628123456789'),
            NaskahBaruDiterima::class
        );
    });

    it('heartbeat tanpa naskah tertunda tetap sukses dan tidak mengirim', function () {
        Notification::fake();
        Config::set('srikandi.notify.baseline_grace_minutes', 0);

        srikandiNotifyClient(['notify_baseline_at' => now()->subHour()]);

        akhiriSiklus();

        Notification::assertNothingSent();
    });
});
