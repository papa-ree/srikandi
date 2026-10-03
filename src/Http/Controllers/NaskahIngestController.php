<?php

namespace Bale\Srikandi\Http\Controllers;

use Bale\Srikandi\Exceptions\SrikandiException;
use Bale\Srikandi\Services\NaskahIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/srikandi/naskah-dinas (spec §5.4).
 *
 * Endpoint ini yang menyimpan hasil pembacaan list Srikandi, dan endpoint ini
 * yang memutuskan `inserted` / `revised` / `unchanged` lewat perbandingan
 * `row_hash` — scraper tidak pernah mengirim status "baru".
 *
 * 🔴 FIELD `bale_id` (UUID `bale_lists.id`) MEWAKILI DUA HAL SEKALI: filter
 * unik `(bale_id, nomor_naskah, tahun)` DAN pernyataan "naskah ini milik
 * organisasi ini". Menaruh UUID yang salah di sini tidak akan gagal diam-diam -
 * naskah deterioro dokumen resmi akan tersimpan di bawah organisasi yang salah
 * dan unique key ikut berubah, sehingga naskah yang sama bisa masuk dua kali
 * dengan UUID berbeda. Karena itu payload WAJIB cocok dengan `bale_id` token.
 */
class NaskahIngestController extends SrikandiController
{
    public function __construct(protected NaskahIngestService $naskah) {}

    public function __invoke(Request $request): JsonResponse
    {
        // 🔴 TIDAK ADA `bale_id` di payload, dan itu disengaja.
        //
        // `srikandi_naskah` adalah cache dari SATU mailbox SRIKANDI. Tidak ada
        // hubungannya dengan `bale_lists` (katalog tenant database), jadi
        // meminta UUID organisasi di sini hanya memaksa scraper mengirim data
        // yang tidak berarti - dan kalau salah, dokumen resmi tersimpan di
        // bawah tenant yang salah tanpa error yang terlihat.
        //
        // Identitas pemanggil tetap dijaga, tapi lewat api token yang sudah
        // ada (`srikandi.naskah.write`), bukan lewat kolom di body.
        $validated = $request->validate([
            'items' => ['present', 'array', 'max:500'],
            'items.*' => ['array'],
        ]);

        try {
            $counts = $this->naskah->ingest($validated['items']);
        } catch (SrikandiException $e) {
            return $this->failure($e);
        } catch (\Throwable $e) {
            return $this->unexpected($e);
        }

        return $this->ok([
            'inserted' => $counts['inserted'],
            'revised' => $counts['revised'],
            'unchanged' => $counts['unchanged'],
            'skipped' => $counts['skipped'],
        ]);
    }
}
