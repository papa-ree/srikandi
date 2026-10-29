<?php

/*
 | ---------------------------------------------------------------------------
 | Export Overview Srikandi: PDF & XLSX
 | ---------------------------------------------------------------------------
 |
 | Yang dikunci di sini: export memakai PERIODE yang sama dengan halaman,
 | permission yang sama, dan angka yang sama dengan section.
 |
 | Isi sengaja tidak diperiksa sampai ke sel XLSX: membongkar workbook di test
 | menambah dependensi dan kerapuhan tanpa membuktikan lebih banyak daripada
 | memeriksa `OverviewReport` -- sumber data yang dipakai kedua format.
 |
 */

use App\Models\User;
use Bale\Srikandi\Livewire\Pages\Landlord\Overview\Index as OverviewIndex;
use Bale\Srikandi\Livewire\Pages\Landlord\Overview\Section\OverviewCharts;
use Bale\Srikandi\Livewire\Pages\Landlord\Overview\Section\OverviewMetrics;
use Bale\Srikandi\Models\SrikandiNaskah;
use Bale\Srikandi\Models\SrikandiNaskahKeluar;
use Bale\Srikandi\Support\OverviewPeriod;
use Bale\Srikandi\Support\OverviewReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

require_once __DIR__.'/../helpers.php';

beforeEach(function () {
    srikandiSetup()->call($this);

    Permission::firstOrCreate(['name' => 'srikandi.client.read', 'guard_name' => 'web']);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('srikandi.client.read');

    $this->actingAs($this->user);
});

function exportNaskah(string $nomor, string $tanggal, ?string $statusBerkas = null): SrikandiNaskah
{
    return SrikandiNaskah::create([
        'sumber' => 'default',
        'nomor_naskah' => $nomor,
        'tahun' => (int) substr($tanggal, 0, 4),
        'tanggal_naskah' => $tanggal,
        'hal' => 'Hal '.$nomor,
        'ringkasan' => 'Ringkasan '.$nomor,
        'pengirim' => 'Pengirim '.$nomor,
        'status_baca' => 'sudah',
        'status_berkas' => $statusBerkas,
        'first_seen_at' => $tanggal.' 08:00:00',
        'last_seen_at' => now(),
    ]);
}

function exportNaskahKeluar(string $nomor, string $tanggal): SrikandiNaskahKeluar
{
    return SrikandiNaskahKeluar::create([
        'sumber' => 'default',
        'nomor_naskah' => $nomor,
        'tahun' => (int) substr($tanggal, 0, 4),
        'tanggal_naskah' => $tanggal,
        'tujuan_naskah' => 'Tujuan '.$nomor,
        'status_tindak_lanjut' => 'KIRIM',
        'first_seen_at' => $tanggal.' 08:00:00',
        'last_seen_at' => now(),
    ]);
}

function exportLink(string $format, array $query): string
{
    return route('srikandi.overview.export', ['format' => $format] + $query);
}

describe('Laporan export', function () {
    it('mengambil ringkasan dan baris hanya dari periode terpilih', function () {
        exportNaskah('A/1', '2026-08-05', statusBerkas: 'proses');
        exportNaskah('A/2', '2026-08-08');
        exportNaskah('A/3', '2026-08-20');
        exportNaskah('A/4', '2026-09-01');
        exportNaskahKeluar('K/1', '2026-08-06');
        exportNaskahKeluar('K/2', '2026-09-02');

        $report = new OverviewReport(OverviewPeriod::fromQuery(null, '2026-08-01', '2026-08-10'));
        $summary = collect($report->summary())->pluck('value', 'label');

        expect($summary[__('Naskah Masuk')])->toBe(2)
            ->and($summary[__('Naskah Keluar')])->toBe(1)
            ->and($summary->has(__('Client Menyetor')))->toBeFalse()
            ->and($summary[__('Punya Berkas')])->toBe(1)
            ->and($summary[__('Periode')])->toBe('01 Agt 2026 – 10 Agt 2026')
            ->and($report->generatedAt()->timezoneName)->toBe(config('app.timezone'));

        $masuk = $report->masuk();
        $keluar = $report->keluar();

        expect($masuk['total'])->toBe(2)
            ->and(array_column($masuk['rows'], 'nomor_naskah'))->toBe(['A/1', 'A/2'])
            ->and($masuk['rows'][0]['tanggal_naskah'])->toBe('05 Agt 2026')
            ->and($keluar['total'])->toBe(1)
            ->and($keluar['rows'][0]['tujuan_naskah'])->toBe('Tujuan K/1')
            ->and($keluar['rows'][0]['tanggal_naskah'])->toBe('06 Agt 2026');
    });

    it('menghitung total penuh walau daftar baris dipotong', function () {
        foreach (range(1, 3) as $i) {
            exportNaskah('A/'.$i, '2026-08-05');
        }

        $report = new OverviewReport(OverviewPeriod::fromQuery('2026-08', null, null));
        $masuk = $report->masuk();

        expect($masuk['total'])->toBe(3)
            ->and($masuk['rows'])->toHaveCount(3);
    });
});

describe('Route export', function () {
    it('menolak user tanpa permission srikandi.client.read', function () {
        $user = User::factory()->create();

        foreach (['pdf', 'xlsx'] as $format) {
            $this->actingAs($user)
                ->get(exportLink($format, ['bulan' => '2026-08']))
                ->assertForbidden();
        }
    });

    it('mengunduh PDF untuk bulan yang dipilih', function () {
        exportNaskah('A/1', '2026-08-05');
        exportNaskah('A/2', '2026-09-01');

        $response = $this->get(exportLink('pdf', ['bulan' => '2026-08']));

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        expect($response->headers->get('content-disposition'))
            ->toContain('overview-srikandi-2026-08.pdf')
            ->and($response->getContent())->toStartWith('%PDF');
    });

    it('mengunduh XLSX dengan nama file periode aktif', function () {
        exportNaskah('A/1', '2026-08-05');

        $response = $this->get(exportLink('xlsx', ['bulan' => '2026-08']));

        $response->assertOk();

        expect($response->headers->get('content-type'))
            ->toContain('spreadsheetml')
            ->and($response->headers->get('content-disposition'))
            ->toContain('overview-srikandi-2026-08.xlsx');
    });

    it('memakai rentang tanggal, bukan bulan, saat keduanya dikirim', function () {
        exportNaskah('A/1', '2026-07-05');
        exportNaskah('A/2', '2026-08-05');

        $response = $this->get(exportLink('pdf', [
            'bulan' => '2026-08',
            'dari' => '2026-07-01',
            'sampai' => '2026-07-31',
        ]));

        $response->assertOk();
        expect($response->headers->get('content-disposition'))
            ->toContain('overview-srikandi-2026-07-01_2026-07-31.pdf');
    });

    it('menolak format selain pdf dan xlsx', function () {
        $this->get('/srikandi/overview/export/csv')->assertNotFound();
    });
});

describe('Filter rentang di halaman Overview', function () {
    it('membuka mode rentang dari query dan meneruskan periode ke metrik', function () {
        exportNaskah('A/1', '2026-08-05');
        exportNaskah('A/2', '2026-08-20');

        Livewire::test(OverviewIndex::class, ['dari' => '2026-08-01', 'sampai' => '2026-08-10'])
            ->assertSet('mode', 'rentang')
            ->assertSee('Rentang')
            ->assertSee('name="dari"', escape: false)
            ->assertSee('name="sampai"', escape: false);

        Livewire::test(OverviewMetrics::class, ['dari' => '2026-08-01', 'sampai' => '2026-08-10'])
            ->assertViewHas('total', 1);

        Livewire::test(OverviewCharts::class, ['dari' => '2026-08-01', 'sampai' => '2026-08-10'])
            ->assertViewHas('trendLabels', fn (array $labels): bool => count($labels) === 10)
            ->assertViewHas('periodeLabel', '01 Agt 2026 – 10 Agt 2026');
    });

    it('tidak mengirim tag komponen yang gagal dikompilasi', function () {
        // 🔴 `@if` di dalam tag komponen membuat tag terkirim apa adanya dan
        // Alpine mengevaluasi atributnya sebagai ekspresi.
        Livewire::test(OverviewIndex::class)
            ->assertDontSee('x-core::', escape: false);

        // Factory picker harus dari bundle, bukan `<script>` hasil render ulang.
        Livewire::test(OverviewIndex::class)
            ->call('setMode', 'rentang')
            ->assertDontSee('x-core::', escape: false)
            ->assertSee('baleDatePicker(', escape: false);
    });

    it('mengisi rentang dari bulan aktif saat mode rentang dipilih', function () {
        Livewire::test(OverviewIndex::class)
            ->set('bulan', '2026-08')
            ->call('setMode', 'rentang')
            ->assertSet('mode', 'rentang')
            ->assertSet('dari', '2026-08-01')
            ->assertSet('sampai', '2026-08-31')
            ->call('setMode', 'bulan')
            ->assertSet('dari', '')
            ->assertSet('sampai', '');
    });

    it('memotong rentang lebih dari 90 hari dan menulis hasilnya kembali ke input', function () {
        Livewire::test(OverviewIndex::class)
            ->call('setMode', 'rentang')
            ->set('dari', '2026-01-01')
            ->set('sampai', '2026-06-30')
            ->assertSet('dari', '2026-01-01')
            ->assertSet('sampai', '2026-03-31')
            ->assertNotSet('periodError', null)
            ->assertSee('90');
    });

    it('membalik tanggal yang tertukar', function () {
        Livewire::test(OverviewIndex::class)
            ->call('setMode', 'rentang')
            ->set('dari', '2026-08-20')
            ->set('sampai', '2026-08-05')
            ->assertSet('dari', '2026-08-05')
            ->assertSet('sampai', '2026-08-20');
    });

    it('kembali ke bulan berjalan bila tanggal tidak dikenali', function () {
        Livewire::test(OverviewIndex::class)
            ->call('setMode', 'rentang')
            ->set('dari', 'abc')
            ->assertSet('dari', now()->startOfMonth()->toDateString())
            ->assertSet('sampai', now()->endOfMonth()->toDateString())
            ->assertNotSet('periodError', null);
    });

    it('membawa periode aktif pada tautan export', function () {
        Livewire::test(OverviewIndex::class)
            ->set('bulan', '2026-08')
            ->assertSee(exportLink('pdf', ['bulan' => '2026-08']))
            ->assertSee(exportLink('xlsx', ['bulan' => '2026-08']));

        Livewire::test(OverviewIndex::class, ['dari' => '2026-08-01', 'sampai' => '2026-08-10'])
            ->assertSee(exportLink('pdf', ['dari' => '2026-08-01', 'sampai' => '2026-08-10']));
    });
});

describe('Filter bulanan & batas kalender', function () {
    it('menyusun periode dari pilihan bulan dan tahun terpisah', function () {
        Livewire::test(OverviewIndex::class)
            ->assertSee('id="overview-bulan"', escape: false)
            ->assertSee('id="overview-tahun"', escape: false)
            // 🔴 Date picker hanya untuk mode Rentang.
            ->assertDontSee('name="dari"', escape: false)
            ->assertDontSee('baleDatePicker', escape: false)
            ->set('bulan', '2026-08')
            ->call('setBulanTahun', '2025')
            ->assertSet('bulan', '2025-08')
            ->call('setBulanBulan', '3')
            ->assertSet('bulan', '2025-03')
            // Bulan di luar 1-12 kembali ke bulan berjalan, bukan exception.
            ->call('setBulanBulan', '13')
            ->assertSet('bulan', now()->format('Y-m'));
    });

    it('menyertakan tahun dari periode aktif walau belum ada naskah', function () {
        Livewire::test(OverviewIndex::class, ['bulan' => '2020-05'])
            ->assertViewHas('tahunOptions', fn (array $years): bool => in_array('2020', $years, true))
            ->assertSee('2020');
    });

    it('membatasi kalender sampai 90 hari dari tanggal awal', function () {
        Livewire::test(OverviewIndex::class, ['dari' => '2026-08-01', 'sampai' => '2026-08-10'])
            ->assertViewHas('sampaiMaxDate', '2026-10-29')
            ->assertSee('wire:key="overview-sampai-2026-08-01"', escape: false)
            ->assertSee('2026-10-29', escape: false);
    });

    it('melepas date picker saat kembali ke mode bulanan', function () {
        Livewire::test(OverviewIndex::class)
            ->call('setMode', 'rentang')
            ->assertSee('name="dari"', escape: false)
            ->call('setMode', 'bulan')
            ->assertSee('id="overview-bulan"', escape: false)
            ->assertDontSee('name="dari"', escape: false)
            ->assertDontSee('baleDatePicker', escape: false);
    });
});
