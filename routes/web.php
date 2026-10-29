<?php

use Bale\Srikandi\Http\Controllers\OverviewExportController;
use Bale\Srikandi\Livewire\Pages\Landlord\Client\Form as ClientForm;
use Bale\Srikandi\Livewire\Pages\Landlord\Client\Index as ClientIndex;
use Bale\Srikandi\Livewire\Pages\Landlord\Naskah\Detail as NaskahDetail;
use Bale\Srikandi\Livewire\Pages\Landlord\Naskah\Index as NaskahIndex;
use Bale\Srikandi\Livewire\Pages\Landlord\NaskahKeluar\Detail as NaskahKeluarDetail;
use Bale\Srikandi\Livewire\Pages\Landlord\NaskahKeluar\Index as NaskahKeluarIndex;
use Bale\Srikandi\Livewire\Pages\Landlord\Overview\Index as OverviewIndex;
use Bale\Srikandi\Livewire\Pages\Landlord\Sesi\Detail as SesiDetail;
use Bale\Srikandi\Livewire\Pages\Landlord\Sesi\Index as SesiIndex;
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
| scraper — device, kredensial, dan nomor akun — jadi ini operasi update
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
    /*
     * 🔴 Overview dijaga `srikandi.client.read`, sama seperti daftar client.
     *
     * Halaman ini pintu masuk grup Srikandi: ia menampilkan kesehatan akun
     * lebih dulu, baru statistik naskah. Operator yang mengelola akun tanpa
     * akses arsip naskah tetap harus bisa membukanya.
     */
    Route::get('/overview', OverviewIndex::class)
        ->name('overview')
        ->middleware('can:srikandi.client.read');

    /*
     * Export Overview: PDF atau XLSX untuk periode yang sama dengan halaman
     * (`?bulan=` atau `?dari=&sampai=`), bukan periode default.
     *
     * 🔴 Permission-nya `srikandi.client.read`, sama dengan halaman. Isinya
     * data yang sama; menambah permission baru berarti operator yang boleh
     * melihat halaman justru tidak boleh menyimpan hasilnya.
     */
    Route::get('/overview/export/{format}', OverviewExportController::class)
        ->whereIn('format', ['pdf', 'xlsx'])
        ->name('overview.export')
        ->middleware('can:srikandi.client.read');

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

    /*
     * 🔴 Naskah Keluar adalah HALAMAN dan TABEL terpisah dari Naskah Masuk,
     * dengan permission sendiri (`srikandi.naskah.keluar.read`).
     *
     * Sama seperti Naskah Masuk: tidak ada route form, hanya dua route baca.
     * `srikandi_naskah_keluar` menyimpan salinan dokumen milik Srikandi, jadi
     * Bale tidak boleh mengubah atau menghapusnya.
     */
    Route::get('/naskah-keluar', NaskahKeluarIndex::class)
        ->name('naskah-keluar.index')
        ->middleware('can:srikandi.naskah.keluar.read');

    Route::get('/naskah-keluar/{naskahKeluar}', NaskahKeluarDetail::class)
        ->name('naskah-keluar.show')
        ->middleware('can:srikandi.naskah.keluar.read');

    /*
     * 🔴 Log Sesi adalah HALAMAN dan TABEL terpisah lagi, dengan permission
     * sendiri (`srikandi.sesi.read`).
     *
     * Satu baris = satu SESI scraper (laporan diagnostik), bukan satu dokumen.
     * Read-only: tidak ada route form, hanya dua route baca. Sesi yang sama
     * bisa dikirim ulang scraper dan di-dedup lewat unique key, jadi tidak ada
     * yang boleh diubah manual dari sini.
     */
    Route::get('/sesi', SesiIndex::class)
        ->name('sesi.index')
        ->middleware('can:srikandi.sesi.read');

    Route::get('/sesi/{snapshot}', SesiDetail::class)
        ->name('sesi.show')
        ->middleware('can:srikandi.sesi.read');
});
