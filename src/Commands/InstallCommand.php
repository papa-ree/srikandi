<?php

namespace Bale\Srikandi\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

/**
 * Install bale/srikandi: seed permission, publish migration, jalankan migrate.
 *
 * Idempoten: publish migration melewati yang sudah ada, `migrate` melewati yang
 * sudah dijalankan, dan pengecekan tabel melompati tabel yang sudah ada.
 */
class InstallCommand extends Command
{
    protected $signature = 'srikandi:install {--fresh : Jalankan migrate:fresh sebelum publish}';

    protected $description = 'Install bale/srikandi: seed permission, publish migration, lalu jalankan';

    /**
     * Tabel yang harus ada setelah install.
     *
     * @var list<string>
     */
    protected array $tables = [
        'srikandi_otp_states',
        'srikandi_naskah',
    ];

    /**
     * Client Wara yang dipegang `bale/srikandi`.
     *
     * 🔴 Satu konstanta ini dipakai oleh `InstallCommand` dan migration
     * `move_otp_route_to_srikandi_client`. Kalau keduanya punya string sendiri
     * dan salah ketik, migrasi akan membuat client kedua yang namanya
     * berbeda - dan `OtpService` akan mencari yang kosong.
     */
    public const WARA_CLIENT_NAME = 'Srikandi (Package)';

    public function handle(): int
    {
        $this->info('Install bale/srikandi');

        $this->seedPermissions();

        $this->newLine();

        if ($this->option('fresh')) {
            if (! $this->confirmToProceed()) {
                return self::FAILURE;
            }

            $this->call('migrate:fresh');
            $this->newLine();
        }

        $missing = $this->missingTables();

        if ($missing === []) {
            $this->info('Semua tabel sudah ada. Tidak ada yang perlu diubah.');

            // 🔴 Client dibuat DI SINI juga, bukan hanya di jalur migrate.
            // Jalur "semua tabel sudah ada" adalah jalur yang paling sering
            // dipakai (install kedua), jadi kalau client tidak dibuat di sini,
            // skenario yang paling umum justru tidak pernah membuatnya.
            $this->ensureWaraClient();

            $this->newLine();
            $this->line('Langkah berikutnya: petakan purpose ke device di menu Wara > Client.');

            return self::SUCCESS;
        }

        $this->line('Tabel yang belum ada:');
        foreach ($missing as $table) {
            $this->line('  - '.$table);
        }

        $this->newLine();

        if ($this->call('srikandi:publish-migration') !== self::SUCCESS) {
            $this->error('Publish migration gagal.');

            return self::FAILURE;
        }

        $this->newLine();

        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            $this->error('Migration gagal dijalankan.');

            return self::FAILURE;
        }

        $this->newLine();

        $stillMissing = $this->missingTables();

        if ($stillMissing !== []) {
            $this->error('Tabel masih belum ada: '.implode(', ', $stillMissing));

            return self::FAILURE;
        }

        // Client Wara dibuat SETELAH migrate, bukan sebelumnya: `wara_clients`
        // belum ada sebelum tabelnya dibuat, dan menulis ke tabel yang belum
        // ada akan gagal dengan error yang tidak menjelaskan apa pun.
        $this->ensureWaraClient();

        $this->info('bale/srikandi terpasang. 2 tabel siap dipakai.');

        $this->newLine();
        $this->line('Langkah berikutnya:');
        $this->line('  1. Petakan purpose ke device di menu Wara > Client:');
        $this->line('     client "Srikandi (Package)" butuh route "otp".');
        $this->line('  2. Jalankan php artisan wara:sync-devices lebih dulu supaya');
        $this->line('     device yang sudah pairing muncul sebagai pilihan.');
        $this->line('  3. Buat token API scraper lewat UI Token dengan scope:');
        $this->line('     srikandi.otp.read, srikandi.otp.write, srikandi.naskah.write');
        $this->line('  4. Simpan token itu sebagai SRIKANDI_API_KEY di scraper.');
        $this->line('  5. Pastikan webhook GOWA mengarah ke /api/wara/webhook.');

        return self::SUCCESS;
    }

    /**
     * Pastikan client Wara milik `bale/srikandi` ada.
     *
     * 🔴 Route SENGAJA TIDAK diisi di sini, dan itu keputusan yang disepakati.
     *
     * Install tidak tahu device mana yang benar untuk Srikandi. Kalau
     * device_id ditebak dari "device siap pertama", pemetaan itu bisa salah
     * dan pesanan akan keluar dari nomor yang tidak diminta siapa pun - lebih
     * buruk daripada OTP gagal dengan pesan yang menyebut perbaikannya.
     *
     * Karena itu client dibuat kosong, dan admin yang memetakan device-nya
     * sendiri di `Wara > Client`.
     *
     * 🔴 `wara_clients` belum ada = `bale/wara` belum di-install. Command ini
     * memberi warning dan Lanjut, bukan gagal: urutan install dua package tidak
     * dijamin, dan `bale/srikandi` tetap berfungsi tanpa client Wara (hanya
     * saja jalur OTP yang belum bisa dipakai).
     */
    protected function ensureWaraClient(): void
    {
        if (! Schema::hasTable('wara_clients')) {
            $this->warn('Tabel wara_clients belum ada — bale/wara belum di-install.');
            $this->warn('Jalankan: php artisan wara:install');

            return;
        }

        $class = 'Bale\\Wara\\Models\\WaraClient';

        if (! class_exists($class)) {
            $this->warn('Kelas WaraClient tidak ditemukan — package bale/wara tidak aktif.');
            $this->warn('Jalankan: php artisan wara:install');

            return;
        }

        // Class-string, bukan `use` statis: `bale/api`/`bale/wara` tidak selalu
        // terpasang, dan mengimpor kelas yang tidak ada akan fatal saat autoload
        // - bukan saat command ini dijalankan.
        $model = new $class;

        $client = $model::query()->firstOrCreate(
            ['name' => self::WARA_CLIENT_NAME],
            [
                'type' => 'service',
                'bale_id' => null,
                'api_token_id' => null,
                'name' => self::WARA_CLIENT_NAME,
                'is_active' => true,
            ]
        );

        $this->line('  + Client Wara: '.$client->name);
    }

    /**
     * Seed permission dashboard Srikandi.
     *
     * Hanya satu permission: halaman status. Endpoint scraper tidak punya
     * permission — dikunci token Bearer + scope `bale/api`, bukan permission
     * Spatie yang menempel pada user.
     */
    protected function seedPermissions(): void
    {
        $permissions = [
            'srikandi.status.read' => 'Membaca halaman status bale/srikandi. Halaman ini read-only.',
        ];

        $model = config('permission.models.permission', Permission::class);

        foreach ($permissions as $name => $label) {
            $model::firstOrCreate(['name' => $name], ['guard_name' => 'web']);

            $this->line(sprintf('  + Permission: %s', $name));
        }
    }

    /**
     * @return list<string>
     */
    protected function missingTables(): array
    {
        return array_values(array_filter(
            $this->tables,
            fn (string $table) => ! Schema::hasTable($table)
        ));
    }

    protected function confirmToProceed(): bool
    {
        if (! $this->option('fresh')) {
            return true;
        }

        $this->warn('migrate:fresh akan MENGHAPUS seluruh tabel aplikasi.');

        return $this->confirm('Lanjutkan?');
    }
}
