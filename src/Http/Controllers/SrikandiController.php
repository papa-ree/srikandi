<?php

namespace Bale\Srikandi\Http\Controllers;

use Bale\Srikandi\Exceptions\SrikandiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Throwable;

/**
 * Base controller API Srikandi.
 *
 * Bentuk respons mengikuti kontrak di `docs/srikandi.md` §5: `ok` di level atas,
 * bukan `data` seperti konvensi `bale/api`. Scraper sudah ditulis berdasarkan
 * bentuk ini, jadi bentuknya bukan pilihan gaya.
 */
abstract class SrikandiController extends Controller
{
    protected function ok(mixed $payload = [], int $status = 200): JsonResponse
    {
        return response()->json(['ok' => true] + $payload, $status);
    }

    /**
     * Ubah exception terklasifikasi menjadi respons dengan status yang tepat.
     *
     * 🔴 `SrikandiException` TIDAK boleh bocor `code_hash` atau isi OTP ke
     * scraper. `context()` karena itu hanya berisi angka (attempts, sisa,
     * nomor state) — tidak pernah kode.
     */
    protected function failure(SrikandiException $e): JsonResponse
    {
        $response = response()->json([
            'ok' => false,
            'state' => $e->context()['state'] ?? null,
            'message' => $e->getMessage(),
            'code' => $e->errorCode(),
        ] + $this->numericContext($e), $e->status());

        $retryAfter = $e->context()['retry_after'] ?? null;

        if (is_int($retryAfter) && $retryAfter > 0) {
            $response->header('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    /**
     * Hanya angka yang diteruskan; sisanya dibuang.
     */
    protected function numericContext(SrikandiException $e): array
    {
        $allowed = ['attempts', 'remaining', 'expected_digits', 'retry_after'];

        $out = [];

        foreach ($allowed as $key) {
            if (isset($e->context()[$key]) && is_int($e->context()[$key])) {
                $out[$key] = $e->context()[$key];
            }
        }

        return $out;
    }

    /**
     * Jaga agar exception tak terduga tidak membocorkan isi database.
     *
     * Pesan asli dicatat di log (itu tempat debugging yang benar), tapi yang
     * dikirim ke scraper selalu generik: balasannya adalah kode OTP.
     */
    protected function unexpected(Throwable $e): JsonResponse
    {
        report($e);

        return response()->json([
            'ok' => false,
            'message' => 'Terjadi kesalahan internal.',
        ], 500);
    }
}
