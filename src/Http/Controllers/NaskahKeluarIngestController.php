<?php

namespace Bale\Srikandi\Http\Controllers;

use Bale\Srikandi\Exceptions\SrikandiException;
use Bale\Srikandi\Services\NaskahKeluarIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/srikandi/naskah-dinas-keluar (PRD §8, PLAN S3.4).
 *
 * Kontrak SAMA dengan `naskah-dinas`: `{ sumber, items[] }`. Endpoint ini
 * memutuskan `inserted`/`revised`/`unchanged` lewat perbandingan `row_hash`
 * milik Srikandi -- scraper tidak pernah mengirim status "baru".
 *
 * 🔴 Menulis ke `srikandi_naskah_keluar`, tabel TERPISAH. Tidak menyentuh
 * `srikandi_naskah` maupun `NaskahNotifier` (PRD §4.1c).
 *
 * 🔴 `sumber` WAJIB dan HARUS SLUG YANG ADA, persis seperti `naskah-dinas`.
 * Pola yang sama supaya scraper tidak perlu tahu dua kontrak untuk satu
 * keluarga endpoint.
 */
class NaskahKeluarIngestController extends SrikandiController
{
    public function __construct(
        protected NaskahKeluarIngestService $naskah,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /*
         * 🔴 `sumber` = slug client, dan HANYA keberadaannya yang dicek, bukan
         * keaktifan atau kepemilikan kredensial. Alasannya sama dengan
         * `naskah-dinas`: `srikandi_naskah_keluar` sengaja TIDAK punya foreign
         * key dari `sumber`, supaya dokumen yang sudah tersimpan tidak hilang
         * hanya karena operator mengganti nama atau menonaktifkan client.
         *
         * Slug tak dikenal => 422. Kalau diterima diam-diam, naskah tersimpan
         * dengan pemilik yang salah dan tidak ada filter yang membuatnya
         * mencolok -- riwayat naskah yang tidak pernah ada akan terlihat sah.
         */
        $validated = $request->validate([
            'sumber' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9-]+$/',
                Rule::exists('srikandi_clients', 'slug'),
            ],
            'items' => ['present', 'array', 'max:500'],
            'items.*' => ['array'],
        ]);

        try {
            $counts = $this->naskah->ingest($validated['items'], $validated['sumber']);
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
