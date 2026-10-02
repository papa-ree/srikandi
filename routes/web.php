<?php

use Bale\Srikandi\Livewire\Pages\Landlord\Status\Index as StatusIndex;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Srikandi Web Routes (Landlord)
|--------------------------------------------------------------------------
|
| Halaman dashboard untuk landlord. Endpoint untuk scraper ada di
| `routes/api.php` dan tidak muncul di sini.
|
*/

Route::middleware(['web', 'auth'])->prefix('srikandi')->name('srikandi.')->group(function () {
    Route::get('/status', StatusIndex::class)
        ->name('status.index')
        ->middleware('can:srikandi.status.read');
});
