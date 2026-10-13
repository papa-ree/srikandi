{{--
    Card Naskah Keluar untuk layar sempit. `data-table` menyajikannya di bawah md.

    🔴 Tidak ada `item-actions` — halaman ini read-only.
--}}
<div wire:key="srikandi-naskah-keluar-card-{{ $record->getKey() }}"
    class="px-4 py-3.5">

    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <a href="{{ route('srikandi.naskah-keluar.show', $record->getKey()) }}"
                wire:navigate.hover
                class="font-mono text-sm text-gray-900 dark:text-gray-100 hover:text-indigo-600 dark:hover:text-indigo-400">
                {{ $record->nomor_naskah }}
            </a>

            <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                @if ((int) $record->tahun === 0)
                    {{ __('tahun tidak diketahui') }}
                @else
                    {{ $record->tahun }}
                @endif
            </div>
        </div>

        <div class="flex-none flex flex-wrap justify-end gap-1.5">
            @if ($record->hasBerkas())
                <x-core::badge color="indigo" :label="__('BERKAS')" />
            @else
                <x-core::badge color="gray" :label="__('TIDAK ADA')" />
            @endif

            @if ($record->isStale())
                <x-core::badge color="amber" :label="__('basi')" />
            @endif
        </div>
    </div>

    @if (filled($record->tujuan_naskah))
        <div class="mt-2 text-xs text-gray-600 dark:text-gray-400">
            {{ $record->tujuan_naskah }}
        </div>
    @endif

    @if (filled($record->hal))
        <div class="mt-1 text-sm text-gray-700 dark:text-gray-300 line-clamp-2">
            {{ $record->hal }}
        </div>
    @endif

    <div class="mt-2 flex items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
        <span>{{ $record->tanggal_naskah?->format('d M Y') ?? '—' }}</span>

        @if (filled($record->status_tindak_lanjut))
            <span>{{ $record->status_tindak_lanjut }}</span>
        @endif
    </div>
</div>
