<?php

use Bale\Srikandi\Http\Controllers\ClientController;
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

    /*
     * ------------------------------------------------------------------
     | Endpoint client (S3)
     * ------------------------------------------------------------------
     |
     | 🔴 `credentials` dan `heartbeat` TIDAK memakai scope `client.write`.
     |
     | Rencana awal menuliskan `srikandi.client.write` untuk heartbeat. Scope itu
     | sengaja tidak dibuat: heartbeat hanya menulis waktu, dan menambah scope
     | baru berarti ada operator yang bisa salah memberikan akses tulis ke
     | endpoint yang bisa mengubah data client.
     |
     | `credentials` memakai scope-nya sendiri karena isinya plaintext. Itu
     | satu-satunya endpoint di sistem yang melepas kredensial keluar database.
     |
     */

    Route::get('clients', [ClientController::class, 'index'])
        ->middleware('scope:srikandi.client.read')
        ->name('clients.index');

    Route::get('clients/{slug}/credentials', [ClientController::class, 'credentials'])
        ->middleware('scope:srikandi.client.credentials')
        ->name('clients.credentials');

    // 🔴 Heartbeat pakai `naskah.write`, bukan scope baru. Alasannya: isinya
    // laporan hasil scraping -- waktu login, waktu naskah masuk, dan error.
    // Token yang boleh mengirim naskah sudah pasti boleh melaporkan bahwa
    // dia berhasil mengirimnya. Scope terpisah di sini cuma menambah satu
    // permission yang harus diberikan satu per satu ke ke setiap token scraper.
    Route::post('clients/{slug}/heartbeat', [ClientController::class, 'heartbeat'])
        ->middleware('scope:srikandi.naskah.write')
        ->name('clients.heartbeat');
});
