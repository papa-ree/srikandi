<div class="rounded-xl border border-gray-100 bg-white shadow-sm dark:border-white/[0.06] dark:bg-gray-900">

    {{-- ── Header ── --}}
    <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 dark:border-white/[0.06] sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-teal-500 to-emerald-600 shadow-md shadow-teal-500/25">
                <x-lucide-heart-pulse class="size-4 text-white" />
            </div>
            <div>
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('Kesehatan Client') }}
                </h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Status akun, sinkronisasi, dan notifikasi · :total client', ['total' => $totalClients]) }}
                </p>
            </div>
        </div>

        <a href="{{ route('srikandi.client.index') }}"
            class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-lg border border-teal-200 bg-teal-50 px-3 py-1.5 text-xs font-medium text-teal-700 transition hover:bg-teal-100 dark:border-teal-800/40 dark:bg-teal-900/20 dark:text-teal-300 dark:hover:bg-teal-900/40 sm:self-auto">
            <x-lucide-users class="size-3.5" />
            {{ __('Kelola Client') }}
        </a>
    </div>

    @if ($rows->isEmpty())
        {{-- Empty state --}}
        <div class="flex flex-col items-center justify-center px-4 py-16 text-center">
            <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-teal-50 dark:bg-teal-900/20">
                <x-lucide-users class="size-8 text-teal-400 dark:text-teal-500" />
            </div>
            <p class="text-sm font-medium text-gray-600 dark:text-gray-400">{{ __('Belum ada client Srikandi.') }}</p>
            <a href="{{ route('srikandi.client.create') }}"
                class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-teal-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-teal-700">
                <x-lucide-plus class="size-3.5" />
                {{ __('Tambah client pertama') }}
            </a>
        </div>
    @else

        {{-- ── Tabel (md ke atas) ── --}}
        <div class="hidden overflow-x-auto md:block">
            <table class="min-w-full">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50/60 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:border-white/[0.06] dark:bg-gray-800/40 dark:text-gray-400">
                        <th class="px-5 py-3">{{ __('Client') }}</th>
                        <th class="px-5 py-3">{{ __('Status') }}</th>
                        <th class="hidden px-5 py-3 lg:table-cell">{{ __('Wara') }}</th>
                        <th class="px-5 py-3">{{ __('Naskah') }}</th>
                        <th class="hidden px-5 py-3 xl:table-cell">{{ __('Login Terakhir') }}</th>
                        <th class="hidden px-5 py-3 lg:table-cell">{{ __('Naskah Terakhir') }}</th>
                        <th class="px-5 py-3">{{ __('Notifikasi') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100/80 dark:divide-white/[0.04]">
                    @foreach ($rows as $row)
                        @php($client = $row['client'])
                        <tr wire:key="overview-client-{{ $client->getKey() }}"
                            class="transition-colors duration-150 hover:bg-teal-50/40 dark:hover:bg-teal-900/10">

                            <td class="px-5 py-3.5">
                                <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $client->nama }}
                                </div>
                                <div class="font-mono text-xs text-gray-400 dark:text-gray-500">
                                    {{ $client->slug }}
                                </div>
                            </td>

                            <td class="px-5 py-3.5">
                                @if ($client->last_error_at)
                                    <x-core::badge color="{{ $row['status'][1] }}"
                                        :label="$row['status'][0]"
                                        title="{{ $client->last_error_message }}" />
                                @else
                                    <x-core::badge color="{{ $row['status'][1] }}" :label="$row['status'][0]" />
                                @endif
                            </td>

                            <td class="hidden px-5 py-3.5 lg:table-cell">
                                @if ($client->wara_client_id)
                                    <x-core::badge color="emerald" :label="__('Tersambung')" />
                                @else
                                    <span class="text-xs text-gray-400 dark:text-gray-600">—</span>
                                @endif
                            </td>

                            <td class="px-5 py-3.5">
                                <span class="text-sm font-bold text-gray-900 dark:text-white">
                                    {{ number_format($row['naskah']) }}
                                </span>
                                <span class="ml-1 text-xs text-gray-400 dark:text-gray-500">
                                    {{ $bulanLabel }}
                                </span>
                            </td>

                            <td class="hidden px-5 py-3.5 text-xs text-gray-500 dark:text-gray-400 xl:table-cell">
                                {{ $client->last_login_at?->format('d M Y H:i') ?? '—' }}
                            </td>

                            <td class="hidden px-5 py-3.5 text-xs text-gray-500 dark:text-gray-400 lg:table-cell">
                                {{ $client->last_naskah_at?->format('d M Y H:i') ?? '—' }}
                            </td>

                            <td class="px-5 py-3.5">
                                <x-core::badge color="{{ $row['notifikasi'][1] }}" :label="$row['notifikasi'][0]" />
                                <div class="mt-1 text-[11px] text-gray-400 dark:text-gray-500">
                                    {{ trans_choice('{0} 0 penerima|{1} :count penerima|[2,*] :count penerima', $row['recipients'], ['count' => $row['recipients']]) }}
                                </div>
                            </td>

                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- ── Kartu (di bawah md) ── --}}
        <div class="divide-y divide-gray-100/80 dark:divide-white/[0.04] md:hidden">
            @foreach ($rows as $row)
                @php($client = $row['client'])
                <div wire:key="overview-client-card-{{ $client->getKey() }}" class="p-4">

                    {{-- Client name + status badge --}}
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">
                                {{ $client->nama }}
                            </div>
                            <div class="truncate font-mono text-xs text-gray-400 dark:text-gray-500">
                                {{ $client->slug }}
                            </div>
                        </div>
                        <x-core::badge color="{{ $row['status'][1] }}" :label="$row['status'][0]" />
                    </div>

                    {{-- Stats grid --}}
                    <dl class="mt-3 grid grid-cols-2 gap-3">
                        <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                {{ __('Naskah') }}
                            </dt>
                            <dd class="mt-0.5 text-base font-bold text-gray-900 dark:text-white">
                                {{ number_format($row['naskah']) }}
                                <span class="text-xs font-normal text-gray-400">{{ $bulanLabel }}</span>
                            </dd>
                        </div>

                        <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                {{ __('Notifikasi') }}
                            </dt>
                            <dd class="mt-1 flex items-center gap-1.5">
                                <x-core::badge color="{{ $row['notifikasi'][1] }}" :label="$row['notifikasi'][0]" />
                                <span class="text-xs text-gray-400">({{ $row['recipients'] }})</span>
                            </dd>
                        </div>

                        <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                {{ __('Login Terakhir') }}
                            </dt>
                            <dd class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">
                                {{ $client->last_login_at?->format('d M Y H:i') ?? '—' }}
                            </dd>
                        </div>

                        <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                {{ __('Naskah Terakhir') }}
                            </dt>
                            <dd class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">
                                {{ $client->last_naskah_at?->format('d M Y H:i') ?? '—' }}
                            </dd>
                        </div>
                    </dl>

                    {{-- Error message (if any) --}}
                    @if ($client->last_error_message)
                        <div class="mt-3 flex items-start gap-2 rounded-lg border border-red-100 bg-red-50 px-3 py-2.5 dark:border-red-800/30 dark:bg-red-900/15">
                            <x-lucide-alert-triangle class="mt-0.5 size-3.5 shrink-0 text-red-500 dark:text-red-400" />
                            <p class="text-xs text-red-700 dark:text-red-300">{{ $client->last_error_message }}</p>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

    @endif
</div>
