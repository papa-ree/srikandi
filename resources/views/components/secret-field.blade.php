{{--
    Satu kolom secret: label, status "terisi", dan dua checkbox aksi.

    🔴 Kenapa "ganti" dan "hapus" harus dua checkbox terpisah.
    Input bertipe `password` di browser tidak pernah mengirim nilai lamanya, jadi
    nilai kosong berarti "tidak disentuh", bukan "dikosongkan". Kalau cuma ada
    satu kontrol, tidak ada cara untuk MENGHAPUS nilai yang tersimpan.

    Komponen ini dipakai beberapa kali di form client. Menulis ulang pola ini
    inline akan menghasilkan banyak salinan yang pasti akan berbeda satu baris
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
    'secretActions' => [],
])

@php
    $canReplace = $showActions && $set && ! ($secretActions[$name]['replace'] ?? false);
@endphp

<div class="rounded-xl border border-gray-200 bg-gray-50/60 p-4 dark:border-gray-700/60 dark:bg-gray-800/30">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <x-core::label :value="$label" :for="'secret-'.$name" class="mb-0" />

        @if ($set)
            <x-core::badge color="emerald" :label="__('terisi')" />
        @else
            <x-core::badge color="gray" :label="__('belum diisi')" />
        @endif
    </div>

    @if ($showActions && $set)
        <div class="mt-3 flex flex-wrap gap-4">
            <label class="inline-flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                <x-core::checkbox wire:model.live="secretActions.{{ $name }}.replace" />
                <span>{{ __('Ganti nilai tersimpan') }}</span>
            </label>

            <label class="inline-flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                <x-core::checkbox wire:model.live="secretActions.{{ $name }}.clear" />
                <span>{{ __('Hapus nilai tersimpan') }}</span>
            </label>
        </div>
    @endif

    <div class="mt-3">
        <x-core::input id="secret-{{ $name }}"
            type="{{ $type }}"
            autocomplete="off"
            spellcheck="false"
            wire:model="{{ $name }}"
            :disabled="$canReplace"
            placeholder="{{ $set ? __('— nilai lama tidak ditampilkan —') : __('kosongkan bila tidak dipakai') }}" />
    </div>

    <x-core::input-error for="{{ $name }}" class="mt-1" />

    @if ($hint)
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
    @endif
</div>
