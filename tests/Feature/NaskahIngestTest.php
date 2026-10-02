<?php

use Bale\Srikandi\Models\SrikandiNaskah;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../helpers.php';

uses(RefreshDatabase::class)
    ->beforeEach(srikandiSetup());

/**
 * Satu baris list naskah dari Srikandi.
 */
function naskahItem(array $overrides = []): array
{
    return array_merge([
        'nomor' => '800/UA.2026/123',
        'tanggal' => '2026-09-25',
        'hal' => 'Undangan',
        'ringkasan' => 'Undangan rapat',
        'nama_pengirim' => 'Bagian SDM',
        'jabatan_pengirim' => 'Kabid',
        'status_baca' => 'BELUM',
        'status_tindak' => 'TINDAK LANJUT',
        'status_berkas' => null,
        'hash_value' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6',
    ], $overrides);
}

describe('POST /naskah-dinas (spec §5.4)', function () {
    it('menyimpan naskah baru dan melaporkan inserted', function () {
        $response = $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [naskahItem(), naskahItem(['nomor' => '800/UA.2026/124'])],
        ], asScraper());

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('inserted', 2)
            ->assertJsonPath('revised', 0)
            ->assertJsonPath('unchanged', 0);

        expect(SrikandiNaskah::query()->count())->toBe(2);
    });

    it('melaporkan unchanged dan memperbarui last_seen_at', function () {
        $first = $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [naskahItem()],
        ], asScraper());

        $firstSeenAt = SrikandiNaskah::query()->first()->last_seen_at;

        $this->travel(3)->minutes();

        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [naskahItem()],
        ], asScraper())
            ->assertOk()
            ->assertJsonPath('unchanged', 1)
            ->assertJsonPath('inserted', 0);

        // Spec §5.4: last_seen_at diperbarui bahkan saat unchanged — itu yang
        // membuat naskah yang hilang dari list bisa terdeteksi.
        expect(SrikandiNaskah::query()->first()->last_seen_at->toDateTimeString())
            ->not->toBe($firstSeenAt->toDateTimeString());
    });

    it('melaporkan revised saat row_hash berubah', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [naskahItem()],
        ], asScraper())->assertOk();

        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [naskahItem(['hash_value' => 'ffffffffffffffffffffffffffffffff'])],
        ], asScraper())
            ->assertOk()
            ->assertJsonPath('revised', 1)
            ->assertJsonPath('inserted', 0);

        expect(SrikandiNaskah::query()->count())->toBe(1)
            ->and(SrikandiNaskah::query()->first()->row_hash)
            ->toBe('md5:ffffffffffffffffffffffffffffffff');
    });

    it('BACKEND yang memutuskan inserted vs revised, bukan scraper', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [naskahItem()],
        ], asScraper());

        // Scraper mengirim status "baru"; backend harus mengabaikannya.
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [naskahItem(['is_new' => true, 'status_baru' => 'baru'])],
        ], asScraper())
            ->assertOk()
            ->assertJsonPath('unchanged', 1)
            ->assertJsonPath('inserted', 0);
    });

    it('memisahkan naskah antar account_id', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1, 'items' => [naskahItem()],
        ], asScraper())->assertJsonPath('inserted', 1);

        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 2, 'items' => [naskahItem()],
        ], asScraper())->assertJsonPath('inserted', 1);

        expect(SrikandiNaskah::query()->count())->toBe(2);
    });

    it('memisahkan naskah yang sama dengan tahun berbeda', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [naskahItem(['tanggal' => '2026-09-25'])],
        ], asScraper())->assertJsonPath('inserted', 1);

        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [naskahItem(['tanggal' => '2025-09-25'])],
        ], asScraper())->assertJsonPath('inserted', 1);

        expect(SrikandiNaskah::query()->count())->toBe(2);
    });

    it('menyimpan kode lewat unique (account_id, nomor_naskah, tahun)', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1, 'items' => [naskahItem()],
        ], asScraper());

        // Insert kedua dengan kunci sama harus jadi revise/unchanged, bukan error.
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [naskahItem(['hash_value' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'])],
        ], asScraper())->assertOk();

        expect(SrikandiNaskah::query()->count())->toBe(1);
    });

    it('menolak account_id tidak valid', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 0, 'items' => [naskahItem()],
        ], asScraper())->assertStatus(422);
    });

    it('menolak items yang bukan array', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1, 'items' => 'bukan array',
        ], asScraper())->assertStatus(422);
    });
});

describe('penurunan tahun dari tanggal (spec §3.2)', function () {
    it('mengambil tahun dari tanggal, bukan dari nomor naskah', function () {
        // Nomor berakhiran 2025, tapi tanggalnya 2026.
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [naskahItem(['nomor' => '800/UA.2025/999', 'tanggal' => '2026-09-25'])],
        ], asScraper())->assertOk();

        expect(SrikandiNaskah::query()->first()->tahun)->toBe(2026);
    });

    it('mengisi 0 untuk tanggal tidak terbaca, BUKAN NULL', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [
                naskahItem(['nomor' => 'A', 'tanggal' => null]),
                naskahItem(['nomor' => 'B', 'tanggal' => '']),
                naskahItem(['nomor' => 'C', 'tanggal' => 'bukan tanggal']),
            ],
        ], asScraper())->assertOk();

        foreach (SrikandiNaskah::query()->get() as $row) {
            // NULL akan menggagalkan dedup di MySQL; harus 0.
            expect($row->tahun)->toBe(0);
        }
    });

    it('menolak tanggal yang kalendernya tidak valid', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [naskahItem(['tanggal' => '2026-13-45'])],
        ], asScraper())->assertOk();

        $row = SrikandiNaskah::query()->first();

        expect($row->tahun)->toBe(0)
            ->and($row->tanggal_naskah)->toBeNull();
    });

    it('menggagalkan dedup kalau tanggal tidak terbaca diulang', function () {
        // Dua baris tanpa tanggal dengan nomor sama harus tetap satu baris.
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [naskahItem(['nomor' => 'X', 'tanggal' => null])],
        ], asScraper())->assertJsonPath('inserted', 1);

        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [naskahItem(['nomor' => 'X', 'tanggal' => null])],
        ], asScraper())->assertJsonPath('unchanged', 1);

        expect(SrikandiNaskah::query()->count())->toBe(1);
    });
});

describe('penyimpanan status mentah (spec §3.2)', function () {
    it('menyimpan status_berkas null apa adanya', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [naskahItem(['status_berkas' => null])],
        ], asScraper())->assertOk();

        $row = SrikandiNaskah::query()->first();

        expect($row->status_berkas)->toBeNull()
            ->and($row->hasBerkas())->toBeFalse();
    });

    it('menyimpan string status_berkas tanpa mem нормаisasi', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [naskahItem(['status_berkas' => 'ADA'])],
        ], asScraper())->assertOk();

        expect(SrikandiNaskah::query()->first()->status_berkas)->toBe('ADA');
    });

    it('menyimpan bentuk non-string tanpa diam-diam jadi teks', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [naskahItem(['status_berkas' => ['scan.pdf', 'surat.pdf']])],
        ], asScraper())->assertOk();

        // Versi lain mengirim array; bentuknya harus tetap berbeda dan
        // ketahuan, bukan berubah jadi string yang tidak bisa dibedakan.
        $stored = SrikandiNaskah::query()->first()->status_berkas;

        expect($stored)->not->toBe('ADA')
            ->and(str_starts_with((string) $stored, '['))->toBeTrue();
    });

    it('menyimpan snapshot payload mentah untuk rekonstruksi', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [naskahItem()],
        ], asScraper())->assertOk();

        $snapshot = SrikandiNaskah::query()->first()->snapshot;

        // jabatan_pengirim tidak punya kolom sendiri, harus ada di snapshot.
        expect($snapshot['jabatan_pengirim'])->toBe('Kabid')
            ->and($snapshot['nomor'])->toBe('800/UA.2026/123');
    });

    it('memetakan pengirim dari nama_pengirim', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [naskahItem()],
        ], asScraper())->assertOk();

        expect(SrikandiNaskah::query()->first()->pengirim)->toBe('Bagian SDM');
    });
});

describe('baris tidak valid', function () {
    it('melewati item tanpa nomor, bukan menumpuk pada tahun 0', function () {
        $response = $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [
                naskahItem(['nomor' => '']),
                naskahItem(['nomor' => null]),
                naskahItem(['nomor' => '   ']),
                naskahItem(['nomor' => 'valid']),
            ],
        ], asScraper());

        $response->assertOk()
            ->assertJsonPath('inserted', 1)
            ->assertJsonPath('skipped', 3);

        expect(SrikandiNaskah::query()->count())->toBe(1);
    });

    it('menyimpan row_hash dengan prefix md5:', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [naskahItem(['hash_value' => 'abc123'])],
        ], asScraper())->assertOk();

        expect(SrikandiNaskah::query()->first()->row_hash)->toBe('md5:abc123');
    });

    it('memperlakukan hash yang sebelumnya NULL sebagai revised', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [naskahItem(['hash_value' => null])],
        ], asScraper())->assertJsonPath('inserted', 1);

        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [naskahItem(['hash_value' => 'abc123'])],
        ], asScraper())->assertJsonPath('revised', 1);
    });

    it('menyimpan null untuk row_hash tanpa prefix', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'account_id' => 1,
            'items' => [naskahItem(['hash_value' => null])],
        ], asScraper())->assertOk();

        expect(SrikandiNaskah::query()->first()->row_hash)->toBeNull();
    });
});
