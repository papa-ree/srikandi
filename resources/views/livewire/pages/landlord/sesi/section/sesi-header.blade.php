{{--
    Empat kartu ringkasan di atas tabel Log Sesi.

    🔴 "Gagal" memakai scope `failed()` milik model, yang definisinya sama
    dengan badge di baris. Definisi yang berbeda akan membuat angka kartu dan
    jumlah baris merah tidak cocok.

    🔴 "Sesi Terakhir" membaca `finished_at`, bukan `started_at`: yang dicari
    operator adalah sesi yang paling baru SELESAI.
--}}
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <x-core::stat-card>
        <x-slot:icon>
            <x-lucide-activity class="size-6 text-gray-500 dark:text-gray-400" />
        </x-slot:icon>

        <x-slot:title>
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ __('Total Sesi') }}
            </span>
        </x-slot:title>

        <x-slot:data>
            <div class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ number_format($total) }}
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                {{ trans_choice('{1} :count sesi tersimpan|[2,*] :count sesi tersimpan', $total, ['count' => $total]) }}
            </div>
        </x-slot:data>
    </x-core::stat-card>

    <x-core::stat-card>
        <x-slot:icon>
            <x-lucide-check-circle-2 class="size-6 text-emerald-500 dark:text-emerald-400" />
        </x-slot:icon>

        <x-slot:title>
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ __('Sukses') }}
            </span>
        </x-slot:title>

        <x-slot:data>
            <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                {{ number_format($success) }}
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                {{ __('status: success') }}
            </div>
        </x-slot:data>
    </x-core::stat-card>

    <x-core::stat-card>
        <x-slot:icon>
            <x-lucide-alert-triangle class="size-6 text-red-500 dark:text-red-400" />
        </x-slot:icon>

        <x-slot:title>
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ __('Gagal') }}
            </span>
        </x-slot:title>

        <x-slot:data>
            <div class="text-2xl font-bold text-red-600 dark:text-red-400">
                {{ number_format($failed) }}
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                {{ __('status: failed') }}
            </div>
        </x-slot:data>
    </x-core::stat-card>

    <x-core::stat-card>
        <x-slot:icon>
            <x-lucide-clock class="size-6 text-gray-500 dark:text-gray-400" />
        </x-slot:icon>

        <x-slot:title>
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ __('Sesi Terakhir') }}
            </span>
        </x-slot:title>

        <x-slot:data>
            @if ($last === null)
                <div class="text-2xl font-bold text-gray-400 dark:text-gray-500">
                    &mdash;
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Belum ada sesi.') }}
                </div>
            @else
                <div class="text-sm font-bold text-gray-900 dark:text-white">
                    {{ $last->finished_at?->format('d M Y H:i') ?? '—' }}
                </div>
                <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    {{ $last->sumber }}
                </div>
            @endif
        </x-slot:data>
    </x-core::stat-card>
</div>
