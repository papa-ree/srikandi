<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Masa berlaku OTP
    |--------------------------------------------------------------------------
    |
    | Srikandi biasanya meminta kode yang berlaku beberapa menit. Nilai ini
    | Decide kapan `srikandi_otp_states` berpindah ke `expired`.
    |
    */

    'otp' => [
        'ttl_minutes' => (int) env('SRIKANDI_OTP_TTL_MINUTES', 5),

        /*
        | Batas percobaan verifikasi. Melampaui angka ini memindahkan `state`
        | ke `expired` — bukan `failed`, karena kegagalan captcha adalah
        | urusan scraper, bukan state yang perlu Bale simpan.
        */
        'max_attempts' => (int) env('SRIKANDI_OTP_MAX_ATTEMPTS', 5),

        /*
        | Panjang kode OTP. 6 digit adalah standar Srikandi; angka lain
        | ditolak karena `buildCode` hanya menghasilkan angka sepanjang ini.
        */
        'code_length' => (int) env('SRIKANDI_OTP_CODE_LENGTH', 6),

        /*
        | Alasan default saat scraper meminta OTP.
        */
        'default_purpose' => env('SRIKANDI_DEFAULT_PURPOSE', 'otp'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Naskah dinas
    |--------------------------------------------------------------------------
    |
    | `tahun` diambil dari `tanggal`, bukan dari nomor naskah. Nilai fallback
    | dipakai saat tanggal tidak terbaca — TIDAK boleh `null` karena kolom
    | ini bagian dari unique key dan NULL menggagalkan dedup di MySQL.
    |
    */

    'naskah' => [
        'unknown_year' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Blind index untuk kolom terenkripsi
    |--------------------------------------------------------------------------
    |
    | Dipakai `Bale\Srikandi\Support\BlindIndex` untuk menghitung `phone_index`
    | dan `notify_phone_index`, supaya nomor yang terenkripsi tetap bisa dicari
    | tanpa didekripsi.
    |
    | 🔴 WAJIB DIISI, DAN TIDAK BOLEH SAMA DENGAN `APP_KEY`.
    |
    | Nilai ini adalah kunci HMAC-SHA256, bukan kunci enkripsi. Kalau sama dengan
    | `APP_KEY`, siapa pun yang punya kunci enkripsi (mis. dari `.env` yang bocor)
    | bisa menghitung index sekaligus mencoba mendekripsi kolom -- dua mekanisme
    | yang melindungi data runtun jadi satu. Tidak ada fallback ke `APP_KEY`
    | sengaja: lebih baik error eksplisit daripada diam-diam memakai kunci sama.
    |
    | Mengganti nilai ini membuat seluruh `*_index` lama tidak berguna lagi.
    | Field yang terenkripsi tetap bisa dibaca, tapi pencarian by-nomor mati
    | sampai semua client disimpan ulang.
    |
    */

    'index_key' => env('SRIKANDI_INDEX_KEY'),

];
