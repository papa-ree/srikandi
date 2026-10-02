<?php

namespace Bale\Srikandi\Http\Controllers;

use Bale\Srikandi\Exceptions\SrikandiException;
use Bale\Srikandi\Services\OtpService;
use Bale\Srikandi\Support\PhoneMask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/srikandi/otp-request (spec §5.1, kontrak v2 §5.0b).
 *
 * 🔴 DUA CABANG, dan pilihannya ditentukan oleh ada-tidaknya `phone`:
 *
 * * **`phone` tidak dikirim** -> buka JENDELA LISTENING. Nomor diambil dari
 *   device `purpose=otp`, tidak ada kode yang dikarang, tidak ada WhatsApp yang
 *   dikirim. Inilah jalur scraper.
 * * **`phone` dikirim eksplisit** -> jalur LAMA. Bale mengarang kodenya lalu
 *   mengirimkannya. Dipakai Bale sendiri (admin/internal), dan hanya jalan kalau
 *   pemanggil benar-benar menyebut nomor.
 *
 * Membedakan dua-duanya lewat `phone` (bukan lewat parameter terpisah) dipilih
 * supaya konsumen lama yang selalu mengirim `phone` tidak ikut berubah
 * perilaku — dan supaya scraper tidak pernah bisa tanpa sengaja memicu pengiriman
 * pesan.
 */
class OtpRequestController extends SrikandiController
{
    public function __invoke(Request $request, OtpService $otp): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['nullable', 'string', 'max:32'],
            'purpose' => ['nullable', 'string', 'max:32'],
            'session_key' => ['nullable', 'string', 'max:191'],
        ]);

        $phone = $validated['phone'] ?? null;
        $purpose = $validated['purpose'] ?? null;
        $sessionKey = $validated['session_key'] ?? null;

        try {
            if ($phone !== null && trim($phone) !== '') {
                $state = $otp->requestOtp($phone, $purpose, $sessionKey);
            } else {
                // Argumen pertama `openWindow()` adalah `$rawPhone`, dan sengaja
                // NULL di sini: justru emptiness-nya yang memilih jalur jendela.
                // Mengoper `$purpose` ke posisi itu akan membuat purpose ikut
                // diperlakukan sebagai nomor telepon.
                $state = $otp->openWindow(null, $purpose, $sessionKey);
            }
        } catch (SrikandiException $e) {
            return $this->failure($e);
        } catch (\Throwable $e) {
            return $this->unexpected($e);
        }

        /*
         * 🔴 Yang dikembalikan TIDAK memuat kode OTP maupun nomor penuh.
         *
         * `code_hash` tidak pernah keluar (juga tidak ada di jendela). Nomor
         * hanya muncul sebagai `phone_masked` — petunjuk supaya scraper bisa
         * memastikan device-nya yang dipakai, tanpa memegang nomornya.
         */
        $isNew = $state->wasRecentlyCreated;

        return $this->ok([
            'request_id' => $state->request_id,
            'state' => $state->state,
            'phone_masked' => PhoneMask::mask($state->phone),
            'opened_at' => $state->opened_at?->toIso8601String(),
            'expires_at' => $state->expires_at?->toIso8601String(),
            'state_id' => $state->id,
            'reused' => ! $isNew,
        ], $isNew ? 201 : 200);
    }
}
