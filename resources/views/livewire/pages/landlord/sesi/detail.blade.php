<div class="space-y-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="font-mono text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ $snapshot->started_at?->format('d M Y H:i') ?? '—' }}
            </h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ __('Laporan diagnostik satu sesi panen scraper. Read-only — tidak ada yang bisa diubah dari sini.') }}
            </p>
        </div>

        <a href="{{ route('srikandi.sesi.index') }}"
            class="flex-none text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
            {{ __('Kembali ke daftar') }}
        </a>
    </div>

    {{-- Badge ringkasan kondisi sesi. --}}
    <div class="flex flex-wrap items-center gap-2">
        @if ($snapshot->isFailed())
            <x-core::badge color="red" :label="__('Gagal')" />
        @else
            <x-core::badge color="emerald" :label="__('Sukses')" />
        @endif

        <x-core::badge color="gray" :label="__('sumber: :slug', ['slug' => $snapshot->sumber])" />

        @if ($duration !== null)
            <x-core::badge color="indigo" :label="__('Durasi :durasi', ['durasi' => $duration])" />
        @endif

        @if (filled($snapshot->error_code))
            <x-core::badge color="red" :label="__('error: :kode', ['kode' => $snapshot->error_code])" />
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Ringkasan rentang waktu. --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700/60 dark:bg-gray-900">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('Rentang Sesi') }}
                </h2>

                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('Mulai') }}
                        </dt>
                        <dd class="mt-0.5 text-gray-800 dark:text-gray-200">
                            {{ $snapshot->started_at?->format('d M Y H:i:s') ?? '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('Selesai') }}
                        </dt>
                        <dd class="mt-0.5 text-gray-800 dark:text-gray-200">
                            {{ $snapshot->finished_at?->format('d M Y H:i:s') ?? '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('Login') }}
                        </dt>
                        <dd class="mt-0.5 text-gray-800 dark:text-gray-200">
                            {{ $snapshot->login_at?->format('d M Y H:i:s') ?? '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('Baca Naskah') }}
                        </dt>
                        <dd class="mt-0.5 text-gray-800 dark:text-gray-200">
                            {{ $snapshot->naskah_at?->format('d M Y H:i:s') ?? '—' }}
                        </dd>
                    </div>
                </dl>
            </div>

            {{-- Ringkasan panen per jenis. --}}
            <div class="space-y-4">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('Ringkasan Panen per Jenis') }}
                </h2>

                @forelse ($entries as $index => $entry)
                    @php($entry = (array) $entry)
                    <div wire:key="sesi-entry-{{ $index }}"
                        class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700/60 dark:bg-gray-900">

                        <div class="flex items-center justify-between gap-3">
                            <h3 class="font-mono text-sm font-semibold text-gray-900 dark:text-gray-100">
                                {{ $entry['jenis'] ?? __('(jenis tidak diketahui)') }}
                            </h3>

                            @php($complete = $entry['complete'] ?? null)
                            @if ($complete === true)
                                <x-core::badge color="emerald" :label="__('lengkap')" />
                            @elseif ($complete === false)
                                <x-core::badge color="amber" :label="__('terpotong')" />
                            @else
                                <span class="text-xs text-gray-400 dark:text-gray-500">—</span>
                            @endif
                        </div>

                        <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                            @foreach ([
                                'fetched' => __('Fetched'),
                                'domRows' => __('DOM Rows'),
                                'rows' => __('Rows'),
                                'responses' => __('Responses'),
                                'encryptedResponses' => __('Terenkripsi'),
                                'baru' => __('Baru'),
                                'revisi' => __('Revisi'),
                                'tetap' => __('Tetap'),
                            ] as $key => $label)
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        {{ $label }}
                                    </dt>
                                    <dd class="mt-0.5 text-gray-800 dark:text-gray-200">
                                        {{ array_key_exists($key, $entry) && $entry[$key] !== null ? $entry[$key] : '—' }}
                                    </dd>
                                </div>
                            @endforeach
                        </dl>

                        <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3 dark:border-gray-700/60">
                            @php($hilang = $entry['hilangDariSrikandi'] ?? null)
                            <x-core::badge color="{{ $hilang === 0 ? 'gray' : 'amber' }}"
                                :label="__('Hilang dari Srikandi: :n', ['n' => $hilang ?? '—'])" />

                            @php($cursor = $entry['cursorExhausted'] ?? null)
                            <x-core::badge color="{{ $cursor === true ? 'emerald' : 'gray' }}"
                                :label="__('Cursor habis: :v', ['v' => $cursor === null ? '—' : ($cursor ? 'ya' : 'tidak')])" />

                            @php($tfoot = $entry['tfootSaysDone'] ?? null)
                            <x-core::badge color="{{ $tfoot === true ? 'emerald' : 'gray' }}"
                                :label="__('tfoot: :v', ['v' => $tfoot === null ? '—' : ($tfoot ? 'done' : 'belum')])" />

                            @if (array_key_exists('round', $entry) || array_key_exists('maxRounds', $entry))
                                <x-core::badge color="indigo"
                                    :label="__('Round :r / :m', ['r' => $entry['round'] ?? '—', 'm' => $entry['maxRounds'] ?? '—'])" />
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-gray-200 p-6 text-center dark:border-gray-700/60">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('Sesi ini tidak membawa ringkasan panen (mis. gagal sebelum membaca list).') }}
                        </p>
                    </div>
                @endforelse
            </div>
        </div>

        <div class="space-y-6">
            {{--
                🔴 `snapshots` render pakai echo ter-escape, BUKAN raw echo.

                Isinya JSON dari luar sistem — bisa berisi apa saja termasuk tag
                `<script>`. Raw echo di sini akan jadi Stored XSS di halaman
                Bale, dan gejalanya baru terlihat kalau sumbernya memang
                mengirim HTML.

                Flag JSON_UNESCAPED_SLASHES supaya garis miring di bale/srikandi
                tidak muncul sebagai teks korup.
            --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700/60 dark:bg-gray-900">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('Snapshot mentah') }}
                </h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Bentuk persis seperti yang dikirim scraper, sebelum Bale memetakan kolom.') }}
                </p>

                <details class="mt-3">
                    <summary class="cursor-pointer text-xs font-medium text-indigo-600 hover:text-indigo-700 dark:text-indigo-400">
                        {{ __('Tampilkan snapshot') }}
                    </summary>

                    @if ($snapshot->snapshots === null)
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                            {{ __('Snapshot kosong.') }}
                        </p>
                    @else
                        <pre class="mt-2 max-h-96 overflow-auto rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-800 dark:bg-gray-800/50 dark:text-gray-200">{{ json_encode($snapshot->snapshots, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    @endif
                </details>
            </div>

            {{-- Identitas sesi. --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700/60 dark:bg-gray-900">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('Identitas Sesi') }}
                </h2>

                <dl class="mt-3 space-y-3 text-sm">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('Sumber') }}
                        </dt>
                        <dd class="mt-0.5 font-mono text-gray-800 dark:text-gray-200">
                            {{ $snapshot->sumber }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('Status') }}
                        </dt>
                        <dd class="mt-0.5 text-gray-800 dark:text-gray-200">
                            {{ $snapshot->status }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('Kode Error') }}
                        </dt>
                        <dd class="mt-0.5 font-mono text-gray-800 dark:text-gray-200">
                            {{ $snapshot->error_code ?? '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('ID') }}
                        </dt>
                        <dd class="mt-0.5 break-all font-mono text-xs text-gray-500 dark:text-gray-400">
                            {{ $snapshot->getKey() }}
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</div>
