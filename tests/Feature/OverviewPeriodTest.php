<?php

/*
 | ---------------------------------------------------------------------------
 | Periode Overview: bulan vs rentang tanggal
 | ---------------------------------------------------------------------------
 |
 | Batas 90 hari dan koreksi input (dibalik, dipotong, rusak) adalah aturan
 | produk, bukan detail tampilan. Test di sini mengunci angka batasnya supaya
 | perubahan kecil di kemudian hari tidak memperlebar export tanpa disadari.
 |
 */

use Bale\Srikandi\Support\OverviewPeriod;

describe('OverviewPeriod', function () {
    it('memakai bulan berjalan bila tidak ada query periode', function () {
        $period = OverviewPeriod::fromQuery(null, null, null);

        expect($period->isRange)->toBeFalse()
            ->and($period->key())->toBe(now()->format('Y-m'))
            ->and($period->tanggalRange()[0])->toBe(now()->startOfMonth()->toDateString())
            ->and($period->tanggalRange()[1])->toBe(now()->endOfMonth()->toDateTimeString());
    });

    it('memakai akhir hari sebagai batas atas', function () {
        $period = OverviewPeriod::fromQuery('2026-08', null, null);

        expect($period->tanggalRange())->toBe([
            '2026-08-01',
            '2026-08-31 23:59:59',
        ]);
    });

    it('memilih rentang walau bulan juga dikirim', function () {
        $period = OverviewPeriod::fromQuery('2026-08', '2026-07-10', '2026-07-20');

        expect($period->isRange)->toBeTrue()
            ->and($period->start->toDateString())->toBe('2026-07-10')
            ->and($period->end->toDateString())->toBe('2026-07-20')
            ->and($period->notice)->toBeNull();
    });

    it('membalik tanggal yang tertukar', function () {
        $period = OverviewPeriod::fromQuery(null, '2026-08-20', '2026-08-05');

        expect($period->start->toDateString())->toBe('2026-08-05')
            ->and($period->end->toDateString())->toBe('2026-08-20')
            ->and($period->notice)->toContain('tertukar');
    });

    it('menerima tepat 90 hari', function () {
        $period = OverviewPeriod::fromQuery(null, '2026-01-01', '2026-03-31');

        expect($period->end->toDateString())->toBe('2026-03-31')
            ->and($period->notice)->toBeNull();
    });

    it('memotong rentang lebih dari 90 hari', function () {
        $period = OverviewPeriod::fromQuery(null, '2026-01-01', '2026-06-30');

        expect($period->start->toDateString())->toBe('2026-01-01')
            ->and($period->end->toDateString())->toBe('2026-03-31')
            ->and($period->notice)->toContain('90');
    });

    it('kembali ke bulan berjalan bila salah satu tanggal tidak dikenali', function () {
        $period = OverviewPeriod::fromQuery('2026-08', '2026-08-05', '2026-02-31');

        expect($period->isRange)->toBeFalse()
            ->and($period->key())->toBe('2026-08')
            ->and($period->notice)->not->toBeNull();
    });

    it('kembali ke bulan berjalan bila kedua tanggal tidak dikenali', function () {
        $period = OverviewPeriod::fromQuery('2026-08', 'abc', 'xyz');

        expect($period->isRange)->toBeFalse()
            ->and($period->key())->toBe('2026-08')
            ->and($period->notice)->not->toBeNull();
    });

    it('membangun query export sesuai mode', function () {
        expect(OverviewPeriod::fromQuery('2026-08', null, null)->query())
            ->toBe(['bulan' => '2026-08'])
            ->and(OverviewPeriod::fromQuery(null, '2026-08-01', '2026-08-10')->query())
            ->toBe(['dari' => '2026-08-01', 'sampai' => '2026-08-10']);
    });
});
