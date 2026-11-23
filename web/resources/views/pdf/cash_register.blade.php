@php
    $money = function (?string $v): string {
        if ($v === null || $v === '') return '';
        $neg = str_starts_with($v, '-');
        [$i, $d] = array_pad(explode('.', ltrim($v, '-')), 2, '00');
        return ($neg ? '−' : '').strrev(implode('.', str_split(strrev($i), 3))).','.str_pad(substr($d, 0, 2), 2, '0');
    };
    $date = fn (?string $iso) => $iso ? implode('/', array_reverse(explode('-', $iso))) : '';
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 18mm 12mm 16mm 12mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #102f2a; }
    h1 { font-size: 14pt; margin: 0 0 2mm; }
    .meta { color: #52665f; margin-bottom: 5mm; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #edf9f1; text-align: left; font-size: 7.5pt; padding: 2mm 1.5mm; border-bottom: 0.4mm solid #003d35; }
    td { padding: 1.6mm 1.5mm; border-bottom: 0.2mm solid #dce3dd; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .muted { color: #52665f; font-size: 7.5pt; }
    .totals { margin-top: 6mm; width: 55%; }
    .sum td { font-weight: bold; border-top: 0.4mm solid #003d35; }
    footer { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7pt; color: #52665f; }
</style>
</head>
<body>
<footer>{{ $company->name }} · P. IVA {{ $company->partita_iva }} · Registro incassi e pagamenti · generato il {{ $generatedAt->format('d/m/Y H:i') }} con Semplicon</footer>
<h1>Registro incassi e pagamenti · {{ $label }}</h1>
<div class="meta">{{ $company->name }} · P. IVA {{ $company->partita_iva }}@if($company->codice_fiscale) · C.F. {{ $company->codice_fiscale }}@endif</div>
<table>
    <thead><tr><th>Data</th><th>Data fiscale</th><th>Operazione</th><th>Controparte</th><th>Documenti</th><th>Modalità</th><th class="num">Incassi</th><th class="num">Pagamenti</th></tr></thead>
    <tbody>
    @forelse($rows as $r)
        <tr>
            <td>{{ $date($r['date']) }}</td>
            <td>{{ $date($r['fiscal_date']) }}@if($r['sostituto'])<br><span class="muted">data del sostituto</span>@endif</td>
            <td>{{ $r['kind'] === 'incasso' ? 'Incasso' : 'Pagamento' }}</td>
            <td>{{ $r['party'] }}</td>
            <td>@foreach($r['docs'] as $d){{ $d }}<br>@endforeach</td>
            <td>{{ $r['method'] }}</td>
            <td class="num">{{ $r['kind'] === 'incasso' ? $money($r['amount']) : '' }}</td>
            <td class="num">{{ $r['kind'] === 'pagamento' ? $money($r['amount']) : '' }}</td>
        </tr>
    @empty
        <tr><td colspan="8" class="muted">Nessun movimento nel periodo.</td></tr>
    @endforelse
    </tbody>
</table>
<table class="totals">
    <tr><td>Totale incassi</td><td class="num">{{ $money($in) }}</td></tr>
    <tr><td>Totale pagamenti</td><td class="num">{{ $money($out) }}</td></tr>
    <tr class="sum"><td>Saldo</td><td class="num">{{ $money($balance) }}</td></tr>
</table>
</body>
</html>
