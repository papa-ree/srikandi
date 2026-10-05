<?php

namespace Bale\Srikandi;

use Bale\Srikandi\Commands\InstallCommand;
use Bale\Srikandi\Commands\PublishMigrationCommand;
use Bale\Srikandi\Listeners\HandleIncomingMessage;
use Bale\Wara\Events\WaraIncomingMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Component as LivewireComponent;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;

class SrikandiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/srikandi.php', 'srikandi');

        $this->commands([
            InstallCommand::class,
            PublishMigrationCommand::class,
        ]);
    }

    public function boot(): void
    {
        $this->registerApiScopes();
        $this->registerEventListeners();

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'srikandi');

        $this->registerLivewireComponents();

        $this->app->booted(function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        });

        $this->offerPublishing();
    }

    /**
     * Daftarkan komponen Livewire dari `src/Livewire` sebagai alias kebab-case.
     *
     * `src/Livewire/Pages/Landlord/Status/Index.php` menjadi
     * `srikandi.pages.landlord.status.index`, mengikuti konvensi `bale/wara`.
     */
    protected function registerLivewireComponents(): void
    {
        $basePath = __DIR__.'/Livewire';

        if (! is_dir($basePath)) {
            return;
        }

        $finder = new Finder;
        $finder->files()->in($basePath)->name('*.php');

        foreach ($finder as $file) {
            $relative = $file->getRelativePathname();
            $nsPath = str_replace(['/', '\\'], '\\', $relative);

            $class = 'Bale\\Srikandi\\Livewire\\'.Str::beforeLast($nsPath, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, LivewireComponent::class)) {
                continue;
            }

            $segments = preg_split('#[\\/\\\\]#', Str::replaceLast('.php', '', $relative));
            $kebab = array_map(fn ($s) => Str::kebab($s), $segments);

            Livewire::component('srikandi.'.implode('.', $kebab), $class);
        }
    }

    /**
     * Scope API untuk scraper (spec §7).
     *
     * Dipecah per operasi, bukan satu scope `srikandi.*` untuk semuanya, supaya
     * token bisa dibuat seminimal mungkin: proses yang hanya polling balasan
     * tidak perlu bisa membuat atau memverifikasi OTP.
     *
     * Autentikasi memakai `bale/api` (token Bearer + throttle + hardening),
     * bukan tabel API key terpisah. Jadi issue token scraper ditangani oleh UI
     * token yang sudah ada, termasuk masa berlaku dan pencabutan.
     */
    protected function registerApiScopes(): void
    {
        if (! function_exists('registerApiScopes')) {
            return;
        }

        registerApiScopes('srikandi', [
            'srikandi.otp.read' => 'Membaca balasan OTP untuk polling (GET /otp-pending).',
            'srikandi.otp.write' => 'Meminta dan memverifikasi OTP.',
            'srikandi.naskah.write' => 'Mengirim hasil pembacaan list naskah dinas.',
            'srikandi.client.read' => 'Membaca daftar client dan status readiness-nya (GET /clients).',
            // 🔴 Scope paling sensitif di daftar ini: isinya username, password,
            // TOTP secret, dan Gemini API key dalam bentuk PLAIN TEXT.
            //
            // Jangan pernah digabung dengan `srikandi.client.read`. Kalau
            // gabung, token yang hanya perlu `GET /clients` untuk tear-down
            // yang rapi ikut bisa mengambil semua kredensial client lain --
            // dan tidak ada jejak pembedaannya di log akses.
            'srikandi.client.credentials' => 'Mengambil kredensial login satu client (GET /clients/{slug}/credentials). Hanya untuk scraper yang sedang menjalankan siklus login.',
        ]);
    }

    /**
     * Listener balasan masuk (spec §6.1).
     *
     * Di-daftarkan sebagai listener sinkron (bukan queued). Pencocokan OTP
     * harus selesai sebelum scraper polling, dan menambahkannya ke antrean
     * hanya membuka jendela di mana scraper sudah membaca `pending` padahal
     * kodenya sudah terkirim.
     */
    protected function registerEventListeners(): void
    {
        Event::listen(
            WaraIncomingMessage::class,
            HandleIncomingMessage::class,
        );
    }

    protected function offerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/srikandi.php' => config_path('srikandi.php'),
        ], 'srikandi:config');

        $this->publishes($this->getMigrations(), 'srikandi:migrations');
    }

    protected function getMigrations(): array
    {
        $sourceDir = __DIR__.'/../database/migrations';

        if (! is_dir($sourceDir)) {
            return [];
        }

        $migrations = [];

        foreach (glob($sourceDir.'/*.php.stub') ?: [] as $file) {
            $name = basename($file, '.php.stub');

            $migrations[$file] = database_path(
                sprintf('migrations/%s_%s.php', date('Y_m_d_His'), $name)
            );
        }

        return $migrations;
    }
}
