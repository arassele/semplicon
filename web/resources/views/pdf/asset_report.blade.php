@php
    $fmt = function ($v, bool $num) {
        if (! $num || $v === null || $v === '' || ! preg_match('/^-?\d+(\.\d+)?$/', (string) $v)) return $v;
        $neg = str_starts_with((string) $v, '-'); [$i, $d] = array_pad(explode('.', ltrim((string) $v, '-')), 2, '00');
        return ($neg ? '−' : '').strrev(implode('.', str_split(strrev($i), 3))).','.str_pad(substr($d, 0, 2), 2, '0');
    };
    $num = array_map(fn ($c) => ($c[1] ?? '') === 'num', $r['columns']);
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 14mm 10mm 14mm 10mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 7.5pt; color: #222; }
    h1 { font-size: 13pt; margin: 0 0 1mm; } .meta { color: #555; margin-bottom: 3mm; font-size: 8pt; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #eee; text-align: left; font-size: 7pt; padding: 1.2mm; border-bottom: 0.4mm solid #222; vertical-align: bottom; }
    td { padding: 1mm 1.2mm; border-bottom: 0.2mm solid #ddd; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .tot td { font-weight: bold; } .muted { color: #555; font-size: 7pt; }
    footer { position: fixed; bottom: -9mm; left: 0; right: 0; font-size: 6.5pt; color: #555; }
</style>
</head>
<body>
<footer>{{ $company->name }} · P. IVA {{ $company->partita_iva }} · {{ $r['title'] }} · esercizio {{ $r['year'] }} · generato il {{ $generatedAt->format('d/m/Y H:i') }} con Semplicon</footer>
<h1>{{ $r['title'] }}</h1>
<div class="meta">{{ $company->name }} · P. IVA {{ $company->partita_iva }} · esercizio {{ $r['year'] }} · data di stampa {{ $generatedAt->format('d/m/Y') }}</div>
<table>
    <thead><tr>@foreach($r['columns'] as $k => $c)<th class="{{ $num[$k] ? 'num' : '' }}">{{ $c[0] }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse($r['rows'] as $row)
        <tr>@foreach($row as $k => $v)<td class="{{ ($num[$k] ?? false) ? 'num' : '' }}">{{ $fmt($v, $num[$k] ?? false) }}</td>@endforeach</tr>
    @empty
        <tr><td colspan="{{ count($r['columns']) }}" class="muted">Nessun dato per l’esercizio.</td></tr>
    @endforelse
    </tbody>
</table>
@if($r['totals'])
    <table style="width: 55%; margin: 4mm 0 0 auto">@foreach($r['totals'] as $k => $v)<tr class="tot"><td>{{ $k }}</td><td class="num">{{ $fmt($v, true) }}</td></tr>@endforeach</table>
@endif
@foreach($r['notes'] as $n)<p class="muted">{{ $n }}</p>@endforeach
</body>
</html>
