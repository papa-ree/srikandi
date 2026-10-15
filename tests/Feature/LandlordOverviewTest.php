<?php

/*
 | ---------------------------------------------------------------------------
 | Halaman Overview Srikandi (landlord)
 | ---------------------------------------------------------------------------
 |
 | Halaman ini adalah pintu masuk grup Srikandi dan menyatukan dua sumber data
 | yang berbeda sifat:
 |
 |   - `srikandi_naskah`  -> aktivitas ingest, DISARING periode bulan terpilih
 |   - `srikandi_clients` -> kesehatan akun, kondisi SEKARANG (tidak disaring)
 |
 | Test di bawah mengunci pemisahan itu: angka naskah ikut bulan, status client
 | tidak. Kalau salah satu ikut yang lain, operator membaca "client sehat" dari
 | data bulan lalu tanpa ada yang memberi tahu.
 |
 | 🔴 Filter bulan memakai `tanggal_naskah` (tanggal DOKUMEN), BUKAN
 | `first_seen_at` (kapan Bale pertama melihatnya).
 |
 | Test sengaja membuat `tanggal_naskah` dan `first_seen_at` BERBEDA bulan pada
 | satu baris. Kalau implementasinya keliru memakai waktu ingest, baris itu
 | akan masuk bulan yang salah dan test gagal.
 |
 */

use App\Models\User;
use Bale\Srikandi\Livewire\Pages\Landlord\Overview\Index as OverviewIndex;
use Bale\Srikandi\Livewire\Pages\Landlord\Overview\Section\ClientStatusTable;
use Bale\Srikandi\Livewire\Pages\Landlord\Overview\Section\OverviewCharts;
use Bale\Srikandi\Livewire\Pages\Landlord\Overview\Section\OverviewMetrics;
use Bale\Srikandi\Models\SrikandiClient;
use Bale\Srikandi\Models\SrikandiNaskah;
use Bale\Srikandi\Models\SrikandiNaskahKeluar;
use Bale\Srikandi\Models\SrikandiNotifyRecipient;
use Bale\Srikandi\Models\SrikandiSessionSnapshot;
use Bale\Srikandi\Support\OtpPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

require_once __DIR__.'/../helpers.php';

beforeEach(function () {
    srikandiSetup()->call($this);

    Permission::firstOrCreate(['name' => 'srikandi.client.read', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'srikandi.naskah.read', 'guard_name' => 'web']);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('srikandi.client.read');

    $this->actingAs($this->user);
    Livewire::actingAs($this->user);
});

/**
 * Satu baris naskah. Parameter ketiga adalah `tanggal_naskah` (tanggal
 * DOKUMEN) karena itu yang menyaring periode; `firstSeen` bisa diatur terpisah
 * supaya test bisa membuktikan kolom mana yang dipakai.
 */
function overviewNaskah(
    string $sumber,
    string $nomor,
    string $tanggalNaskah,
    ?string $statusBaca = 'sudah',
    ?string $statusBerkas = null,
    ?string $firstSeen = null,
): SrikandiNaskah {
    return SrikandiNaskah::create([
        'sumber' => $sumber,
        'nomor_naskah' => $nomor,
        'tahun' => (int) substr($tanggalNaskah, 0, 4),
        'tanggal_naskah' => $tanggalNaskah,
        'status_baca' => $statusBaca,
        'status_berkas' => $statusBerkas,
        'first_seen_at' => ($firstSeen ?? $tanggalNaskah).' 08:00:00',
        'last_seen_at' => now(),
    ]);
}

function overviewNaskahKeluar(
    string $sumber,
    string $nomor,
    string $tanggalNaskah,
    ?string $statusTindakLanjut = null,
    ?string $firstSeen = null,
): SrikandiNaskahKeluar {
    return SrikandiNaskahKeluar::create([
        'sumber' => $sumber,
        'nomor_naskah' => $nomor,
        'tahun' => (int) substr($tanggalNaskah, 0, 4),
        'tanggal_naskah' => $tanggalNaskah,
        'status_tindak_lanjut' => $statusTindakLanjut,
        'first_seen_at' => ($firstSeen ?? $tanggalNaskah).' 08:00:00',
        'last_seen_at' => now(),
    ]);
}

function overviewRecipient(SrikandiClient $client, string $phone, bool $active = true): SrikandiNotifyRecipient
{
    $recipient = new SrikandiNotifyRecipient;
    $recipient->forceFill(['client_id' => $client->getKey()]);
    $recipient->fill([
        'type' => SrikandiNotifyRecipient::TYPE_PHONE,
        'is_active' => $active,
    ]);
    $recipient->setValue(app(OtpPhone::class)->normalize($phone));
    $recipient->save();

    return $recipient;
}

/**
 * Satu sesi scraper. `finished_at` yang menentukan "sesi terbaru", jadi test
 * bisa membuat sesi lama & baru pada slug yang sama.
 */
function overviewSesi(
    string $sumber,
    string $status = 'success',
    string $finishedAt = '2026-08-05 10:00:00',
    ?string $errorCode = null,
    ?string $startedAt = null,
): SrikandiSessionSnapshot {
    return SrikandiSessionSnapshot::create([
        'sumber' => $sumber,
        'started_at' => $startedAt ?? $finishedAt,
        'finished_at' => $finishedAt,
        'status' => $status,
        'error_code' => $errorCode,
    ]);
}

describe('Overview Srikandi', function () {
    it('menolak user tanpa permission srikandi.client.read', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/srikandi/overview')
            ->assertForbidden();
    });

    it('terdaftar sebagai route bernama srikandi.overview', function () {
        expect(route('srikandi.overview', absolute: false))->toBe('/srikandi/overview');
    });

    it('merender halaman untuk user berizin', function () {
        $this->withoutVite()
            ->get('/srikandi/overview')
            ->assertOk()
            ->assertSee('Overview Srikandi');
    });

    it('menormalkan bulan yang tidak valid kembali ke bulan berjalan', function () {
        Livewire::test(OverviewIndex::class)
            ->set('bulan', 'abc')
            ->assertSet('bulan', now()->format('Y-m'))
            ->set('bulan', '2026-13')
            ->assertSet('bulan', now()->format('Y-m'))
            ->set('bulan', '2026-08')
            ->assertSet('bulan', '2026-08');
    });

    it('memuat bulan berjalan sebagai opsi walau belum ada naskah', function () {
        Livewire::test(OverviewIndex::class)
            ->assertViewHas('monthOptions', fn (array $options): bool => array_key_exists(now()->format('Y-m'), $options));
    });

    it('menurunkan opsi bulan dari tanggal_naskah, bukan waktu ingest', function () {
        // Dokumen Mei, tapi baru di-ingest bulan ini.
        overviewNaskah('default', 'A/1', '2026-05-11', firstSeen: now()->toDateString());

        Livewire::test(OverviewIndex::class)
            ->assertViewHas('monthOptions', function (array $options): bool {
                return array_key_exists('2026-05', $options)
                    && array_key_exists(now()->format('Y-m'), $options);
            });
    });
});

describe('Overview — metrik', function () {
    it('menghitung naskah hanya pada bulan terpilih, memakai tanggal_naskah', function () {
        overviewNaskah('default', 'A/1', '2026-08-05');
        overviewNaskah('default', 'A/2', '2026-08-20');
        overviewNaskah('default', 'A/3', '2026-09-01');

        // 🔴 Tanggal DOKUMEN di Agustus, tapi PERTAMA DILIHAT di September.
        // Harus dihitung Agustus kalau filternya benar memakai tanggal_naskah.
        overviewNaskah('default', 'A/4', '2026-08-15', firstSeen: '2026-09-10');

        Livewire::test(OverviewMetrics::class, ['bulan' => '2026-08'])
            ->assertViewHas('total', 3);

        Livewire::test(OverviewMetrics::class, ['bulan' => '2026-09'])
            ->assertViewHas('total', 1);
    });

    it('memasukkan naskah bertanggal hari pertama bulan', function () {
        overviewNaskah('default', 'A/1', '2026-08-01');
        overviewNaskah('default', 'A/2', '2026-08-31');

        Livewire::test(OverviewMetrics::class, ['bulan' => '2026-08'])
            ->assertViewHas('total', 2);
    });

    it('mengabaikan naskah tanpa tanggal_naskah', function () {
        overviewNaskah('default', 'A/1', '2026-08-05');

        $kosong = SrikandiNaskah::create([
            'sumber' => 'default',
            'nomor_naskah' => 'A/2',
            'tahun' => 0,
            'tanggal_naskah' => null,
            'status_baca' => 'sudah',
            'first_seen_at' => '2026-08-06 08:00:00',
            'last_seen_at' => now(),
        ]);

        expect($kosong->tanggal_naskah)->toBeNull();

        Livewire::test(OverviewMetrics::class, ['bulan' => '2026-08'])
            ->assertViewHas('total', 1);
    });

    it('menghitung client menyetor sebagai sumber unik', function () {
        SrikandiClient::create(['slug' => 'satker-b', 'nama' => 'Satker B']);

        overviewNaskah('default', 'A/1', '2026-08-05');
        overviewNaskah('default', 'A/2', '2026-08-06');
        overviewNaskah('satker-b', 'B/1', '2026-08-07');

        Livewire::test(OverviewMetrics::class, ['bulan' => '2026-08'])
            ->assertViewHas('menyetor', 2);
    });

    it('menghitung naskah dengan status berkas pada bulan terpilih', function () {
        overviewNaskah('default', 'A/1', '2026-08-05', statusBerkas: 'proses');
        overviewNaskah('default', 'A/2', '2026-08-06', statusBerkas: null);
        overviewNaskah('default', 'A/3', '2026-09-01', statusBerkas: 'selesai');

        Livewire::test(OverviewMetrics::class, ['bulan' => '2026-08'])
            ->assertViewHas('withBerkas', 1);
    });

    it('menghitung naskah keluar pada bulan terpilih, memakai tanggal_naskah', function () {
        overviewNaskahKeluar('default', 'K/1', '2026-08-05');
        overviewNaskahKeluar('default', 'K/2', '2026-08-20');
        overviewNaskahKeluar('default', 'K/3', '2026-09-01');

        // 🔴 Tanggal DOKUMEN Agustus, tapi PERTAMA DILIHAT di September.
        overviewNaskahKeluar('default', 'K/4', '2026-08-15', firstSeen: '2026-09-10');

        Livewire::test(OverviewMetrics::class, ['bulan' => '2026-08'])
            ->assertViewHas('totalKeluar', 3);

        Livewire::test(OverviewMetrics::class, ['bulan' => '2026-09'])
            ->assertViewHas('totalKeluar', 1);
    });
});

describe('Overview — grafik', function () {
    it('mengisi tren harian penuh termasuk hari bernilai nol', function () {
        overviewNaskah('default', 'A/1', '2026-08-05');
        overviewNaskah('default', 'A/2', '2026-08-05');
        overviewNaskah('default', 'A/3', '2026-08-21');

        Livewire::test(OverviewCharts::class, ['bulan' => '2026-08'])
            ->assertViewHas('trendLabels', fn (array $labels): bool => count($labels) === 31)
            ->assertViewHas('trendData', function (array $data): bool {
                // Indeks 0 = tanggal 1.
                return $data[4] === 2        // 5 Agustus
                    && $data[20] === 1       // 21 Agustus
                    && $data[0] === 0        // hari lain nol, bukan dihilangkan
                    && array_sum($data) === 3;
            })
            ->assertViewHas('sumberLabels', fn (array $labels): bool => in_array('default', $labels, true));
    });

    it('mengisi tren naskah keluar dan distribusi status tindak lanjut', function () {
        overviewNaskahKeluar('default', 'K/1', '2026-08-05', statusTindakLanjut: 'KIRIM');
        overviewNaskahKeluar('default', 'K/2', '2026-08-05', statusTindakLanjut: 'BELUM');
        overviewNaskahKeluar('default', 'K/3', '2026-08-21', statusTindakLanjut: 'KIRIM');

        Livewire::test(OverviewCharts::class, ['bulan' => '2026-08'])
            ->assertViewHas('keluarTrendData', function (array $data): bool {
                return $data[4] === 2          // 5 Agustus
                    && $data[20] === 1         // 21 Agustus
                    && array_sum($data) === 3;
            })
            ->assertViewHas('keluarStatusLabels', function (array $labels): bool {
                return in_array('KIRIM', $labels, true) && in_array('BELUM', $labels, true);
            })
            ->assertViewHas('keluarStatusData', fn (array $data): bool => array_sum($data) === 3);
    });
});

describe('Overview — kesehatan client', function () {
    it('menampilkan client beserta jumlah penerima aktif dan status dari sesi terbaru', function () {
        $client = SrikandiClient::query()->where('slug', 'default')->firstOrFail();

        overviewRecipient($client, '628000000001', active: true);
        overviewRecipient($client, '628000000002', active: false);

        overviewNaskah('default', 'A/1', '2026-08-05');
        overviewSesi('default', 'success', '2026-08-05 10:00:00');

        Livewire::test(ClientStatusTable::class, ['bulan' => '2026-08'])
            ->assertViewHas('rows', function ($rows) use ($client): bool {
                $row = $rows->firstWhere('client.id', $client->getKey());

                return $row !== null
                    && $row['naskah'] === 1
                    && $row['recipients'] === 1
                    && $row['status'][0] === __('Sukses')
                    && $row['session']?->status === 'success';
            })
            ->assertSee('Client Default')
            ->assertSee('default');
    });

    it('menampilkan "Belum ada sesi" bila client belum pernah mengirim sesi', function () {
        Livewire::test(ClientStatusTable::class, ['bulan' => '2026-08'])
            ->assertViewHas('rows', function ($rows): bool {
                $row = $rows->first();

                return $row['status'][0] === __('Belum ada sesi')
                    && $row['session'] === null;
            });
    });

    it('mengambil status dari sesi PALING BARU berdasarkan finished_at', function () {
        overviewSesi('default', 'success', '2026-08-05 10:00:00');
        overviewSesi('default', 'failed', '2026-08-06 10:00:00', 'LOGIN_FAILED');

        Livewire::test(ClientStatusTable::class, ['bulan' => '2026-08'])
            ->assertViewHas('rows', function ($rows): bool {
                $row = $rows->first();

                return $row['status'][0] === __('Gagal')
                    && $row['session']->finished_at->format('Y-m-d H:i') === '2026-08-06 10:00'
                    && $row['session']->error_code === 'LOGIN_FAILED';
            })
            ->assertSee('LOGIN_FAILED');
    });

    it('menampilkan sesi terbaru walau selesai di luar bulan yang dipilih', function () {
        // Sesi Juli, tapi tabel dibuka untuk Agustus: status adalah kondisi
        // SEKARANG, tidak ikut disaring bulan (angka naskah yang ikut bulan).
        overviewSesi('default', 'success', '2026-07-20 09:00:00');

        Livewire::test(ClientStatusTable::class, ['bulan' => '2026-08'])
            ->assertViewHas('rows', function ($rows): bool {
                return $rows->first()['status'][0] === __('Sukses');
            });
    });

    it('menautkan ke detail Log Sesi bila user punya srikandi.sesi.read', function () {
        Permission::firstOrCreate(['name' => 'srikandi.sesi.read', 'guard_name' => 'web']);
        $this->user->givePermissionTo('srikandi.sesi.read');

        $session = overviewSesi('default', 'success', '2026-08-05 10:00:00');

        Livewire::test(ClientStatusTable::class, ['bulan' => '2026-08'])
            ->assertSee(route('srikandi.sesi.show', $session), escape: false);
    });
});
