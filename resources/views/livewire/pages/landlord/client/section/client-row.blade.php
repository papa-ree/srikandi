<tr wire:key="srikandi-client-row-{{ $record->getKey() }}"
    class="hover:bg-gray-50/80 dark:hover:bg-gray-800/50 transition-colors duration-150">

    {{-- Primary column: nama + slug. `slug` adalah nilai `sumber` yang tertulis
    permanen di `srikandi_naskah`, jadi ditampilkan sebagai monospace:
    admin membacanya saat menelusuri asal sebuah naskah. --}}
    <td class="px-4 py-3.5 w-full max-w-0 sm:max-w-none sm:w-auto">
        <div class="font-medium text-sm text-gray-900 dark:text-gray-100">
            {{ $record->nama }}
        </div>
        <div class="text-xs font-mono text-gray-500 dark:text-gray-400">
            {{ $record->slug }}
        </div>
    </td>

    {{-- Device wara yang dipakai client ini. Menentukan OTP masuk ke akun mana,
    jadi kolom ini tidak disembunyikan di layar sempit. --}}
    <td class="px-4 py-3.5">
        @if ($record->waraClient === null)
            <span class="text-xs text-gray-400 dark:text-gray-500">
                {{ __('belum ditugaskan') }}
            </span>
        @else
            <span class="text-sm text-gray-700 dark:text-gray-300">
                {{ $record->waraClient->name }}
            </span>
        @endif
    </td>

    {{-- Kesiapan. Badge hijau/abu saja tidak menjawab pertanyaan admin
    ("kenapa request saya ditolak?"), jadi yang ditampilkan adalah
    ketersiapan untuk scraper. --}}
    <td class="px-4 py-3.5 hidden lg:table-cell">
        @if ($record->isReadyForScraper())
            <x-core::badge color="emerald" :label="__('siap dipakai scraper')" />
        @elseif (!$record->isActive())
            <x-core::badge color="gray" :label="__('nonaktif')" />
        @else
            <x-core::badge color="amber" :label="__('kredensial belum lengkap')" />
        @endif
    </td>

    <td class="px-4 py-3.5 hidden sm:table-cell whitespace-nowrap">
        {{-- 🔴 Badge "error terakhir" sengaja tidak ada di baris ini: `last_error_at`
        dibaca oleh scraper, bukan oleh admin, dan menampilkan timestamp error
        tanpa isi errornya hanya menghasilkan angka yang tidak bisa ditindaklanjuti. --}}
        @if ($record->isActive())
            <x-core::badge color="emerald" :label="__('aktif')" />
        @else
            <x-core::badge color="gray" :label="__('nonaktif')" />
        @endif
    </td>

    <td class="px-4 py-3.5 hidden xl:table-cell">
        <span class="text-sm text-gray-700 dark:text-gray-300">
            {{ $record->created_at?->format('d M Y H:i') }}
        </span>
    </td>

    {{-- 🔴 HANYA tombol edit. `deleteId` SENGAJA TIDAK DILEWATI di sini.
    Menghapus client akan meninggalkan naskah yang menunjuk slug yang sudah
    tidak ada, dan mencabut aksesnya cukup dengan mematikan `is_active`
    lewat form. --}}
    <td class="px-4 py-3.5 whitespace-nowrap w-px">
        @can('srikandi.client.update')
            <livewire:core.shared-components.item-actions :editUrl="route('srikandi.client.edit', $record->getKey())"
                :navigate="false" wire:key="srikandi-client-actions-{{ $record->getKey() }}" />
        @endcan
    </td>
</tr>