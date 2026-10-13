<div class="space-y-6">

    {{-- ── Hero Header ── --}}
    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-teal-600 via-teal-700 to-emerald-800 p-6 text-white shadow-xl dark:from-teal-800 dark:via-teal-900 dark:to-emerald-950">
        {{-- Decorative blobs --}}
        <div class="pointer-events-none absolute -right-12 -top-12 h-48 w-48 rounded-full bg-white/10 blur-2xl"></div>
        <div class="pointer-events-none absolute -bottom-10 -left-10 h-40 w-40 rounded-full bg-emerald-400/20 blur-2xl"></div>
        <div class="pointer-events-none absolute right-24 bottom-0 h-24 w-24 rounded-full bg-teal-300/15 blur-xl"></div>

        <div class="relative flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
            {{-- Title block --}}
            <div class="flex items-center gap-4">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-white/15 shadow-inner backdrop-blur-sm ring-1 ring-white/20">
                    <x-lucide-gauge class="size-6 text-white" />
                </div>
                <div>
                    <h1 class="text-xl font-bold tracking-tight text-white sm:text-2xl">
                        {{ __('Overview Srikandi') }}
                    </h1>
                    <p class="mt-0.5 text-sm text-teal-100/80">
                        {{ __('Ringkasan ingest naskah dan kesehatan akun scraper') }}
                    </p>
                </div>
            </div>

            {{--
                🔴 `<select>` native, bukan dropdown kustom.

                Komponen ini harus bisa dirender server-side: label bulan yang
                sedang aktif ikut terkirim di HTML. Dropdown berbasis Alpine
                menaruh labelnya di `x-text`, jadi halaman yang di-render tidak
                memuat bulan terpilih sama sekali -- tidak untuk pembaca layar,
                tidak untuk test, dan tidak saat JS gagal dimuat.
            --}}
            <div class="w-full shrink-0 sm:w-56">
                <label for="overview-bulan" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-teal-100/70">
                    {{ __('Periode') }}
                </label>
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-teal-100/60">
                        <x-lucide-calendar-range class="size-4" />
                    </span>
                    <select id="overview-bulan"
                        wire:model.live="bulan"
                        class="block w-full appearance-none rounded-xl border border-white/20 bg-white/10 py-2.5 ps-9 pe-9 text-sm text-white backdrop-blur-sm transition focus:border-white/50 focus:outline-none focus:ring-2 focus:ring-white/30">
                        @foreach ($monthOptions as $value => $label)
                            <option value="{{ $value }}" class="bg-teal-800 text-white">{{ $label }}</option>
                        @endforeach
                    </select>
                    <span class="pointer-events-none absolute inset-y-0 end-0 flex items-center pe-3 text-teal-100/60">
                        <x-lucide-chevron-down class="size-4" />
                    </span>
                </div>
            </div>
        </div>
    </div>

    {{-- 🔴 `:key` memuat bulan: mengubah periode me-REMOUNT anak, bukan sekadar
         mengganti prop. Query-nya dijalankan ulang dari nol, jadi tidak ada
         angka/cache lama yang tertinggal satu render. --}}
    <livewire:srikandi.pages.landlord.overview.section.overview-metrics
        :bulan="$bulan"
        :key="'overview-metrics-'.$bulan" />

    <livewire:srikandi.pages.landlord.overview.section.overview-charts
        :bulan="$bulan"
        :key="'overview-charts-'.$bulan" />

    <livewire:srikandi.pages.landlord.overview.section.client-status-table
        :bulan="$bulan"
        :key="'overview-clients-'.$bulan" />
</div>
