<?php

namespace Bale\Srikandi\Facades;

use Bale\Srikandi\Services\OtpService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Bale\Srikandi\Models\SrikandiOtpState openWindow(?string $rawPhone = null, ?string $purpose = null, ?string $sessionKey = null)
 * @method static \Bale\Srikandi\Models\SrikandiOtpState requestOtp(string $phone, ?string $purpose = null, ?string $sessionKey = null)
 * @method static \Bale\Srikandi\Models\SrikandiOtpState verifyOtp(string $rawCode, ?string $rawPhone = null, ?string $requestId = null)
 * @method static \Bale\Srikandi\Models\SrikandiOtpState|null findVerifiable(?string $rawPhone = null, ?string $requestId = null)
 * @method static \Bale\Srikandi\Models\SrikandiOtpState|null findVerifiableByPhone(string $phone)
 * @method static \Bale\Srikandi\Models\SrikandiOtpState|null findByRequestId(string $requestId)
 *
 * @see OtpService
 */
class Srikandi extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return OtpService::class;
    }
}
