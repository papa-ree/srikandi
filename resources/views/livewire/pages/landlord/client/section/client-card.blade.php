{{--
Kartu client untuk layar sempit. Isinya harus sama dengan `client-row.blade.php`.
--}}
<div wire:key="srikandi-client-card-{{ $record->getKey() }}"
    class="relative overflow-hidden rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700/60 dark:bg-gray-900">

    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="font-medium text-sm text-gray-900 dark:text-gray-100">
                {{ $record->nama }}
            </div>
            <div class="text-xs font-mono text-gray-500 dark:text-gray-400">
                {{ $record->slug }}
            </div>
        </div>

        @if ($record->isActive())
            <x-core::badge color="emerald" :label="__('aktif')" />
        @else
            <x-core::badge color="gray" :label="__('nonaktif')" />
        @endif
    </div>

    <dl class="mt-3 space-y-1.5 text-xs">
        <div class="flex items-center justify-between gap-3">
            <dt class="text-gray-500 dark:text-gray-400">{{ __('Device Wara') }}</dt>
            <dd class="text-gray-900 dark:text-gray-100">
                {{ $record->waraClient?->name ?? __('belum ditugaskan') }}
            </dd>
        </div>

        <div class="flex items-center justify-between gap-3">
            <dt class="text-gray-500 dark:text-gray-400">{{ __('Kesiapan') }}</dt>
            <dd>
                @if ($record->isReadyForScraper())
                    <x-core::badge color="emerald" :label="__('siap')" />
                @elseif (!$record->isActive())
                    <x-core::badge color="gray" :label="__('nonaktif')" />
                @else
                    <x-core::badge color="amber" :label="__('belum lengkap')" />
                @endif
            </dd>
        </div>

        <div class="flex items-center justify-between gap-3">
            <dt class="text-gray-500 dark:text-gray-400">{{ __('Dibuat') }}</dt>
            <dd class="text-gray-900 dark:text-gray-100">
                {{ $record->created_at?->format('d M Y H:i') }}
            </dd>
        </div>
    </dl>

    {{-- 🔴 Hanya edit. Kartu ini tidak pernah punya tombol hapus. --}}
    @can('srikandi.client.update')
        <div class="mt-3 flex justify-end border-t border-gray-100 pt-3 dark:border-gray-700/60">
            <livewire:core.shared-components.item-actions :editUrl="route('srikandi.client.edit', $record->getKey())"
                :navigate="false" wire:key="srikandi-client-card-actions-{{ $record->getKey() }}" />
        </div>
    @endcan
</div>