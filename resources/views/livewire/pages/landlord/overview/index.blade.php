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

            <div class="w-full shrink-0 space-y-3 sm:w-80">
                {{--
                    Mode periode: Bulanan (default, `?bulan=`) atau Rentang
                    (`?dari=&sampai=`). Bulan tetap default supaya tautan lama
                    tidak berubah arti.
                --}}
                <div class="flex rounded-xl bg-white/10 p-1 ring-1 ring-white/20 backdrop-blur-sm">
                    <button type="button" wire:click="setMode('bulan')"
                        class="flex-1 rounded-lg px-3 py-1.5 text-xs font-semibold transition {{ $mode === 'bulan' ? 'bg-white text-teal-800 shadow-sm' : 'text-teal-50 hover:bg-white/10' }}">
                        {{ __('Bulanan') }}
                    </button>
                    <button type="button" wire:click="setMode('rentang')"
                        class="flex-1 rounded-lg px-3 py-1.5 text-xs font-semibold transition {{ $mode === 'rentang' ? 'bg-white text-teal-800 shadow-sm' : 'text-teal-50 hover:bg-white/10' }}">
                        {{ __('Rentang') }}
                    </button>
                </div>

                @if ($mode === 'bulan')
                    {{--
                        🔴 Dua `<select>` native (bulan + tahun), bukan dropdown
                        kustom.

                        Komponen ini harus bisa dirender server-side: bulan dan
                        tahun yang aktif ikut terkirim di HTML. Dropdown berbasis
                        Alpine menaruh labelnya di `x-text`, jadi halaman yang
                        di-render tidak memuat periode terpilih sama sekali --
                        tidak untuk pembaca layar, tidak untuk test, dan tidak
                        saat JS gagal dimuat.

                        Nilai tetap disimpan sebagai `$bulan` (`Y-m`) supaya URL
                        dan section tidak tahu ada dua kontrol di sini.
                    --}}
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label for="overview-bulan" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-teal-100/70">
                                {{ __('Bulan') }}
                            </label>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-teal-100/60">
                                    <x-lucide-calendar-range class="size-4" />
                                </span>
                                <select id="overview-bulan"
                                    wire:change="setBulanBulan($event.target.value)"
                                    class="block w-full appearance-none rounded-xl border border-white/20 bg-white/10 py-2.5 ps-9 pe-9 text-sm text-white backdrop-blur-sm transition focus:border-white/50 focus:outline-none focus:ring-2 focus:ring-white/30">
                                    @foreach ($namaBulan as $index => $nama)
                                        <option value="{{ $index + 1 }}" @selected((int) substr($bulan, 5, 2) === $index + 1) class="bg-teal-800 text-white">
                                            {{ $nama }}
                                        </option>
                                    @endforeach
                                </select>
                                <span class="pointer-events-none absolute inset-y-0 end-0 flex items-center pe-3 text-teal-100/60">
                                    <x-lucide-chevron-down class="size-4" />
                                </span>
                            </div>
                        </div>

                        <div>
                            <label for="overview-tahun" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-teal-100/70">
                                {{ __('Tahun') }}
                            </label>
                            <div class="relative">
                                <select id="overview-tahun"
                                    wire:change="setBulanTahun($event.target.value)"
                                    class="block w-full appearance-none rounded-xl border border-white/20 bg-white/10 py-2.5 ps-4 pe-9 text-sm text-white backdrop-blur-sm transition focus:border-white/50 focus:outline-none focus:ring-2 focus:ring-white/30">
                                    @foreach ($tahunOptions as $tahun)
                                        <option value="{{ $tahun }}" @selected(substr($bulan, 0, 4) === $tahun) class="bg-teal-800 text-white">
                                            {{ $tahun }}
                                        </option>
                                    @endforeach
                                </select>
                                <span class="pointer-events-none absolute inset-y-0 end-0 flex items-center pe-3 text-teal-100/60">
                                    <x-lucide-chevron-down class="size-4" />
                                </span>
                            </div>
                        </div>
                    </div>
                @else
                    {{-- 🔴 Key pada pembungkus: seluruh subtree (termasuk picker
                         ber-`wire:ignore`) dibuang saat kembali ke Bulanan, tidak
                         hanya disembunyikan. --}}
                    <div wire:key="overview-rentang" class="grid grid-cols-1 gap-2">
                        <x-core::date-picker model="dari" mode="date" live
                            aria-label="{{ __('Dari tanggal') }}" placeholder="{{ __('Dari tanggal') }}" />
                        {{--
                            🔴 `wire:key` memuat `$dari`: picker "sampai" di-remount
                            setiap tanggal awal berubah, karena batas kalendernya
                            (min = dari, max = dari + 89 hari) dikirim server dan
                            picker berada di dalam `wire:ignore`.
                        --}}
                        <div wire:key="overview-sampai-{{ $dari }}">
                            <x-core::date-picker model="sampai" mode="date" live
                                :minDate="$dari ?: null"
                                :maxDate="$sampaiMaxDate"
                                aria-label="{{ __('Sampai tanggal') }}" placeholder="{{ __('Sampai tanggal') }}" />
                        </div>
                    </div>
                    <p class="text-[11px] text-teal-100/60">
                        {{ __('Rentang maksimal :days hari, dihitung inklusif.', ['days' => 90]) }}
                    </p>
                @endif

                @if ($periodError)
                    <p class="rounded-lg bg-amber-400/15 px-3 py-2 text-xs text-amber-50 ring-1 ring-amber-300/30">
                        {{ $periodError }}
                    </p>
                @endif

                {{-- Export membawa periode yang sedang aktif, bukan bulan default. --}}
                <div class="flex items-center gap-2">
                    <a href="{{ route('srikandi.overview.export', ['format' => 'pdf'] + $exportQuery) }}"
                        class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-white/10 px-3 py-2 text-xs font-semibold text-white ring-1 ring-white/20 transition hover:bg-white/20">
                        <x-lucide-file-text class="size-3.5" />
                        {{ __('PDF') }}
                    </a>
                    <a href="{{ route('srikandi.overview.export', ['format' => 'xlsx'] + $exportQuery) }}"
                        class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-white/10 px-3 py-2 text-xs font-semibold text-white ring-1 ring-white/20 transition hover:bg-white/20">
                        <x-lucide-file-spreadsheet class="size-3.5" />
                        {{ __('XLSX') }}
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- 🔴 `:key` memuat PERIODE (bulan atau rentang): mengubah periode
         me-REMOUNT anak, bukan sekadar mengganti prop. Query-nya dijalankan
         ulang dari nol, jadi tidak ada angka/cache lama yang tertinggal satu
         render. --}}
    <livewire:srikandi.pages.landlord.overview.section.overview-metrics
        :bulan="$bulan"
        :dari="$dari"
        :sampai="$sampai"
        :key="'overview-metrics-'.$periodKey" />

    <livewire:srikandi.pages.landlord.overview.section.overview-charts
        :bulan="$bulan"
        :dari="$dari"
        :sampai="$sampai"
        :key="'overview-charts-'.$periodKey" />

    <livewire:srikandi.pages.landlord.overview.section.client-status-table
        :bulan="$bulan"
        :dari="$dari"
        :sampai="$sampai"
        :key="'overview-clients-'.$periodKey" />
</div>
