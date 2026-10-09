<div class="space-y-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ $naskah->nomor_naskah }}
            </h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ __('Salinan list naskah dinas. Read-only — sumbernya tetap Srikandi.') }}
            </p>
        </div>

        <a href="{{ route('srikandi.naskah.index') }}"
            class="flex-none text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
            {{ __('Kembali ke daftar') }}
        </a>
    </div>

    {{-- Badge ringkasan kondisi. --}}
    <div class="flex flex-wrap items-center gap-2">
        @if ($stale)
            <x-core::badge color="amber" :label="__('Tidak terlihat lagi di list Srikandi')" />
        @else
            <x-core::badge color="emerald" :label="__('Masih terlihat di list Srikandi')" />
        @endif

        @if ($hasBerkas)
            <x-core::badge color="indigo" :label="trim((string) $naskah->status_berkas)" />
        @else
            <x-core::badge color="gray" :label="__('TIDAK ADA — status berkas kosong')" />
        @endif

        <x-core::badge color="gray" :label="__('sumber: :slug', ['slug' => $naskah->sumber])" />
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            {{-- Isi naskah. --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700/60 dark:bg-gray-900">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('Isi Naskah') }}
                </h2>

                <dl class="mt-3 space-y-3 text-sm">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('Perihal') }}
                        </dt>
                        <dd class="mt-0.5 text-gray-800 dark:text-gray-200">
                            {{ $naskah->hal ?? '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('Ringkasan') }}
                        </dt>
                        <dd class="mt-0.5 text-gray-800 dark:text-gray-200">
                            {{ $naskah->ringkasan ?? '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('Pengirim') }}
                        </dt>
                        <dd class="mt-0.5 text-gray-800 dark:text-gray-200">
                            {{ $naskah->pengirim ?? '—' }}
                        </dd>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ __('Tanggal') }}
                            </dt>
                            <dd class="mt-0.5 text-gray-800 dark:text-gray-200">
                                {{ $naskah->tanggal_naskah?->format('d M Y') ?? '—' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ __('Status Baca') }}
                            </dt>
                            <dd class="mt-0.5 text-gray-800 dark:text-gray-200">
                                {{ trim((string) $naskah->status_baca) !== '' ? $naskah->status_baca : '—' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ __('Status Tindak') }}
                            </dt>
                            <dd class="mt-0.5 text-gray-800 dark:text-gray-200">
                                {{ trim((string) $naskah->status_tindak) !== '' ? $naskah->status_tindak : '—' }}
                            </dd>
                        </div>
                    </div>
                </dl>
            </div>

            {{--
                🔴 `snapshot` render pakai echoscape, BUKAN raw echo.

                Isinya JSON dari luar sistem — bisa berisi apa saja termasuk
                tag `<script>`. Raw echo di sini akan jadi Stored XSS di halaman
                Bale, dan gejalanya baru terlihat kalau sumbernya memang
                mengirim HTML.

                Flag JSON_UNESCAPED_SLASHES dipakai supaya garis miring di
                bale/srikandi tidak jadi escape dan tidak muncul sebagai teks
                korup.
            --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700/60 dark:bg-gray-900">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('Snapshot mentah') }}
                </h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Bentuk persis seperti yang dikirim scraper, sebelum Bale memetakan kolom. Dipakai untuk membandingkan apa yang berubah antar siklus.') }}
                </p>

                <details class="mt-3">
                    <summary class="cursor-pointer text-xs font-medium text-indigo-600 hover:text-indigo-700 dark:text-indigo-400">
                        {{ __('Tampilkan snapshot') }}
                    </summary>

                    @if ($naskah->snapshot === null)
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                            {{ __('Snapshot kosong.') }}
                        </p>
                    @else
                        <pre class="mt-2 max-h-96 overflow-auto rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-800 dark:bg-gray-800/50 dark:text-gray-200">{{ json_encode($naskah->snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    @endif
                </details>
            </div>
        </div>

        <div class="space-y-6">
            {{-- Jejak pengamatan. --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700/60 dark:bg-gray-900">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('Jejak Pengamatan') }}
                </h2>

                <dl class="mt-3 space-y-3 text-sm">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('Pertama terlihat') }}
                        </dt>
                        <dd class="mt-0.5 text-gray-800 dark:text-gray-200">
                            {{ $naskah->first_seen_at?->format('d M Y H:i') ?? '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('Terakhir terlihat') }}
                        </dt>
                        <dd class="mt-0.5 text-gray-800 dark:text-gray-200">
                            {{ $naskah->last_seen_at?->format('d M Y H:i') ?? '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('Berada di list selama') }}
                        </dt>
                        <dd class="mt-0.5 text-gray-800 dark:text-gray-200">
                            {{ $naskah->first_seen_at && $naskah->last_seen_at
                                ? $naskah->first_seen_at->diffForHumans($naskah->last_seen_at, ['syntax' => Carbon\CarbonInterface::DIFF_ABSOLUTE])
                                : '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('Status') }}
                        </dt>
                        <dd class="mt-0.5 text-gray-800 dark:text-gray-200">
                            {{ $stale ? __('basi — tidak terlihat lagi') : __('segar — masih terlihat') }}
                        </dd>
                    </div>
                </dl>
            </div>

            {{-- Hash baris. --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700/60 dark:bg-gray-900">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('Hash Baris') }}
                </h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('row_hash dihitung backend dari isi baris. Hash yang sama berarti isinya tidak berubah sejak siklus lalu; hash yang berbeda memicu penandaan revised.') }}
                </p>
                <p class="mt-2 break-all font-mono text-xs text-gray-800 dark:text-gray-200">
                    {{ $naskah->row_hash ?? '—' }}
                </p>
            </div>
        </div>
    </div>
</div>