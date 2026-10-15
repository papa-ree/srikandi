{{--
    Baris tabel Log Sesi. READ-ONLY.

    🔴 Tidak ada `core-shared-components::item-actions` di sini, dan tidak ada
    `deleteEvent` di tabel. Satu baris = laporan sesi scraper; kalau Bale bisa
    menghapusnya, log ini berhenti bisa dipercaya sebagai catatan "apa yang
    pernah dijalankan scraper".

    🔴 Badge status memakai `isFailed()`, bukan `$record->status === 'failed'`
    yang ditulis ulang. Definisi "gagal" harus satu sumber dengan kartu header.
--}}
<tr wire:key="srikandi-sesi-row-{{ $record->getKey() }}"
    class="hover:bg-gray-50/80 dark:hover:bg-gray-800/50 transition-colors duration-150">

    {{-- Primary column: waktu mulai + sumber. --}}
    <td class="px-4 py-3.5 w-full max-w-0 sm:max-w-none sm:w-auto">
        <a href="{{ route('srikandi.sesi.show', $record->getKey()) }}"
            wire:navigate.hover
            class="font-mono text-sm text-gray-900 dark:text-gray-100 hover:text-indigo-600 dark:hover:text-indigo-400">
            {{ $record->started_at?->format('d M Y H:i') ?? '—' }}
        </a>
        <div class="text-xs text-gray-500 dark:text-gray-400">
            {{ $record->sumber }}
        </div>
    </td>

    <td class="px-4 py-3.5 hidden sm:table-cell whitespace-nowrap">
        @if ($record->isFailed())
            <x-core::badge color="red" :label="__('Gagal')" />
        @else
            <x-core::badge color="emerald" :label="__('Sukses')" />
        @endif
    </td>

    <td class="px-4 py-3.5 hidden md:table-cell whitespace-nowrap">
        <span class="text-sm text-gray-700 dark:text-gray-300">
            {{ $record->finished_at?->format('d M Y H:i') ?? '—' }}
        </span>
        @if (filled($record->durationForHumans()))
            <div class="text-xs text-gray-400 dark:text-gray-500">
                {{ $record->durationForHumans() }}
            </div>
        @endif
    </td>

    <td class="px-4 py-3.5 hidden lg:table-cell">
        <span class="font-mono text-xs text-gray-700 dark:text-gray-300">
            {{ $record->error_code ?? '—' }}
        </span>
    </td>
</tr>
