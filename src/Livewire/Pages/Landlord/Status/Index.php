<?php

namespace Bale\Srikandi\Livewire\Pages\Landlord\Status;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Halaman status bale/srikandi: apa yang selesai, apa yang direncanakan.
 *
 * Sama seperti halaman status `bale/wara` — read-only, data dari
 * `src/status.php`.
 */
#[Layout('rakaca::layouts.app')]
#[Title('Status Srikandi')]
class Index extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('srikandi.status.read');
    }

    public function render()
    {
        // `dirname(__DIR__, 4)` naik dari `src/Livewire/Pages/Landlord/Status`
        // sampai ke `src/`. Lihat catatan yang sama di kelas status `bale/wara`.
        return view('srikandi::livewire.pages.landlord.status.index', [
            'status' => require dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'status.php',
        ]);
    }
}
