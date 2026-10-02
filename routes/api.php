<?php

use Bale\Srikandi\Http\Controllers\NaskahIngestController;
use Bale\Srikandi\Http\Controllers\OtpPendingController;
use Bale\Srikandi\Http\Controllers\OtpRequestController;
use Bale\Srikandi\Http\Controllers\OtpVerifyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Endpoint scraper Srikandi (spec §5)
|--------------------------------------------------------------------------
|
| Autentikasi memakai middleware group `bale.api` milik `bale/api`:
| token Bearer + throttle + hardening. TIDAK ada tabel API key khusus
| Srikandi — token dikelola lewat UI token yang sudah ada, bersama scope
| dan masa berlakunya.
|
| Scope dipisah per jenis operasi supaya token scraper bisa dibuat seminimal
| mungkin: token untuk polling OTP tidak perlu bisa menulis naskah.
|
*/

Route::middleware('bale.api')->prefix('api/v1/srikandi')->name('api.v1.srikandi.')->group(function () {
    Route::post('otp-request', OtpRequestController::class)
        ->middleware('scope:srikandi.otp.write')
        ->name('otp-request');

    // Polling: butuh bisa MENGGANTI token karena sering dipakai scraping loop.
    Route::get('otp-pending', OtpPendingController::class)
        ->middleware('scope:srikandi.otp.read')
        ->name('otp-pending');

    Route::post('otp-verify', OtpVerifyController::class)
        ->middleware('scope:srikandi.otp.write')
        ->name('otp-verify');

    Route::post('naskah-dinas', NaskahIngestController::class)
        ->middleware('scope:srikandi.naskah.write')
        ->name('naskah-dinas');
});
