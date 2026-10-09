<?php

use Bale\Srikandi\Livewire\Pages\Landlord\Client\Form as ClientForm;
use Bale\Srikandi\Livewire\Pages\Landlord\Client\Index as ClientIndex;
use Bale\Srikandi\Livewire\Pages\Landlord\Naskah\Detail as NaskahDetail;
use Bale\Srikandi\Livewire\Pages\Landlord\Naskah\Index as NaskahIndex;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Srikandi Web Routes (Landlord)
|--------------------------------------------------------------------------
|
| Halaman dashboard untuk landlord. Endpoint untuk scraper ada di
| `routes/api.php` dan tidak muncul di sini.
|
| 🔴 `create` memakai permission `update`, bukan `create`.
| Ada permissions `srikandi.client.create` dan `.delete`, tapi keduanya tidak
| dipakai di sini. Menambah client MEMUBAH client yang sudah ada dari sisi
| scraper — device, kredensial, dan nomor tujuan — jadi ini operasi update
| yang dibatasi pada "belum ada baris". `delete` tidak dipakai karena client
| tidak boleh dihapus sama sekali; mencabut akses dilakukan dengan
| `is_active = false` lewat form update.
|
| 🔴 Naskah tidak punya route form, hanya dua route baca.
| `srikandi_naskah` menyimpan salinan dokumen milik Srikandi. Kalau Bale bisa
| mengubah atau menghapus barisnya, tabel itu berhenti bisa dipercaya sebagai
| catatan "apa yang pernah Bale lihat".
|
*/

Route::middleware(['web', 'auth'])->prefix('srikandi')->name('srikandi.')->group(function () {
    Route::get('/client', ClientIndex::class)
        ->name('client.index')
        ->middleware('can:srikandi.client.read');

    Route::get('/client/create', ClientForm::class)
        ->name('client.create')
        ->middleware('can:srikandi.client.update');

    /*
     * 🔴 `/client/create` HARUS ditulis sebelum `/client/{client}/edit`.
     *
     * Karena segmen `{client}` menerima string apa saja, pola `/client/create` akan
     * tertangkap route edit lebih dulu dan `create` akan membuka form dengan
     * `$client` berisi teks "create" — errornya baru muncul sebagai "model not
     * found", jauh dari penyebabnya.
     */
    Route::get('/client/{client}/edit', ClientForm::class)
        ->name('client.edit')
        ->middleware('can:srikandi.client.update');

    Route::get('/naskah', NaskahIndex::class)
        ->name('naskah.index')
        ->middleware('can:srikandi.naskah.read');

    Route::get('/naskah/{naskah}', NaskahDetail::class)
        ->name('naskah.show')
        ->middleware('can:srikandi.naskah.read');
});
