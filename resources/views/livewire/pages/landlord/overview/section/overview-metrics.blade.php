{{--
    Empat kartu angka utama.

    Semua kartu dibangun secara inline (bukan x-core::stat-card) untuk mendukung
    ikon dengan gradient teal-emerald khas Bale.

    🔴 Kartu "Naskah Masuk" dan "Punya Berkas" mengikuti periode terpilih.
    Kartu "Masih Terlihat" TIDAK -- itu kondisi SEKARANG, bukan angka historic.
    Mencampur keduanya tanpa label periode akan membuat operator mengira angka
    itu juga ikut tersaring bulan.
--}}
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">

    {{-- Naskah Masuk --}}
    <div class="group relative overflow-hidden rounded-xl border border-gray-100 bg-white p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md dark:border-white/[0.06] dark:bg-gray-900">
        <div class="flex items-start justify-between">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-teal-500 to-teal-600 shadow-md shadow-teal-500/30 dark:shadow-teal-500/20">
                <x-lucide-files class="size-5 text-white" />
            </div>
            <span class="inline-flex items-center rounded-full bg-teal-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-teal-700 dark:bg-teal-900/30 dark:text-teal-300">
                {{ $bulanLabel }}
            </span>
        </div>
        <div class="mt-4">
            <div class="text-3xl font-bold text-gray-900 dark:text-white">{{ number_format($total) }}</div>
            <div class="mt-1 text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Naskah Masuk') }}</div>
            <div class="mt-0.5 text-[11px] text-gray-400 dark:text-gray-500">{{ __('Berdasarkan tanggal naskah') }}</div>
        </div>
        <div class="pointer-events-none absolute -bottom-4 -right-4 h-20 w-20 rounded-full bg-teal-500/5 transition-all duration-300 group-hover:scale-150"></div>
    </div>

    {{-- Client Menyetor --}}
    <div class="group relative overflow-hidden rounded-xl border border-gray-100 bg-white p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md dark:border-white/[0.06] dark:bg-gray-900">
        <div class="flex items-start justify-between">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-600 shadow-md shadow-emerald-500/30 dark:shadow-emerald-500/20">
                <x-lucide-user-check class="size-5 text-white" />
            </div>
            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">
                {{ __('Aktif') }}
            </span>
        </div>
        <div class="mt-4">
            <div class="text-3xl font-bold text-gray-900 dark:text-white">{{ number_format($menyetor) }}</div>
            <div class="mt-1 text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Client Menyetor') }}</div>
            <div class="mt-0.5 text-[11px] text-gray-400 dark:text-gray-500">
                {{ trans_choice('{0} tidak ada client aktif|{1} dari :count client aktif|[2,*] dari :count client aktif', $activeClients, ['count' => $activeClients]) }}
            </div>
        </div>
        <div class="pointer-events-none absolute -bottom-4 -right-4 h-20 w-20 rounded-full bg-emerald-500/5 transition-all duration-300 group-hover:scale-150"></div>
    </div>

    {{-- Masih Terlihat (realtime, bukan periode) --}}
    <div class="group relative overflow-hidden rounded-xl border border-gray-100 bg-white p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md dark:border-white/[0.06] dark:bg-gray-900">
        <div class="flex items-start justify-between">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-sky-500 to-sky-600 shadow-md shadow-sky-500/30 dark:shadow-sky-500/20">
                <x-lucide-eye class="size-5 text-white" />
            </div>
            {{-- Badge "Sekarang" karena ini BUKAN data historis --}}
            <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-sky-700 dark:bg-sky-900/30 dark:text-sky-300">
                <span class="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-sky-500"></span>
                {{ __('Live') }}
            </span>
        </div>
        <div class="mt-4">
            <div class="text-3xl font-bold text-gray-900 dark:text-white">{{ number_format($fresh) }}</div>
            <div class="mt-1 text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Masih Terlihat') }}</div>
            <div class="mt-0.5 text-[11px] text-gray-400 dark:text-gray-500">{{ __('Sekarang · terakhir :count menit', ['count' => $thresholdMinutes]) }}</div>
        </div>
        <div class="pointer-events-none absolute -bottom-4 -right-4 h-20 w-20 rounded-full bg-sky-500/5 transition-all duration-300 group-hover:scale-150"></div>
    </div>

    {{-- Punya Berkas --}}
    <div class="group relative overflow-hidden rounded-xl border border-gray-100 bg-white p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md dark:border-white/[0.06] dark:bg-gray-900">
        <div class="flex items-start justify-between">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-amber-500 to-amber-600 shadow-md shadow-amber-500/30 dark:shadow-amber-500/20">
                <x-lucide-paperclip class="size-5 text-white" />
            </div>
            <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                {{ $bulanLabel }}
            </span>
        </div>
        <div class="mt-4">
            <div class="text-3xl font-bold text-gray-900 dark:text-white">{{ number_format($withBerkas) }}</div>
            <div class="mt-1 text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Punya Berkas') }}</div>
            <div class="mt-0.5 text-[11px] text-gray-400 dark:text-gray-500">{{ __('Status berkas terisi') }}</div>
        </div>
        <div class="pointer-events-none absolute -bottom-4 -right-4 h-20 w-20 rounded-full bg-amber-500/5 transition-all duration-300 group-hover:scale-150"></div>
    </div>

</div>
