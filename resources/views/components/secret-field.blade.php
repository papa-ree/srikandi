{{--
    Satu kolom secret: label, status "terisi", dan dua checkbox aksi.

    🔴 Kenapa "ganti" dan "hapus" harus dua checkbox terpisah.
    Input bertipe `password` di browser tidak pernah mengirim nilai lamanya, jadi
    nilai kosong berarti "tidak disentuh", bukan "dikosongkan". Kalau cuma ada
    satu kontrol, tidak ada cara untuk MENGHAPUS nilai yang tersimpan.

    Komponen ini dipakai enam kali di form client. Menulis ulang pola ini
    inline akan menghasilkan enam salinan yang pasti akan berbeda satu baris
    someday, dan yang berbeda itu adalah kolom yang tidak bisa dikosongkan.

    @param string      $name     property Livewire yang menerima nilai baru
    @param string      $label    teks label
    @param bool        $set      apakah ada nilai tersimpan
    @param string      $type     text|password
    @param string|null $hint     penjelasan tambahan
    @param bool        $showActions  tampilkan checkbox (hanya bermakna saat edit)
--}}
@props([
    'name',
    'label',
    'set' => false,
    'type' => 'password',
    'hint' => null,
    'showActions' => true,
])

<div class="rounded-lg border border-gray-200 bg-gray-50/60 p-3 dark:border-gray-700/60 dark:bg-gray-800/30">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <label for="secret-{{ $name }}" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
            {{ $label }}
        </label>

        @if ($set)
            <x-core::badge color="emerald" :label="__('terisi')" />
        @else
            <x-core::badge color="gray" :label="__('belum diisi')" />
        @endif
    </div>

    @if ($showActions && $set)
        <div class="mt-2 flex flex-wrap gap-4">
            <label class="inline-flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                <input type="checkbox"
                    wire:model="secretActions.{{ $name }}.replace"
                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900">
                <span>{{ __('Ganti nilai tersimpan') }}</span>
            </label>

            <label class="inline-flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                <input type="checkbox"
                    wire:model="secretActions.{{ $name }}.clear"
                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900">
                <span>{{ __('Hapus nilai tersimpan') }}</span>
            </label>
        </div>
    @endif

    <input id="secret-{{ $name }}"
        type="{{ $type }}"
        autocomplete="off"
        spellcheck="false"
        wire:model="{{ $name }}"
        @disabled($showActions && $set && ! ($secretActions[$name]['replace'] ?? false))
        placeholder="{{ $set ? __('— nilai lama tidak ditampilkan —') : __('kosongkan bila tidak dipakai') }}"
        class="mt-2 block w-full rounded-md border-gray-300 shadow-sm disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">

    @error($name)
        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror

    @if ($hint)
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
    @endif
</div>