<?php

use Bale\Srikandi\Models\SrikandiClient;
use Bale\Srikandi\Models\SrikandiNaskah;
use Bale\Srikandi\Models\SrikandiNaskahKeluar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/../helpers.php';

uses(RefreshDatabase::class)
    ->beforeEach(function () {
        // `srikandiSetup()` mengembalikan Closure yang memakai `$this`, jadi
        // harus dipanggil dalam konteks test — bukan `srikandiSetup()` polos.
        //
        // Client `default` sudah dibuat setup lewat `firstOrCreate` (dibutuhkan
        // karena `sumber` wajib slug yang terdaftar); membuatnya lagi di sini
        // akan menabrak unique key `srikandi_clients.slug`.
        srikandiSetup()->call($this);
    });

/**
 * Satu baris list Naskah Keluar dari Srikandi.
 *
 * Sengaja memakai nama field MENTAH dari discovery (`nomor`, `tanggal`,
 * `nomor_urut`, `status_send.status`, `hash_value`) untuk membuktikan mapper
 * menerima payload apa adanya, bukan hanya kontrak ternormalisasi.
 */
function keluarItem(array $overrides = []): array
{
    return array_merge([
        'nomor' => '000.6/KH/71/405.18/2026',
        'tanggal' => '2026-10-12',
        'hal' => 'Undangan',
        'ringkasan' => 'Undangan Rapat Koordinasi',
        'tujuan_naskah' => 'Kepala Dinas Komunikasi',
        'status_send' => ['status' => 'KIRIM'],
        'status_baca' => 'BELUM',
        'status_berkas' => null,
        'nomor_urut' => 71,
        'file_path' => 'naskah-dinas-keluar/187514/2026-10',
        'file_name_original' => 'Surat Undangan Rakor.docx',
        'hash_value' => '56b640df9fe18c3ff1aab3b715e098a5',
    ], $overrides);
}

describe('POST /naskah-dinas-keluar (S3.4)', function () {
    it('menyimpan naskah keluar baru dan melaporkan inserted', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas-keluar', [
            'sumber' => 'default',
            'items' => [keluarItem(), keluarItem(['nomor' => '000.6/KH/70/405.18/2026'])],
        ], asScraper())
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('inserted', 2)
            ->assertJsonPath('revised', 0)
            ->assertJsonPath('unchanged', 0);

        expect(SrikandiNaskahKeluar::query()->count())->toBe(2);
    });

    it('primary key naskah keluar adalah UUID', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas-keluar', [
            'sumber' => 'default',
            'items' => [keluarItem()],
        ], asScraper())->assertOk();

        $row = SrikandiNaskahKeluar::query()->firstOrFail();

        expect($row->getIncrementing())->toBeFalse();
        expect($row->getKeyType())->toBe('string');
        expect(Str::isUuid($row->getKey()))->toBeTrue();
    });

    it('melaporkan unchanged dan memperbarui last_seen_at', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas-keluar', [
            'sumber' => 'default',
            'items' => [keluarItem()],
        ], asScraper())->assertOk();

        $firstSeenAt = SrikandiNaskahKeluar::query()->first()->last_seen_at;

        $this->travel(3)->minutes();

        $this->postJson('/api/v1/srikandi/naskah-dinas-keluar', [
            'sumber' => 'default',
            'items' => [keluarItem()],
        ], asScraper())
            ->assertOk()
            ->assertJsonPath('unchanged', 1)
            ->assertJsonPath('inserted', 0);

        expect(SrikandiNaskahKeluar::query()->first()->last_seen_at->toDateTimeString())
            ->not->toBe($firstSeenAt->toDateTimeString());
    });

    it('melaporkan revised saat hash_value berubah', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas-keluar', [
            'sumber' => 'default',
            'items' => [keluarItem()],
        ], asScraper())->assertOk();

        $this->postJson('/api/v1/srikandi/naskah-dinas-keluar', [
            'sumber' => 'default',
            'items' => [keluarItem(['hash_value' => 'ffffffffffffffffffffffffffffffff'])],
        ], asScraper())
            ->assertOk()
            ->assertJsonPath('revised', 1)
            ->assertJsonPath('inserted', 0);

        expect(SrikandiNaskahKeluar::query()->count())->toBe(1)
            ->and(SrikandiNaskahKeluar::query()->first()->row_hash)
            ->toBe('md5:ffffffffffffffffffffffffffffffff');
    });

    it('menyimpan ke tabel keluar, TIDAK menyentuh srikandi_naskah', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas-keluar', [
            'sumber' => 'default',
            'items' => [keluarItem()],
        ], asScraper())->assertOk();

        expect(SrikandiNaskahKeluar::query()->count())->toBe(1)
            ->and(SrikandiNaskah::query()->count())->toBe(0);
    });

    it('tidak punya kolom jenis (tabel terpisah, bukan penanda baris)', function () {
        expect(Schema::hasColumn('srikandi_naskah_keluar', 'jenis'))->toBeFalse()
            ->and(Schema::hasColumn('srikandi_naskah_keluar', 'account_id'))->toBeFalse()
            ->and(Schema::hasColumn('srikandi_naskah_keluar', 'bale_id'))->toBeFalse();
    });

    it('memetakan field discovery ke kolom', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas-keluar', [
            'sumber' => 'default',
            'items' => [keluarItem()],
        ], asScraper())->assertOk();

        $row = SrikandiNaskahKeluar::query()->firstOrFail();

        expect($row->nomor_naskah)->toBe('000.6/KH/71/405.18/2026')
            ->and($row->tahun)->toBe(2026)
            ->and($row->tujuan_naskah)->toBe('Kepala Dinas Komunikasi')
            // Diambil dari status_send.status karena discovery tidak punya
            // `status_tindak_lanjut`.
            ->and($row->status_tindak_lanjut)->toBe('KIRIM')
            ->and($row->status_baca)->toBe('BELUM')
            ->and($row->no_urut)->toBe(71)
            ->and($row->file_path)->toBe('naskah-dinas-keluar/187514/2026-10')
            ->and($row->file_name_original)->toBe('Surat Undangan Rakor.docx')
            ->and($row->hash_value)->toBe('56b640df9fe18c3ff1aab3b715e098a5')
            ->and($row->row_hash)->toBe('md5:56b640df9fe18c3ff1aab3b715e098a5');
    });

    it('menyimpan snapshot payload mentah', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas-keluar', [
            'sumber' => 'default',
            'items' => [keluarItem()],
        ], asScraper())->assertOk();

        expect(SrikandiNaskahKeluar::query()->first()->snapshot['ringkasan'])
            ->toBe('Undangan Rapat Koordinasi');
    });

    it('mengisi 0 untuk tanggal tidak terbaca, BUKAN NULL', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas-keluar', [
            'sumber' => 'default',
            'items' => [keluarItem(['nomor' => 'X', 'tanggal' => 'bukan tanggal'])],
        ], asScraper())->assertOk();

        $row = SrikandiNaskahKeluar::query()->firstOrFail();

        expect($row->tahun)->toBe(0)
            ->and($row->tanggal_naskah)->toBeNull();
    });

    it('melewati item tanpa nomor, bukan menumpuk pada tahun 0', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas-keluar', [
            'sumber' => 'default',
            'items' => [
                keluarItem(['nomor' => '']),
                keluarItem(['nomor' => 'valid']),
            ],
        ], asScraper())
            ->assertOk()
            ->assertJsonPath('inserted', 1)
            ->assertJsonPath('skipped', 1);

        expect(SrikandiNaskahKeluar::query()->count())->toBe(1);
    });

    it('menolak items yang bukan array', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas-keluar', [
            'sumber' => 'default',
            'items' => 'bukan array',
        ], asScraper())->assertStatus(422);
    });
});

describe('validasi sumber naskah keluar (S3.4)', function () {
    it('menolak sumber yang slug-nya tidak ada', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas-keluar', [
            'sumber' => 'tidak-ada',
            'items' => [keluarItem()],
        ], asScraper())->assertStatus(422)
            ->assertJsonValidationErrors('sumber');

        expect(SrikandiNaskahKeluar::query()->count())->toBe(0);
    });

    it('menolak sumber yang bentuknya salah walau slug-nya ada', function () {
        $this->postJson('/api/v1/srikandi/naskah-dinas-keluar', [
            'sumber' => 'Default',
            'items' => [keluarItem()],
        ], asScraper())->assertStatus(422)
            ->assertJsonValidationErrors('sumber');
    });

    it('memisahkan naskah nomor sama milik dua client berbeda', function () {
        SrikandiClient::query()->create(['slug' => 'kedua', 'nama' => 'Client Kedua']);

        $this->postJson('/api/v1/srikandi/naskah-dinas-keluar', [
            'sumber' => 'default',
            'items' => [keluarItem(['hal' => 'Dari client pertama'])],
        ], asScraper())->assertOk();

        $this->postJson('/api/v1/srikandi/naskah-dinas-keluar', [
            'sumber' => 'kedua',
            'items' => [keluarItem(['hal' => 'Dari client kedua'])],
        ], asScraper())->assertOk();

        expect(SrikandiNaskahKeluar::query()->count())->toBe(2)
            ->and(SrikandiNaskahKeluar::query()->pluck('sumber')->sort()->values()->all())
            ->toBe(['default', 'kedua']);
    });
});
