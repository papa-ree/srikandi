<?php

/*
 | ---------------------------------------------------------------------------
 | Endpoint snapshot per sesi scraper (rak-srikandi PLAN 2026-10-14)
 | ---------------------------------------------------------------------------
 |
 | 🔴 Yang diuji, dan kenapa:
 |
 | 1. Idempotensi. Scraper menelan error kiriman snapshot (non-fatal), jadi
 |    siklus berikutnya bisa mengirim sesi yang SAMA. Tanpa unique key +
 |    updateOrCreate, laporan diagnostik terduplikasi dan masalah yang mau
 |    didiagnosis terlihat dua kali lebih besar.
 |
 | 2. Pemilik sesi dari PATH, bukan body. Berbeda dari `naskah-dinas`, snapshot
 |    tidak mengirim `sumber`; slug di URL yang menentukan. Salah ambil berarti
 |    laporan satu client tersimpan atas nama client lain.
 |
 | 3. TIDAK menyentuh tabel naskah. Snapshot itu diagnostik baca-saja; kalau
 |    dia ikut menulis naskah, "panen kosong" justru bisa hilang karena ada
 |    yang mengisinya dari jalur yang salah.
 |
 | 4. Scope & status client sama dengan heartbeat/credentials: token tanpa
 |    `srikandi.naskah.write` => 403, slug tak ada => 404, client nonaktif => 403.
 |
 */

use Bale\Srikandi\Models\SrikandiClient;
use Bale\Srikandi\Models\SrikandiNaskah;
use Bale\Srikandi\Models\SrikandiNaskahKeluar;
use Bale\Srikandi\Models\SrikandiSessionSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

require_once __DIR__.'/../helpers.php';

uses(RefreshDatabase::class)->beforeEach(srikandiSetup());

/**
 * Payload satu sesi, mengikuti `SessionSnapshot` di `client-bale.ts`
 * (waktu Asia/Jakarta +07:00).
 */
function snapshotPayload(array $overrides = []): array
{
    return array_merge([
        'started_at' => '2026-10-14T10:00:00+07:00',
        'finished_at' => '2026-10-14T10:12:30+07:00',
        'status' => 'success',
        'error_code' => null,
        'login_at' => '2026-10-14T10:00:05+07:00',
        'naskah_at' => '2026-10-14T10:11:00+07:00',
        'snapshots' => [
            [
                'jenis' => 'naskah masuk',
                'fetched' => 120,
                'complete' => true,
                'rows' => 120,
                'responses' => 118,
                'encryptedResponses' => 2,
                'cursorExhausted' => true,
                'tfootSaysDone' => true,
                'domRows' => 120,
                'baru' => 5,
                'revisi' => 1,
                'tetap' => 114,
                'hilangDariSrikandi' => 0,
                'round' => 3,
                'maxRounds' => 10,
            ],
            [
                'jenis' => 'naskah keluar',
                'fetched' => 40,
                'complete' => true,
                'rows' => 40,
                'responses' => 40,
                'encryptedResponses' => 0,
                'cursorExhausted' => true,
                'tfootSaysDone' => true,
                'domRows' => 40,
                'baru' => 2,
                'revisi' => 0,
                'tetap' => 38,
                'hilangDariSrikandi' => null,
            ],
        ],
    ], $overrides);
}

function snapshotUrl(string $slug = 'default'): string
{
    return "/api/v1/srikandi/clients/{$slug}/sessions/snapshot";
}

describe('POST /clients/{slug}/sessions/snapshot (snapshot per sesi)', function () {
    it('menyimpan snapshot sesi dan mengembalikan ok', function () {
        $response = $this->postJson(snapshotUrl(), snapshotPayload(), asScraper());

        $response->assertOk()->assertJsonPath('ok', true);

        expect(Str::isUuid($response->json('id')))->toBeTrue();

        $row = SrikandiSessionSnapshot::query()->firstOrFail();

        expect($row->sumber)->toBe('default')
            ->and($row->status)->toBe('success')
            ->and($row->error_code)->toBeNull();
    });

    it('menyimpan waktu +07:00 sebagai instant yang benar', function () {
        $this->postJson(snapshotUrl(), snapshotPayload(), asScraper())->assertOk();

        $row = SrikandiSessionSnapshot::query()->firstOrFail();

        // Dibandingkan sebagai INSTANT, bukan string: normalisasi timezone
        // boleh menggeser wall-clock, tapi titik waktunya tidak boleh berubah.
        expect($row->started_at->timestamp)
            ->toBe(Carbon::parse('2026-10-14T10:00:00+07:00')->timestamp)
            ->and($row->finished_at->timestamp)
            ->toBe(Carbon::parse('2026-10-14T10:12:30+07:00')->timestamp)
            ->and($row->login_at->timestamp)
            ->toBe(Carbon::parse('2026-10-14T10:00:05+07:00')->timestamp);
    });

    it('menyimpan array snapshots APA ADANYA', function () {
        $this->postJson(snapshotUrl(), snapshotPayload(), asScraper())->assertOk();

        $snapshots = SrikandiSessionSnapshot::query()->firstOrFail()->snapshots;

        expect($snapshots)->toBeArray()->toHaveCount(2)
            ->and($snapshots[0]['jenis'])->toBe('naskah masuk')
            ->and($snapshots[0]['encryptedResponses'])->toBe(2)
            ->and($snapshots[0]['round'])->toBe(3)
            ->and($snapshots[1]['jenis'])->toBe('naskah keluar')
            ->and($snapshots[1]['hilangDariSrikandi'])->toBeNull();
    });

    it('menerima snapshots kosong (sesi gagal sebelum panen)', function () {
        $this->postJson(snapshotUrl(), snapshotPayload([
            'status' => 'failed',
            'error_code' => 'LOGIN_FAILED',
            'login_at' => null,
            'naskah_at' => null,
            'snapshots' => [],
        ]), asScraper())->assertOk()->assertJsonPath('ok', true);

        $row = SrikandiSessionSnapshot::query()->firstOrFail();

        expect($row->status)->toBe('failed')
            ->and($row->error_code)->toBe('LOGIN_FAILED')
            ->and($row->login_at)->toBeNull()
            ->and($row->snapshots)->toBe([]);
    });

    it('TIDAK menyentuh tabel naskah masuk maupun keluar', function () {
        $this->postJson(snapshotUrl(), snapshotPayload(), asScraper())->assertOk();

        expect(SrikandiNaskah::query()->count())->toBe(0)
            ->and(SrikandiNaskahKeluar::query()->count())->toBe(0);
    });

    it('memisahkan sesi milik dua client', function () {
        SrikandiClient::query()->create(['slug' => 'kedua', 'nama' => 'Client Kedua']);

        $this->postJson(snapshotUrl('default'), snapshotPayload(), asScraper())->assertOk();
        $this->postJson(snapshotUrl('kedua'), snapshotPayload(), asScraper())->assertOk();

        expect(SrikandiSessionSnapshot::query()->count())->toBe(2)
            ->and(SrikandiSessionSnapshot::query()->pluck('sumber')->sort()->values()->all())
            ->toBe(['default', 'kedua']);
    });
});

describe('idempotensi snapshot sesi', function () {
    it('dua kiriman sesi SAMA tidak menambah baris', function () {
        $this->postJson(snapshotUrl(), snapshotPayload(), asScraper())->assertOk();
        $this->postJson(snapshotUrl(), snapshotPayload(), asScraper())->assertOk();

        expect(SrikandiSessionSnapshot::query()->count())->toBe(1);
    });

    it('kiriman ulang memperbarui status, bukan menimpa jadi baris baru', function () {
        $this->postJson(snapshotUrl(), snapshotPayload(), asScraper())->assertOk();

        // Rentang identik, tapi siklus yang sama berakhir gagal (mis. retry
        // setelah jaringan putus di tengah).
        $this->postJson(snapshotUrl(), snapshotPayload([
            'status' => 'failed',
            'error_code' => 'PANEN_TIMEOUT',
        ]), asScraper())->assertOk();

        expect(SrikandiSessionSnapshot::query()->count())->toBe(1);

        $row = SrikandiSessionSnapshot::query()->firstOrFail();

        expect($row->status)->toBe('failed')
            ->and($row->error_code)->toBe('PANEN_TIMEOUT');
    });

    it('sesi dengan rentang berbeda tetap dua baris', function () {
        $this->postJson(snapshotUrl(), snapshotPayload(), asScraper())->assertOk();
        $this->postJson(snapshotUrl(), snapshotPayload([
            'started_at' => '2026-10-14T11:00:00+07:00',
            'finished_at' => '2026-10-14T11:05:00+07:00',
        ]), asScraper())->assertOk();

        expect(SrikandiSessionSnapshot::query()->count())->toBe(2);
    });
});

describe('validasi snapshot sesi', function () {
    it('menolak status di luar success/failed', function () {
        $this->postJson(snapshotUrl(), snapshotPayload(['status' => 'hmm']), asScraper())
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    });

    it('menolak finished_at sebelum started_at', function () {
        $this->postJson(snapshotUrl(), snapshotPayload([
            'started_at' => '2026-10-14T10:12:30+07:00',
            'finished_at' => '2026-10-14T10:00:00+07:00',
        ]), asScraper())
            ->assertStatus(422)
            ->assertJsonValidationErrors('finished_at');
    });

    it('menolak started_at yang hilang', function () {
        $payload = snapshotPayload();
        unset($payload['started_at']);

        $this->postJson(snapshotUrl(), $payload, asScraper())
            ->assertStatus(422)
            ->assertJsonValidationErrors('started_at');
    });

    it('menolak jenis snapshot yang tidak dikenal', function () {
        $this->postJson(snapshotUrl(), snapshotPayload([
            'snapshots' => [['jenis' => 'naskah lain']],
        ]), asScraper())
            ->assertStatus(422)
            ->assertJsonValidationErrors('snapshots.0.jenis');
    });

    it('menolak snapshots yang bukan array', function () {
        $this->postJson(snapshotUrl(), snapshotPayload(['snapshots' => 'bukan array']), asScraper())
            ->assertStatus(422)
            ->assertJsonValidationErrors('snapshots');
    });
});

describe('akses & status client pada snapshot sesi', function () {
    it('memberi 401 tanpa token', function () {
        $this->postJson(snapshotUrl(), snapshotPayload())->assertUnauthorized();
    });

    it('menolak token tanpa scope naskah.write', function () {
        $this->postJson(
            snapshotUrl(),
            snapshotPayload(),
            asScraper(scraperToken(['srikandi.client.read'])['plain'])
        )->assertForbidden();

        expect(SrikandiSessionSnapshot::query()->count())->toBe(0);
    });

    it('memberi 404 untuk slug yang tidak ada', function () {
        $this->postJson(snapshotUrl('tidak-ada'), snapshotPayload(), asScraper())
            ->assertNotFound();
    });

    it('memberi 403 -- bukan 404 -- untuk client yang nonaktif', function () {
        SrikandiClient::query()->create([
            'slug' => 'mati',
            'nama' => 'Client Mati',
            'is_active' => false,
        ]);

        $this->postJson(snapshotUrl('mati'), snapshotPayload(), asScraper())
            ->assertForbidden()
            ->assertJsonPath('code', 'client_inactive');

        expect(SrikandiSessionSnapshot::query()->count())->toBe(0);
    });
});

describe('prune snapshot sesi', function () {
    it('menghapus snapshot lebih tua dari ambang retensi', function () {
        SrikandiSessionSnapshot::query()->create([
            'sumber' => 'default',
            'started_at' => now()->subDays(120),
            'finished_at' => now()->subDays(120)->addMinutes(5),
            'status' => 'success',
            'snapshots' => [],
        ]);

        SrikandiSessionSnapshot::query()->create([
            'sumber' => 'default',
            'started_at' => now()->subDay(),
            'finished_at' => now()->subDay()->addMinutes(5),
            'status' => 'success',
            'snapshots' => [],
        ]);

        $this->artisan('srikandi:prune-session-snapshots')->assertSuccessful();

        $rows = SrikandiSessionSnapshot::query()->get();

        expect($rows)->toHaveCount(1)
            ->and($rows->first()->started_at->gt(now()->subDays(2)))->toBeTrue();
    });

    it('--dry-run tidak menghapus apa pun', function () {
        SrikandiSessionSnapshot::query()->create([
            'sumber' => 'default',
            'started_at' => now()->subDays(120),
            'finished_at' => now()->subDays(120)->addMinutes(5),
            'status' => 'success',
            'snapshots' => [],
        ]);

        $this->artisan('srikandi:prune-session-snapshots', ['--dry-run' => true])
            ->assertSuccessful();

        expect(SrikandiSessionSnapshot::query()->count())->toBe(1);
    });
});
