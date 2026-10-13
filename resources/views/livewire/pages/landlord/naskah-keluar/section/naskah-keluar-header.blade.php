{{--
    Empat kartu ringkasan di atas tabel Naskah Keluar.

    🔴 Angka "basi" memakai scope `fresh()` / `notFresh()` milik model, yang
    batas menitnya sama persis dengan `isStale()`.

    🔴 Kartu "Ada Berkas" mengikuti `hasBerkas()` (berbasis `file_path`), BUKAN
    `status_berkas`: bentuk Naskah Keluar tidak punya status berkas di level
    baris (PRD §4.1c).
--}}
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <x-core::stat-card>
        <x-slot:icon>
            <x-lucide-send class="size-6 text-gray-500 dark:text-gray-400" />
        </x-slot:icon>

        <x-slot:title>
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ __('Total Naskah Keluar') }}
            </span>
        </x-slot:title>

        <x-slot:data>
            <div class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ number_format($total) }}
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                {{ trans_choice('{1} :count baris tersimpan|[2,*] :count baris tersimpan', $total, ['count' => $total]) }}
            </div>
        </x-slot:data>
    </x-core::stat-card>

    <x-core::stat-card>
        <x-slot:icon>
            <x-lucide-eye class="size-6 text-gray-500 dark:text-gray-400" />
        </x-slot:icon>

        <x-slot:title>
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ __('Masih Terlihat') }}
            </span>
        </x-slot:title>

        <x-slot:data>
            <div class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ number_format($fresh) }}
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                {{ __('Terakhir :count menit lalu', ['count' => $thresholdMinutes]) }}
            </div>
        </x-slot:data>
    </x-core::stat-card>

    <x-core::stat-card>
        <x-slot:icon>
            <x-lucide-archive class="size-6 text-gray-500 dark:text-gray-400" />
        </x-slot:icon>

        <x-slot:title>
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ __('Tidak Terlihat Lagi') }}
            </span>
        </x-slot:title>

        <x-slot:data>
            <div class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ number_format($stale) }}
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                {{ __('Masih di cache, tapi hilang dari list Srikandi') }}
            </div>
        </x-slot:data>
    </x-core::stat-card>

    <x-core::stat-card>
        <x-slot:icon>
            <x-lucide-paperclip class="size-6 text-gray-500 dark:text-gray-400" />
        </x-slot:icon>

        <x-slot:title>
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ __('Ada Berkas') }}
            </span>
        </x-slot:title>

        <x-slot:data>
            <div class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ number_format($withBerkas) }}
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                {{ __('Baris dengan lampiran (file_path)') }}
            </div>
        </x-slot:data>
    </x-core::stat-card>
</div>
