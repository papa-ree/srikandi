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

        $this->info('bale/srikandi terpasang. 2 tabel siap dipakai.');

        $this->newLine();
        $this->line('Langkah berikutnya:');
        $this->line('  1. Buat token API scraper lewat UI Token dengan scope:');
        $this->line('     srikandi.otp.read, srikandi.otp.write, srikandi.naskah.write');
        $this->line('  2. Simpan token itu sebagai SRIKANDI_API_KEY di scraper.');
        $this->line('  3. Pastikan webhook GOWA mengarah ke /api/wara/webhook.');

        return self::SUCCESS;
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
