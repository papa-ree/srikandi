@php
    // Palet untuk distribusi per CLIENT — urutan tetap supaya warna satu client
    // konsisten di kedua grafik (tidak bergeser saat client lain hilang).
    // Palet mengikuti identitas Bale: teal-emerald sebagai warna utama.
    $sumberPalette = ['#14B8A6', '#0EA5E9', '#8B5CF6', '#F59E0B', '#EF4444', '#EC4899', '#6366F1', '#10B981'];

    // Peta warna SEMANTIK untuk distribusi STATUS BACA.
    // Kata kunci dicocokkan dengan strtolower() supaya tidak case-sensitive.
    // Kunci: substring yang muncul di label; nilai: warna hex.
    $statusColorMap = [
        'dibaca'  => '#4ADE80',   // green-400   — sudah dibaca ✅
        'belum'   => '#38BDF8',   // sky-400     — belum dibaca 🔵
        'tidak'   => '#C084FC',   // purple-400  — tidak diketahui / null
        'unknown' => '#C084FC',
    ];

    // Bangun array warna sesuai urutan $statusLabels.
    $statusColors = collect($statusLabels)->map(function (string $label) use ($statusColorMap): string {
        $lower = strtolower($label);
        foreach ($statusColorMap as $keyword => $color) {
            if (str_contains($lower, $keyword)) {
                return $color;
            }
        }
        return '#CBD5E1'; // slate-300 fallback
    })->values()->all();

    // Peta warna SEMANTIK untuk distribusi STATUS TINDAK LANJUT (Naskah Keluar).
    // Bentuk aslinya dari Srikandi: "KIRIM"/"BELUM"; nilai lain jatuh ke slate.
    $tindakColorMap = [
        'kirim'   => '#4ADE80',   // green-400  — sudah ditindaklanjuti
        'sudah'   => '#4ADE80',
        'selesai' => '#4ADE80',
        'belum'   => '#FBBF24',   // amber-400  — menunggu tindak lanjut
        'tidak'   => '#C084FC',   // purple-400 — tidak diketahui / null
        'unknown' => '#C084FC',
    ];

    $keluarStatusColors = collect($keluarStatusLabels)->map(function (string $label) use ($tindakColorMap): string {
        $lower = strtolower($label);
        foreach ($tindakColorMap as $keyword => $color) {
            if (str_contains($lower, $keyword)) {
                return $color;
            }
        }
        return '#CBD5E1'; // slate-300 fallback
    })->values()->all();
@endphp

<div class="grid grid-cols-1 gap-4 lg:grid-cols-3">

    {{-- ── Tren harian masuk vs keluar (full width) ── --}}
    <div class="rounded-xl border border-gray-100 bg-white shadow-sm dark:border-white/[0.06] dark:bg-gray-900 lg:col-span-3">
        {{-- Card header --}}
        <div class="flex items-center gap-3 border-b border-gray-100 px-5 py-4 dark:border-white/[0.06]">
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-teal-500 to-teal-600 shadow-md shadow-teal-500/25">
                <x-lucide-trending-up class="size-4 text-white" />
            </div>
            <div class="min-w-0">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('Tren Naskah') }}
                </h2>
                <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Naskah masuk & keluar per tanggal naskah · :bulan', ['bulan' => $bulanLabel]) }}
                </p>
            </div>
        </div>

        <div class="p-4">
            @if ($totalPeriode > 0 || $totalKeluarPeriode > 0)
                <x-core::chart
                    type="line"
                    :labels="$trendLabels"
                    :datasets="[
                        [
                            'label' => __('Naskah Masuk'),
                            'data' => $trendData,
                            'borderColor' => '#14B8A6',
                            'backgroundColor' => 'rgba(20,184,166,0.10)',
                            'borderWidth' => 2,
                            'pointRadius' => 0,
                            'pointHoverRadius' => 4,
                            'fill' => true,
                            'tension' => 0.35,
                        ],
                        [
                            'label' => __('Naskah Keluar'),
                            'data' => $keluarTrendData,
                            'borderColor' => '#F43F5E',
                            'backgroundColor' => 'rgba(244,63,94,0.08)',
                            'borderWidth' => 2,
                            'pointRadius' => 0,
                            'pointHoverRadius' => 4,
                            'fill' => true,
                            'tension' => 0.35,
                        ],
                    ]"
                    :options="[
                        'interaction' => ['mode' => 'index', 'intersect' => false],
                        'plugins' => ['legend' => ['display' => true, 'position' => 'bottom', 'labels' => ['boxWidth' => 10, 'font' => ['size' => 11]]]],
                        'scales' => [
                            'x' => ['ticks' => ['maxTicksLimit' => 10, 'font' => ['size' => 10]]],
                            'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0, 'font' => ['size' => 10]]],
                        ],
                    ]" />
            @else
                <div class="flex h-64 flex-col items-center justify-center text-gray-400 dark:text-gray-500">
                    <x-lucide-inbox class="mb-3 size-10 opacity-40" />
                    <p class="text-sm italic">{{ __('Belum ada naskah pada periode ini.') }}</p>
                </div>
            @endif
        </div>
    </div>

    {{-- ── Distribusi per client ── --}}
    <div class="rounded-xl border border-gray-100 bg-white shadow-sm dark:border-white/[0.06] dark:bg-gray-900">
        <div class="flex items-center gap-3 border-b border-gray-100 px-5 py-4 dark:border-white/[0.06]">
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-600 shadow-md shadow-emerald-500/25">
                <x-lucide-users class="size-4 text-white" />
            </div>
            <div class="min-w-0">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('Distribusi per Client') }}
                </h2>
                <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Naskah per sumber · :bulan', ['bulan' => $bulanLabel]) }}
                </p>
            </div>
        </div>

        <div class="p-4">
            @if (count($sumberData) > 0)
                <x-core::chart
                    type="doughnut"
                    :labels="$sumberLabels"
                    :datasets="[
                        [
                            'data' => $sumberData,
                            'backgroundColor' => array_slice($sumberPalette, 0, count($sumberData)),
                            'borderWidth' => 0,
                        ],
                    ]" />
            @else
                <div class="flex h-64 flex-col items-center justify-center text-gray-400 dark:text-gray-500">
                    <x-lucide-pie-chart class="mb-3 size-10 opacity-40" />
                    <p class="text-sm italic">{{ __('Belum ada data.') }}</p>
                </div>
            @endif
        </div>
    </div>

    {{-- ── Distribusi status baca ── --}}
    <div class="rounded-xl border border-gray-100 bg-white shadow-sm dark:border-white/[0.06] dark:bg-gray-900">
        <div class="flex items-center gap-3 border-b border-gray-100 px-5 py-4 dark:border-white/[0.06]">
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-amber-500 to-amber-600 shadow-md shadow-amber-500/25">
                <x-lucide-mail-open class="size-4 text-white" />
            </div>
            <div class="min-w-0">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('Distribusi Status Baca') }}
                </h2>
                <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Status baca Srikandi · :bulan', ['bulan' => $bulanLabel]) }}
                </p>
            </div>
        </div>

        <div class="p-4">
            @if (count($statusData) > 0)
                <x-core::chart
                    type="doughnut"
                    :labels="$statusLabels"
                    :datasets="[
                        [
                            'data' => $statusData,
                            'backgroundColor' => $statusColors,
                            'borderWidth' => 0,
                        ],
                    ]" />
            @else
                <div class="flex h-64 flex-col items-center justify-center text-gray-400 dark:text-gray-500">
                    <x-lucide-pie-chart class="mb-3 size-10 opacity-40" />
                    <p class="text-sm italic">{{ __('Belum ada data.') }}</p>
                </div>
            @endif
        </div>
    </div>

    {{-- ── Distribusi status tindak lanjut (Naskah Keluar) ── --}}
    <div class="rounded-xl border border-gray-100 bg-white shadow-sm dark:border-white/[0.06] dark:bg-gray-900">
        <div class="flex items-center gap-3 border-b border-gray-100 px-5 py-4 dark:border-white/[0.06]">
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-rose-500 to-rose-600 shadow-md shadow-rose-500/25">
                <x-lucide-send class="size-4 text-white" />
            </div>
            <div class="min-w-0">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('Status Tindak Lanjut') }}
                </h2>
                <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Naskah keluar · :bulan', ['bulan' => $bulanLabel]) }}
                </p>
            </div>
        </div>

        <div class="p-4">
            @if (count($keluarStatusData) > 0)
                <x-core::chart
                    type="doughnut"
                    :labels="$keluarStatusLabels"
                    :datasets="[
                        [
                            'data' => $keluarStatusData,
                            'backgroundColor' => $keluarStatusColors,
                            'borderWidth' => 0,
                        ],
                    ]" />
            @else
                <div class="flex h-64 flex-col items-center justify-center text-gray-400 dark:text-gray-500">
                    <x-lucide-pie-chart class="mb-3 size-10 opacity-40" />
                    <p class="text-sm italic">{{ __('Belum ada data.') }}</p>
                </div>
            @endif
        </div>
    </div>

</div>
