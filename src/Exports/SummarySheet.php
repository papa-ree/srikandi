<?php

namespace Bale\Srikandi\Exports;

use Bale\Srikandi\Support\OverviewReport;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

final class SummarySheet implements FromArray, WithHeadings, WithTitle
{
    public function __construct(private readonly OverviewReport $report) {}

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function array(): array
    {
        return array_map(
            fn (array $row): array => [$row['label'], (string) $row['value']],
            $this->report->summary(),
        );
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [__('Ringkasan'), __('Nilai')];
    }

    public function title(): string
    {
        return __('Ringkasan');
    }
}
