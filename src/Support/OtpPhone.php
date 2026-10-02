<?php

namespace Bale\Srikandi\Support;

use Bale\Wara\Support\PhoneNumber;

/**
 * Normalisasi nomor untuk pencarian state OTP.
 *
 * Nomor normalisasi TIDAK diduplikasi di sini. `bale/wara` sudah
 * memiliki {@see PhoneNumber} yang teruji dan dipakai gateway, dan memakai
 * aturan berbeda akan membuat satu nomor cocok dengan `wara_logs` tapi tidak
 * cocok dengan `srikandi_otp_states` — bug yang sangat sulit dilacak karena
 * gejalanya "balasan OTP tidak pernah kecocok".
 *
 * Kolom `phone` di `srikandi_otp_states` karena itu selalu disimpan dalam
 * bentuk yang sudah dinormalisasi, dan pencarian juga selalu lewat bentuk itu.
 */
final class OtpPhone
{
    public function __construct(protected PhoneNumber $normalizer) {}

    /**
     * Nomor polos format internasional (`62812...`).
     */
    public function normalize(string $phone): string
    {
        return $this->normalizer->toInternational($phone);
    }

    /**
     * Nomor valid atau null, supaya pemanggil bisa menolak dengan pesan jelas.
     */
    public function normalizeOrNull(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $normalized = $this->normalize($phone);

        return $normalized === '' ? null : $normalized;
    }
}
