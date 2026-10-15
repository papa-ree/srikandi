{{--
    Card Log Sesi untuk layar sempit. `data-table` menyajikannya di bawah md.

    🔴 Tidak ada `item-actions` — halaman ini read-only.
--}}
<div wire:key="srikandi-sesi-card-{{ $record->getKey() }}"
    class="px-4 py-3.5">

    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <a href="{{ route('srikandi.sesi.show', $record->getKey()) }}"
                wire:navigate.hover
                class="font-mono text-sm text-gray-900 dark:text-gray-100 hover:text-indigo-600 dark:hover:text-indigo-400">
                {{ $record->started_at?->format('d M Y H:i') ?? '—' }}
            </a>

            <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                {{ $record->sumber }}
            </div>
        </div>

        <div class="flex-none">
            @if ($record->isFailed())
                <x-core::badge color="red" :label="__('Gagal')" />
            @else
                <x-core::badge color="emerald" :label="__('Sukses')" />
            @endif
        </div>
    </div>

    <div class="mt-2 flex items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
        <span>{{ __('Selesai: :waktu', ['waktu' => $record->finished_at?->format('d M Y H:i') ?? '—']) }}</span>

        @if (filled($record->durationForHumans()))
            <span>{{ $record->durationForHumans() }}</span>
        @endif
    </div>

    @if (filled($record->error_code))
        <div class="mt-1 font-mono text-xs text-red-600 dark:text-red-400">
            {{ $record->error_code }}
        </div>
    @endif
</div>
