<?php

/*
 | ---------------------------------------------------------------------------
 | `sumber` sebagai penentu kepemilikan naskah
 | ---------------------------------------------------------------------------
 |
 | 🔴 Test di file ini mengunci perubahan yang paling mudah terlewat di
 | multi-client: `nomor_naskah` + `tahun` TIDAK unik lintas akun.
 |
 | Dua satker bisa punya nomor naskah sama. Tanpa `sumber`, yang kedua akan
 | mendarat di baris yang sama dan dilaporkan `unchanged` -- bukan error.
 | Efeknya satu akun diam-diam kehilangan riwayat naskahnya, dan tidak ada
 | yang diberi tahu karena semua angka di dashboard tetap terlihat wajar.
 |
 */

use Bale\Srikandi\Models\SrikandiNaskah;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../helpers.php';

uses(RefreshDatabase::class)
    ->beforeEach(srikandiSetup());

function naskahRow(string $sumber, string $nomor, int $tahun = 2026, array $extra = []): array
{
    return array_merge([
        'sumber' => $sumber,
        'nomor_naskah' => $nomor,
        'tahun' => $tahun,
        'hal' => 'Undangan',
        'pengirim' => 'Bagian SDM',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $extra);
}

describe('sumber menentukan kepemilikan naskah', function () {
    it('menyimpan naskah dengan sumber yang berbeda sebagai baris terpisah', function () {
        SrikandiNaskah::query()->create(naskahRow('satker-a', '800/UA.2026/123'));
        SrikandiNaskah::query()->create(naskahRow('satker-b', '800/UA.2026/123'));

        expect(SrikandiNaskah::query()->count())->toBe(2);
    });

    it('menyimpan naskah dengan sumber sama dan nomor sama sebagai unchanged', function () {
        $hash = 'md5:'.str_repeat('a', 32);

        SrikandiNaskah::query()->create(naskahRow('satker-a', '800/UA.2026/123', 2026, [
            'row_hash' => $hash,
        ]));

        // Insert kedua dengan kunci IDENTIK harus ditolak oleh unique key.
        // Kalau tidak ditolak, berarti unique key-nya belum memuat `sumber`.
        expect(fn () => SrikandiNaskah::query()->create(naskahRow('satker-a', '800/UA.2026/123', 2026, [
            'row_hash' => $hash,
        ])))->toThrow(QueryException::class);
    });

    it('MEMISAHKAN naskah sumber berbeda walau seluruh field lain sama', function () {
        // 🔴 Ini kasus yang paling mudah salah diimplementasikan: constrain
        // ditambahkan di `persist()` tapi TIDAK di unique key. Test yang hanya
        // memanggil service akan lulus, sementara database tetap bisa menyimpan
        // dua baris yang seharusnya bentrok.
        SrikandiNaskah::query()->create(naskahRow('satker-a', 'X/2026'));
        SrikandiNaskah::query()->create(naskahRow('satker-b', 'X/2026'));

        expect(SrikandiNaskah::query()->count())->toBe(2);

        // Unique key harus benar-benar ada di database, bukan hanya di kode.
        $indexes = collect(DB::select(
            'PRAGMA index_list(srikandi_naskah)'
        ));

        $uniqueNames = $indexes->pluck('name')->all();

        expect($uniqueNames)->toContain('srikandi_naskah_sumber_nomor_naskah_tahun_unique');
    });

    it('TIDAK menghasilkan baris dengan sumber kosong', function () {
        // 🔴 `sumber` NOT NULL. Kalau sampai kosong, naskah itu jadi yatim: tidak
        // bisa dikaitkan ke client mana pun, dan tidak ada halaman yang
        // menampilkannya karena semua daftar memfilter per sumber.
        expect(fn () => SrikandiNaskah::query()->create([
            'nomor_naskah' => 'Y/2026',
            'tahun' => 2026,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    it('masukkan sumber ke dedupKey()', function () {
        $a = new SrikandiNaskah(['sumber' => 'satker-a', 'nomor_naskah' => 'X/2026', 'tahun' => 2026]);
        $b = new SrikandiNaskah(['sumber' => 'satker-b', 'nomor_naskah' => 'X/2026', 'tahun' => 2026]);

        expect($a->dedupKey())->toBe('satker-a|X/2026|2026')
            ->and($b->dedupKey())->toBe('satker-b|X/2026|2026')
            ->and($a->dedupKey())->not->toBe($b->dedupKey());
    });
});

describe('scope query naskah', function () {
    beforeEach(function () {
        SrikandiNaskah::query()->create(naskahRow('satker-a', 'A/2026', 2026));
        SrikandiNaskah::query()->create(naskahRow('satker-a', 'B/2025', 2025));
        SrikandiNaskah::query()->create(naskahRow('satker-b', 'C/2026', 2026));
    });

    it('scopeSumber() memfilter per client', function () {
        expect(SrikandiNaskah::query()->sumber('satker-a')->count())->toBe(2)
            ->and(SrikandiNaskah::query()->sumber('satker-b')->count())->toBe(1)
            ->and(SrikandiNaskah::query()->sumber('tidak-ada')->count())->toBe(0);
    });

    it('scopeTahun() memfilter per tahun', function () {
        expect(SrikandiNaskah::query()->tahun(2026)->count())->toBe(2)
            ->and(SrikandiNaskah::query()->tahun(2025)->count())->toBe(1);
    });

    it('scopeSumber() dan scopeTahun() bisa dirangkai', function () {
        expect(SrikandiNaskah::query()->sumber('satker-a')->tahun(2026)->count())->toBe(1);
    });

    it('scopeBerkasState() membedakan null dan string kosong', function () {
        SrikandiNaskah::query()->create(naskahRow('satker-a', 'ADA/2026', 2026, ['status_berkas' => 'ADA']));
        SrikandiNaskah::query()->create(naskahRow('satker-a', 'KOSONG/2026', 2026, ['status_berkas' => '']));

        $ada = SrikandiNaskah::query()->berkasState('ADA')->pluck('nomor_naskah')->all();
        $tidakAda = SrikandiNaskah::query()->berkasState(SrikandiNaskah::BERKAS_TIDAK_ADA)->pluck('nomor_naskah')->all();

        expect($ada)->toBe(['ADA/2026'])
            ->and($tidakAda)->toContain('A/2026')
            ->and($tidakAda)->toContain('KOSONG/2026')
            ->and($tidakAda)->not->toContain('ADA/2026');
    });

    it('scopeFresh() SEPAKAT dengan isStale() pada ambang yang sama', function () {
        // 🔴 Test terpenting di file ini.
        //
        // `scopeFresh()` meng-bound `last_seen_at` di database (pakai index),
        // `isStale()` menghitungnya di PHP. Kalau keduanya berbeda batasnya,
        // card ringkasan di header dan filter di tabel menjawab pertanyaan
        // berbeda untuk data yang sama -- dan itu kelas bug yang tidak terlihat
        // dari halaman mana pun, karena keduanya "terlihat benar".
        foreach ([1, 5, 60, 1440] as $menit) {
            // 5 menit lalu = fresh untuk ambang 10, tapi stale untuk ambang 1.
            SrikandiNaskah::query()->create(naskahRow(
                'satker-c',
                "F/1440/{$menit}",
                2026,
                ['last_seen_at' => now()->subMinutes($menit)]
            ));
        }

        $semua = SrikandiNaskah::query()->sumber('satker-c')->get();

        foreach ([1, 5, 60, 1440] as $menit) {
            // 🔴 Panggil `notFresh()`, BUKAN `whereNotFresh()`.
            //
            // Dynamic `where*` di Eloquent diprioritaskan: method yang diawali
            // "where" dianggap filter kolom, bukan scope. `whereNotFresh()`
            // berarti filter kolom "notFresh" -- dan karena kolom itu tidak ada,
            // hasilnya selalu nol. Gejalanya bukan error, hanya "tidak ada
            // naskah yang basi" padahal ada. Scope-nya dipanggil polos.
            $lewatQuery = SrikandiNaskah::query()
                ->sumber('satker-c')
                ->notFresh($menit)
                ->pluck('nomor_naskah')
                ->all();

            $lewatPhp = $semua
                ->filter(fn (SrikandiNaskah $n) => $n->isStale($menit))
                ->pluck('nomor_naskah')
                ->all();

            expect($lewatQuery)->toEqualCanonicalizing($lewatPhp, "ambang {$menit} menit");
        }
    });
});
