{{--
    Dokumen PDF Overview Srikandi.

    🔴 Blade MANDIRI: tanpa layout `core::layouts.app`, tanpa Tailwind, tanpa
    komponen ber-Alpine. dompdf hanya mengerti HTML + CSS dasar, dan stylesheet
    Tailwind tidak ikut termuat ke dalam dokumen.

    Chart.js tidak bisa dirender di server, jadi export sengaja berisi angka dan
    tabel -- bukan gambar grafik.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ __('Overview Srikandi') }} — {{ $report->period->label() }}</title>
    <style>
        @page { margin: 24px 28px; }
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9px;
            color: #1f2937;
            margin: 0;
        }
        h1 { font-size: 16px; margin: 0 0 2px; color: #0f766e; }
        h2 { font-size: 11px; margin: 18px 0 6px; color: #0f766e; }
        .meta { font-size: 9px; color: #6b7280; margin: 0 0 12px; }
        .muted { font-weight: normal; color: #6b7280; }
        table { width: 100%; border-collapse: collapse; }
        table.summary th,
        table.summary td {
            border: 1px solid #e5e7eb;
            padding: 4px 6px;
            text-align: left;
            vertical-align: top;
        }
        table.summary th { width: 32%; background: #f0fdfa; color: #115e59; font-weight: bold; }
        table.data { table-layout: fixed; }
        table.data th {
            background: #0f766e;
            color: #ffffff;
            font-size: 8px;
            padding: 4px 3px;
            text-align: left;
            border: 1px solid #0f766e;
        }
        table.data td {
            border: 1px solid #e5e7eb;
            padding: 3px;
            vertical-align: top;
            word-wrap: break-word;
        }
        table.data tbody tr:nth-child(even) { background: #f9fafb; }
        .empty { text-align: center; color: #6b7280; padding: 8px; }
        .note {
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #92400e;
            padding: 5px 7px;
            margin: 0 0 6px;
        }
        .col-nomor { width: 12%; }
        .col-tanggal { width: 8%; }
        .col-sumber { width: 9%; }
        .col-pihak { width: 12%; }
        .col-hal { width: 13%; }
        .col-ringkasan { width: 30%; }
        .col-status { width: 8%; }
    </style>
</head>
<body>

<h1>{{ __('Overview Srikandi') }}</h1>
<p class="meta">
    {{ __('Periode') }}: {{ $report->period->label() }}
    · {{ __('Dibuat') }}: {{ $report->generatedAt()->timezone(config('app.timezone'))->locale('id')->translatedFormat('d M Y H:i') }}
</p>

<table class="summary">
    <tbody>
    @foreach ($report->summary() as $row)
        <tr>
            <th>{{ $row['label'] }}</th>
            <td>{{ $row['value'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

@foreach ([$report->masuk(), $report->keluar()] as $section)
    <h2>
        {{ $section['title'] }}
        <span class="muted">({{ $section['total'] }} {{ __('naskah') }})</span>
    </h2>

    @if ($section['total'] > count($section['rows']))
        <p class="note">
            {{ __('Ditampilkan :shown baris pertama dari :total.', [
                'shown' => count($section['rows']),
                'total' => $section['total'],
            ]) }}
        </p>
    @endif

    <table class="data">
        <thead>
        <tr>
            @foreach ($section['columns'] as $column)
                @php
                    $class = match ($column) {
                        'nomor_naskah' => 'col-nomor',
                        'tanggal_naskah' => 'col-tanggal',
                        'sumber' => 'col-sumber',
                        'pengirim', 'tujuan_naskah' => 'col-pihak',
                        'hal' => 'col-hal',
                        'ringkasan' => 'col-ringkasan',
                        default => 'col-status',
                    };
                @endphp
                <th class="{{ $class }}">{{ $section['headings'][$loop->index] }}</th>
            @endforeach
        </tr>
        </thead>
        <tbody>
        @forelse ($section['rows'] as $row)
            <tr>
                @foreach ($section['columns'] as $column)
                    <td>
                        @if ($column === 'ringkasan')
                            {{ \Illuminate\Support\Str::limit($row[$column], 120) }}
                        @else
                            {{ $row[$column] }}
                        @endif
                    </td>
                @endforeach
            </tr>
        @empty
            <tr>
                <td colspan="{{ count($section['columns']) }}" class="empty">
                    {{ __('Tidak ada naskah pada periode ini.') }}
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>
@endforeach

</body>
</html>
