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
 */
class NaskahIngestController extends SrikandiController
{
    public function __construct(protected NaskahIngestService $naskah) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', 'integer', 'min:1'],
            'items' => ['present', 'array', 'max:500'],
            'items.*' => ['array'],
        ]);

        try {
            $counts = $this->naskah->ingest($validated['account_id'], $validated['items']);
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
