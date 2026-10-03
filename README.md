# bale/srikandi

Package Laravel untuk menyimpan data Srikandi di sisi Bale: status OTP dan
hasil pembacaan daftar naskah. Package ini menyediakan endpoint REST untuk
aplikasi scraper, tabel penyimpanan, dan satu halaman dashboard landlord.

## Kebutuhan

| Dependency | Alasan |
|---|---|
| PHP `^8.3` | Kebutuhan runtime |
| `bale/api` | Token API untuk autentikasi endpoint |
| `bale/wara` | Integrasi pesan WhatsApp |
| Laravel `^11 \|\| ^12 \|\| ^13` | Kompatibilitas lintas versi |

## Instalasi

Package ini adalah Composer *path repository* di dalam monorepo `bale-dev`,
bukan paket Packagist. Daftarkan dulu di `composer.json` aplikasi:

```json
"repositories": [
    { "type": "path", "url": "./packages/wara" },
    { "type": "path", "url": "./packages/srikandi" }
],
"require": {
    "bale/srikandi": "dev-master"
}
```

Lalu pasang:

```bash
composer install
php artisan srikandi:install
```

`srikandi:install` bersifat idempoten: ia men-seed permission, hanya
mempublish migration yang tabelnya belum ada, menjalankan `migrate`, lalu
memverifikasi tabelnya benar-benar ada. Opsi `--fresh` menjalankan
`migrate:fresh` lebih dulu dan meminta konfirmasi karena menghapus data.

Alternatif manual:

```bash
php artisan vendor:publish --tag="srikandi:migrations"
php artisan migrate
php artisan vendor:publish --tag="srikandi:config"
```

## Konfigurasi

`config/srikandi.php` di-merge di `register()`. Semua nilai punya default yang
aman, jadi package tetap boot tanpa `.env`.

| Env | Default | Fungsi |
|---|---|---|
| `SRIKANDI_OTP_TTL_MINUTES` | `5` | Masa berlaku OTP |
| `SRIKANDI_OTP_MAX_ATTEMPTS` | `5` | Batas percobaan verifikasi |
| `SRIKANDI_OTP_CODE_LENGTH` | `6` | Panjang kode OTP |
| `SRIKANDI_DEFAULT_PURPOSE` | `otp` | Tujuan default saat OTP diminta |
| `srikandi.naskah.unknown_year` | `0` | Nilai pengganti `tahun` yang tidak terbaca |

## Command

| Command | Fungsi |
|---|---|
| `srikandi:install` | Seed permission, publish migration yang kurang, migrate, verifikasi tabel |
| `srikandi:publish-migration` | Publish migration stub ke aplikasi |

## Testing

```bash
vendor\bin\pest packages\srikandi
```

## Credits

- [Papa Ree (Ricky R)](mailto:ricky.romdhoni@gmail.com) — Developer

## License

MIT.