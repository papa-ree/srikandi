{{--
    Baris tabel Naskah Keluar. READ-ONLY.

    🔴 Tidak ada `core-shared-components::item-actions` di sini, dan tidak ada
    `deleteEvent` di tabel. `srikandi_naskah_keluar` menyimpan salinan dokumen
    milik Srikandi; kalau Bale bisa menghapus atau mengubahnya, tabel ini
    berhenti bisa dipercaya sebagai catatan "apa yang pernah Bale lihat".

    🔴 Badge "Berkas" memakai `hasBerkas()` (berbasis `file_path`), BUKAN
    `status_berkas`: bentuk Naskah Keluar tidak punya status berkas di level
    baris (PRD §4.1c).
--}}
<tr wire:key="srikandi-naskah-keluar-row-{{ $record->getKey() }}"
    class="hover:bg-gray-50/80 dark:hover:bg-gray-800/50 transition-colors duration-150">

    {{-- Primary column: nomor naskah + tahun. --}}
    <td class="px-4 py-3.5 w-full max-w-0 sm:max-w-none sm:w-auto">
        <a href="{{ route('srikandi.naskah-keluar.show', $record->getKey()) }}"
            wire:navigate.hover
            class="font-mono text-sm text-gray-900 dark:text-gray-100 hover:text-indigo-600 dark:hover:text-indigo-400">
            {{ $record->nomor_naskah }}
        </a>
        <div class="text-xs text-gray-500 dark:text-gray-400">
            {{-- 🔴 Tahun `0` berarti "tahun tidak diketahui", bukan 0. --}}
            @if ((int) $record->tahun === 0)
                {{ __('tahun tidak diketahui') }}
            @else
                {{ $record->tahun }}
            @endif
        </div>
    </td>

    <td class="px-4 py-3.5 hidden lg:table-cell">
        <div class="text-sm text-gray-700 dark:text-gray-300 truncate max-w-xs">
            {{ $record->tujuan_naskah ?? '—' }}
        </div>
    </td>

    <td class="px-4 py-3.5">
        {{-- 🔴 Di-clamp, bukan dipotong. Kolom `hal` bebas berisi paragraf
             penuh; memotongnya diam-diam membuat dua naskah berbeda terlihat
             sama di tabel. --}}
        <div class="text-sm text-gray-700 dark:text-gray-300 line-clamp-2 max-w-md">
            {{ $record->hal ?? '—' }}
        </div>
    </td>

    <td class="px-4 py-3.5 whitespace-nowrap">
        @if ($record->hasBerkas())
            <x-core::badge color="indigo"
                :label="$record->file_name_original ?: basename((string) $record->file_path)" />
        @else
            <x-core::badge color="gray" :label="__('TIDAK ADA')" />
        @endif
    </td>

    <td class="px-4 py-3.5 hidden md:table-cell whitespace-nowrap">
        <span class="text-sm text-gray-700 dark:text-gray-300">
            {{ $record->tanggal_naskah?->format('d M Y') ?? '—' }}
        </span>
    </td>

    <td class="px-4 py-3.5 hidden sm:table-cell whitespace-nowrap">
        @php $tindak = trim((string) $record->status_tindak_lanjut); @endphp

        @if ($tindak === '')
            <span class="text-sm text-gray-400 dark:text-gray-500">—</span>
        @elseif (mb_strtoupper($tindak) === 'KIRIM' || mb_strtolower($tindak) === 'terkirim')
            <x-core::badge color="emerald" :label="$tindak" />
        @else
            <x-core::badge color="gray" :label="$tindak" />
        @endif
    </td>
</tr>
