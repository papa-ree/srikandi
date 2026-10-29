<?php

namespace Bale\Srikandi\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Satu sheet daftar naskah (masuk atau keluar).
 *
 * Menerima bagian laporan apa adanya supaya tidak ada pemetaan kolom kedua yang
 * bisa berbeda dari PDF.
 */
final class NaskahSheet implements FromArray, WithHeadings, WithTitle
{
    /**
     * @param  array{title: string, columns: list<string>, headings: list<string>, rows: list<array<string, string>>, total: int}  $section
     */
    public function __construct(private readonly array $section) {}

    /**
     * @return list<list<string>>
     */
    public function array(): array
    {
        return array_map(
            fn (array $row): array => array_map(
                fn (string $column): string => $row[$column],
                $this->section['columns'],
            ),
            $this->section['rows'],
        );
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->section['headings'];
    }

    public function title(): string
    {
        return $this->section['title'];
    }
}
