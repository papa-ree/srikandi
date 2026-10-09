<?php

namespace Bale\Srikandi\Facades;

use Bale\Srikandi\Services\OtpService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Bale\Srikandi\Models\SrikandiOtpState openWindow(string $sumber, ?string $rawPhone = null, ?string $purpose = null, ?string $sessionKey = null)
 * @method static \Bale\Srikandi\Models\SrikandiOtpState requestOtp(string $sumber, string $rawPhone, ?string $purpose = null, ?string $sessionKey = null)
 * @method static \Bale\Srikandi\Models\SrikandiOtpState verifyOtp(string $sumber, string $rawCode, ?string $rawPhone = null, ?string $requestId = null)
 * @method static \Bale\Srikandi\Models\SrikandiOtpState|null findVerifiable(string $sumber, ?string $rawPhone = null, ?string $requestId = null)
 * @method static \Bale\Srikandi\Models\SrikandiOtpState|null findVerifiableByPhone(string $phone, string $sumber)
 * @method static \Bale\Srikandi\Models\SrikandiOtpState|null findByRequestId(string $requestId, string $sumber)
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
