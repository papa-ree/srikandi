<?php

namespace Bale\Srikandi\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Publish migration stub `.php.stub` ke `database/migrations` aplikasi.
 *
 * Mirip `wara:publish-migration`: prefix timestamp menjaga urutan eksekusi,
 * dan deteksi "sudah ada" memakai nama migration TANPA timestamp supaya command
 * ini aman dijalankan berulang kali.
 */
class PublishMigrationCommand extends Command
{
    protected $signature = 'srikandi:publish-migration';

    protected $description = 'Publish migration stub bale/srikandi ke database/migrations aplikasi';

    public function handle(): int
    {
        $sourceDir = __DIR__.'/../../database/migrations';

        if (! is_dir($sourceDir)) {
            $this->error('Direktori migration package tidak ditemukan: '.$sourceDir);

            return self::FAILURE;
        }

        $published = $skipped = 0;

        foreach (File::files($sourceDir) as $file) {
            $filename = $file->getFilename();

            if (! str_ends_with($filename, '.php.stub')) {
                continue;
            }

            $name = basename($filename, '.php.stub');
            $target = $this->findExisting($name);

            if ($target !== null) {
                $skipped++;
                $this->line(sprintf('  - Lewati (sudah ada): %s', basename($target)));

                continue;
            }

            $new = database_path(sprintf('migrations/%s_%s.php', date('Y_m_d_His'), $name));

            File::copy($file->getRealPath(), $new);
            $published++;
            $this->line(sprintf('  + Dipublish: %s', basename($new)));
        }

        $this->newLine();
        $this->info(sprintf('Selesai. Dipublish: %d, dilewati: %d.', $published, $skipped));

        return self::SUCCESS;
    }

    /**
     * Cari migration dengan nama yang sama, abaikan prefix timestamp.
     */
    protected function findExisting(string $name): ?string
    {
        $matches = glob(database_path('migrations/*_'.$name.'.php'));

        if (is_array($matches) && $matches !== []) {
            return $matches[0];
        }

        // Juga file tanpa timestamp, kalau pernah ada.
        $legacy = database_path('migrations/'.$name.'.php');

        return file_exists($legacy) ? $legacy : null;
    }
}
