<?php

namespace Bale\Srikandi\Http\Controllers;

use Bale\Core\Support\Pdf;
use Bale\Srikandi\Exports\OverviewExport;
use Bale\Srikandi\Support\OverviewPeriod;
use Bale\Srikandi\Support\OverviewReport;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Export Overview ke PDF atau XLSX untuk periode yang sedang dilihat.
 *
 * 🔴 Periode dibaca dari query yang SAMA dengan halaman (`bulan` atau
 * `dari`+`sampai`) dan diterjemahkan oleh `OverviewPeriod` yang sama. Tautan
 * export membawa periode aktif, jadi yang diunduh tidak pernah berbeda dari
 * yang tampil di layar.
 *
 * 🔴 Permission tetap `srikandi.client.read` (middleware route), sama dengan
 * halaman Overview. Isinya data yang sama; menambah permission baru hanya
 * membuat operator yang boleh melihat halaman tidak boleh menyimpan hasilnya.
 */
final class OverviewExportController
{
    public function __invoke(Request $request): Response|BinaryFileResponse
    {
        $period = OverviewPeriod::fromQuery(
            $this->queryString($request, 'bulan'),
            $this->queryString($request, 'dari'),
            $this->queryString($request, 'sampai'),
        );

        $report = new OverviewReport($period);
        $filename = 'overview-srikandi-'.$period->key();

        if ($request->route('format') === 'pdf') {
            return Pdf::download(
                'srikandi::pdf.overview',
                ['report' => $report],
                $filename.'.pdf',
                'a4',
                'landscape',
            );
        }

        return Excel::download(new OverviewExport($report), $filename.'.xlsx');
    }

    /**
     * Query adalah input pengguna: `?bulan[]=x` mengirim array, dan itu tidak
     * boleh sampai ke `OverviewPeriod` yang bertipe `?string`.
     */
    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
