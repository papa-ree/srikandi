<?php

namespace Bale\Srikandi\Http\Controllers;

use Bale\Srikandi\Exceptions\SrikandiException;
use Bale\Srikandi\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/srikandi/otp-verify (spec §5.3, kontrak v2 §5.0b).
 *
 * 🔴 Endpoint ini hanya berlaku untuk record yang punya kode buatan Bale
 * (jalur `requestOtp`). Jendela listening tidak punya kode — kode OTP-nya milik
 * Srikandi — dan dicoba verifikasi di sini akan ditolak dengan
 * `window_not_verifiable`, bukan dianggap "kode salah". Endpoint-nya tetap ada
 * supaya konsumen lama tidak rusak.
 *
 * Responsnya tidak pernah memuat kode itu sendiri — hanya state, waktu, dan
 * jumlah percobaan.
 */
class OtpVerifyController extends SrikandiController
{
    public function __construct(protected OtpService $otp) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'request_id' => ['nullable', 'string', 'max:64'],
            'phone' => ['nullable', 'string', 'max:32'],
            'code' => ['required', 'string', 'max:32'],
        ]);

        $requestId = $this->blankToNull($validated['request_id'] ?? null);
        $phone = $this->blankToNull($validated['phone'] ?? null);

        // Minimal satu identitas. Tanpa ini, `verifyOtp()` akan membaca record
        // terbaru milik siapa pun dan bisa memverifikasi jendela yang bukan milik
        // pemanggil.
        if ($requestId === null && $phone === null) {
            return $this->failure(
                SrikandiException::invalidRequest('request_id atau phone wajib diisi.')
            );
        }

        try {
            $state = $this->otp->verifyOtp($validated['code'], $phone, $requestId);
        } catch (SrikandiException $e) {
            return $this->failure($e);
        } catch (\Throwable $e) {
            return $this->unexpected($e);
        }

        return $this->ok([
            'state' => $state->state,
            'verified_at' => $state->verified_at?->toIso8601String(),
        ]);
    }

    protected function blankToNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
