<?php

namespace Bale\Srikandi\Exports;

use Bale\Srikandi\Support\OverviewReport;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Workbook export Overview: Ringkasan, Naskah Masuk, Naskah Keluar.
 *
 * Seluruh angka berasal dari `OverviewReport` -- objek yang sama yang dipakai
 * PDF dan halaman. Tidak ada query di kelas ini.
 */
final class OverviewExport implements WithMultipleSheets
{
    public function __construct(private readonly OverviewReport $report) {}

    /**
     * @return array<int, object>
     */
    public function sheets(): array
    {
        return [
            new SummarySheet($this->report),
            new NaskahSheet($this->report->masuk()),
            new NaskahSheet($this->report->keluar()),
        ];
    }
}
