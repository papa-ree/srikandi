<?php

use Bale\Srikandi\Models\SrikandiClient;
use Bale\Srikandi\Models\SrikandiNaskah;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/../helpers.php';

uses(RefreshDatabase::class)
    // 🔴 Client `default` dibuat di sini, bukan di tiap test.
    //
    // Endpoint sekarang menolak `sumber` yang slug-nya tidak ada di
    // `srikandi_clients`, jadi setiap test yang mengirim `sumber: default`
    // butuh client itu benar-benar ada. Kalau tiap test membuat sendiri,
    // menambah test baru berarti harus ingat - dan test yang lupa akan gagal
    // dengan 422 yang menyesatkan, bukan karena ada yang salah dengan naskahnya.
    ->beforeEach(function () {
        srikandiSetup();

        SrikandiClient::query()->create([
            'slug' => 'default',
            'nama' => 'Client Default',
        ]);
    });

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
            'sumber' => 'default',
            'items' => [naskahItem(), naskahItem(['nomor' => '800/UA.2026/124'])],
        ], asScraper());

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('inserted', 2)
            ->assertJsonPath('revised', 0)
            ->assertJsonPath('unchanged', 0);

        expect(SrikandiNaskah::query()->count())->toBe(2);
    });

    it('primary key naskah adalah UUID, bukan auto-increment', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
            'items' => [naskahItem()],
        ], asScraper())->assertOk();

        $row = SrikandiNaskah::query()->firstOrFail();

        // 🔴 `getIncrementing()` dan `getKeyType()` wajib ikut dicek, bukan
        // hanya bentuk nilainya. Tanpa `HasUuids`, primary key bisa tetap
        // kebetulan terisi dan test "formatnya UUID" terlihat hijau, padahal
        // Eloquent masih auto-increment. Dua getter itu yang mengunci kontrak.

        expect($row->getIncrementing())->toBeFalse();
        expect($row->getKeyType())->toBe('string');
        expect(Str::isUuid($row->getKey()))->toBeTrue();

    });

    it('primary key naskah berbeda antar baris', function () {
        // Dua naskah berbeda. Kalau `HasUuids` hilang, `incrementing` bawaan
        // Eloquent menghasilkan id angka BERURUTAN - dan test "formatnya UUID"
        // di atas tetap bisa hijau kalau id pertama kebetulan bukan pola UUID.
        // Yang diuji di sini: dua baris, dua id berbeda, keduanya UUID.
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
            'items' => [naskahItem()],
        ], asScraper())->assertOk();

        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
            'items' => [naskahItem(['nomor' => '801/UA.2026/123'])],
        ], asScraper())->assertOk();

        $keys = SrikandiNaskah::query()->pluck('id');

        expect($keys)->toHaveCount(2);
        expect($keys->unique())->toHaveCount(2, 'dua baris harus punya id berbeda');
        expect($keys->every(fn ($k) => Str::isUuid($k)))->toBeTrue();
    });

    it('melaporkan unchanged dan memperbarui last_seen_at', function () {
        $first = $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
            'items' => [naskahItem()],
        ], asScraper());

        $firstSeenAt = SrikandiNaskah::query()->first()->last_seen_at;

        $this->travel(3)->minutes();

        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
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
            'sumber' => 'default',
            'items' => [naskahItem()],
        ], asScraper())->assertOk();

        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
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
            'sumber' => 'default',
            'items' => [naskahItem()],
        ], asScraper());

        // Scraper mengirim status "baru"; backend harus mengabaikannya.
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
            'items' => [naskahItem(['is_new' => true, 'status_baru' => 'baru'])],
        ], asScraper())
            ->assertOk()
            ->assertJsonPath('unchanged', 1)
            ->assertJsonPath('inserted', 0);
    });

    it('TIDAK menerima bale_id di payload anymore', function () {
        // 🔴 `bale_id` sengaja dihapus dari kontrak. `srikandi_naskah` adalah
        // cache dari SATU mailbox SRIKANDI dan tidak berhubungan dengan
        // ale_id sengaja dihapus dari kontrak. srikandi_naskah adalah
        // lama akan tetap mengirimnya dan kita tidak akan pernah tahu kalau
        // ada versi lama yang masih hidup.
        //
        // Laravel mengabaikan field yang tidak dideklarasikan, jadi yang bisa
        // kita periksa adalah: payload dengan `bale_id` tetap berhasil DAN
        // nilainya TIDAK tersimpan ke kolom mana pun.
        // `sumber` ikut dikirim supaya payload ini sah -- kalau tidak, validasi
        // menolak dengan 422 dan test ini jadi lulus karena alasan yang salah:
        // ia_then menguji "422" padahal yang dimaksud "200 dengan bale_id diabaikan".
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
            'bale_id' => '00000000-0000-4000-8000-000000009999',
            'items' => [naskahItem()],
        ], asScraper())->assertOk()->assertJsonPath('inserted', 1);

        expect(SrikandiNaskah::query()->count())->toBe(1);
        expect(Schema::hasColumn('srikandi_naskah', 'bale_id'))->toBeFalse();
        expect(Schema::hasColumn('srikandi_naskah', 'account_id'))->toBeFalse();
    });

    it('memisahkan naskah yang sama dengan tahun berbeda', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
            'items' => [naskahItem(['tanggal' => '2026-09-25'])],
        ], asScraper())->assertJsonPath('inserted', 1);

        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
            'items' => [naskahItem(['tanggal' => '2025-09-25'])],
        ], asScraper())->assertJsonPath('inserted', 1);

        expect(SrikandiNaskah::query()->count())->toBe(2);
    });

    it('menyimpan kode lewat unique (nomor_naskah, tahun)', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
            'items' => [naskahItem()],
        ], asScraper());

        // Insert kedua dengan kunci sama harus jadi revise/unchanged, bukan error.
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
            'items' => [naskahItem(['hash_value' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'])],
        ], asScraper())->assertOk();

        expect(SrikandiNaskah::query()->count())->toBe(1);
    });

    it('menolak items yang bukan array', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
            'items' => 'bukan array',
        ], asScraper())->assertStatus(422);
    });
});

describe('validasi sumber (S3.3)', function () {
    it('menolak sumber yang slug-nya tidak ada di srikandi_clients', function () {
        // 🔴 Ini test yang memblokir naskah tersimpan di bawah pemilik palsu.
        //
        // `default` dibuat di `beforeEach`, jadi `tidak-ada` benar-benar tidak
        // ada. Tanpa validasi ini, payload tetap `200 OK` dan naskahnya
        // tersimpan dengan `sumber = 'tidak-ada'` selamanya.
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'tidak-ada',
            'items' => [naskahItem()],
        ], asScraper())->assertStatus(422)
            ->assertJsonValidationErrors('sumber');

        expect(SrikandiNaskah::query()->count())->toBe(0);
    });

    it('menerima sumber milik client yang ada', function () {
        SrikandiClient::query()->create(['slug' => 'k-office', 'nama' => 'Kantor']);

        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'k-office',
            'items' => [naskahItem()],
        ], asScraper())->assertOk();

        expect(SrikandiNaskah::query()->first()->sumber)->toBe('k-office');
    });

    it('TIDAK menolak sumber milik client yang NONAKTIF', function () {
        // 🔴 inactive = berhenti scraping, BUKAN=data tidak valid.
        //
        // Client yang dinonaktifkan tetap punya naskah historis di database.
        // Kalau ingest ditolak, scraper yang sedang menyelesaikan antrean
        // naskah milik client itu akan gagal dan naskahnya hilang -- padahal
        // naskahnya sudah ada dan masih milik client yang sama.
        //
        // Penolakan terhadap client nonaktif adalah urusan endpoint lain
        // (credentials/heartbeat), bukan endpoint yang menyimpan naskah.
        SrikandiClient::query()->create([
            'slug' => 'nonaktif',
            'nama' => 'Nonaktif',
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'nonaktif',
            'items' => [naskahItem()],
        ], asScraper())->assertOk();

        expect(SrikandiNaskah::query()->count())->toBe(1);
    });

    it('TETAP menolak sumber yang bentuknya salah, walau slug-nya ada', function () {
        // `exists` tidak menggantikan validasi bentuk. Slug dengan huruf besar
        // tidak bisa pernah ada di tabel, jadi harus ditolak oleh `regex` --
        // dan validasinya harus menyangkut format, bukan soal keberadaan.
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'Default',
            'items' => [naskahItem()],
        ], asScraper())->assertStatus(422)
            ->assertJsonValidationErrors('sumber');

        expect(SrikandiNaskah::query()->count())->toBe(0);
    });

    it('memisahkan naskah nomor sama milik dua client berbeda', function () {
        // 🔴 Ini yang bikin `sumber` masuk unique key.
        //
        // Dua akun SRIKANDI punya dokumen dengan nomor identik. Kalau `sumber`
        // tidak ikut unique key, yang kedua jadi `unchanged` dan akun pertama
        // diam-diam kehilangan riwayat naskahnya.
        SrikandiClient::query()->create(['slug' => 'kedua', 'nama' => 'Client Kedua']);

        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
            'items' => [naskahItem(['hal' => 'Naskah dari akun pertama'])],
        ], asScraper())->assertOk();

        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'kedua',
            'items' => [naskahItem(['hal' => 'Naskah dari akun kedua'])],
        ], asScraper())->assertOk();

        expect(SrikandiNaskah::query()->count())->toBe(2)
            ->and(SrikandiNaskah::query()->pluck('sumber')->sort()->values()->all())
            ->toBe(['default', 'kedua']);
    });
});

describe('penurunan tahun dari tanggal (spec §3.2)', function () {
    it('mengambil tahun dari tanggal, bukan dari nomor naskah', function () {
        // Nomor berakhiran 2025, tapi tanggalnya 2026.
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
            'items' => [naskahItem(['nomor' => '800/UA.2025/999', 'tanggal' => '2026-09-25'])],
        ], asScraper())->assertOk();

        expect(SrikandiNaskah::query()->first()->tahun)->toBe(2026);
    });

    it('mengisi 0 untuk tanggal tidak terbaca, BUKAN NULL', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
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
            'sumber' => 'default',
            'items' => [naskahItem(['tanggal' => '2026-13-45'])],
        ], asScraper())->assertOk();

        $row = SrikandiNaskah::query()->first();

        expect($row->tahun)->toBe(0)
            ->and($row->tanggal_naskah)->toBeNull();
    });

    it('menggagalkan dedup kalau tanggal tidak terbaca diulang', function () {
        // Dua baris tanpa tanggal dengan nomor sama harus tetap satu baris.
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
            'items' => [naskahItem(['nomor' => 'X', 'tanggal' => null])],
        ], asScraper())->assertJsonPath('inserted', 1);

        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
            'items' => [naskahItem(['nomor' => 'X', 'tanggal' => null])],
        ], asScraper())->assertJsonPath('unchanged', 1);

        expect(SrikandiNaskah::query()->count())->toBe(1);
    });
});

describe('penyimpanan status mentah (spec §3.2)', function () {
    it('menyimpan status_berkas null apa adanya', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
            'items' => [naskahItem(['status_berkas' => null])],
        ], asScraper())->assertOk();

        $row = SrikandiNaskah::query()->first();

        expect($row->status_berkas)->toBeNull()
            ->and($row->hasBerkas())->toBeFalse();
    });

    it('menyimpan string status_berkas tanpa memnormalisasi', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
            'items' => [naskahItem(['status_berkas' => 'ADA'])],
        ], asScraper())->assertOk();

        expect(SrikandiNaskah::query()->first()->status_berkas)->toBe('ADA');
    });

    it('menyimpan bentuk non-string tanpa diam-diam jadi teks', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
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
            'sumber' => 'default',
            'items' => [naskahItem()],
        ], asScraper())->assertOk();

        $snapshot = SrikandiNaskah::query()->first()->snapshot;

        // jabatan_pengirim tidak punya kolom sendiri, harus ada di snapshot.
        expect($snapshot['jabatan_pengirim'])->toBe('Kabid')
            ->and($snapshot['nomor'])->toBe('800/UA.2026/123');
    });

    it('memetakan pengirim dari nama_pengirim', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
            'items' => [naskahItem()],
        ], asScraper())->assertOk();

        expect(SrikandiNaskah::query()->first()->pengirim)->toBe('Bagian SDM');
    });
});

describe('baris tidak valid', function () {
    it('melewati item tanpa nomor, bukan menumpuk pada tahun 0', function () {
        $response = $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
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
            'sumber' => 'default',
            'items' => [naskahItem(['hash_value' => 'abc123'])],
        ], asScraper())->assertOk();

        expect(SrikandiNaskah::query()->first()->row_hash)->toBe('md5:abc123');
    });

    it('memperlakukan hash yang sebelumnya NULL sebagai revised', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
            'items' => [naskahItem(['hash_value' => null])],
        ], asScraper())->assertJsonPath('inserted', 1);

        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
            'items' => [naskahItem(['hash_value' => 'abc123'])],
        ], asScraper())->assertJsonPath('revised', 1);
    });

    it('menyimpan null untuk row_hash tanpa prefix', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas', [
            'sumber' => 'default',
            'items' => [naskahItem(['hash_value' => null])],
        ], asScraper())->assertOk();

        expect(SrikandiNaskah::query()->first()->row_hash)->toBeNull();
    });
});
