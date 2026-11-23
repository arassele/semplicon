@php
    $acq = $register === 'acquisti';
    // Formatta gli importi senza float: "1234.50" → "1.234,50"
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
    .totals { margin-top: 6mm; width: 60%; }
    .totals td, .totals th { padding: 1.6mm; }
    .sum td { font-weight: bold; border-top: 0.4mm solid #003d35; }
    footer { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7pt; color: #52665f; }
</style>
</head>
<body>
<footer>{{ $company->name }} · P. IVA {{ $company->partita_iva }} · Registro IVA {{ $register }} · generato il {{ $generatedAt->format('d/m/Y H:i') }} con Semplicon</footer>
<h1>Registro IVA {{ $acq ? 'acquisti' : 'vendite' }} · {{ $periodLabel }}</h1>
<div class="meta">{{ $company->name }} · P. IVA {{ $company->partita_iva }}@if($company->codice_fiscale) · C.F. {{ $company->codice_fiscale }}@endif</div>

<table>
    <thead><tr>
        <th>Prot.</th><th>Data reg.</th>@if($acq)<th>Ricezione</th>@endif<th>{{ $data['payment_title'] }}</th><th>Documento</th><th>Controparte</th><th>Riga</th><th>Descrizione</th><th>Aliquota</th>
        <th class="num">Imponibile</th><th class="num">Imposta</th>@if($acq)<th class="num">Detraibile</th>@endif
    </tr></thead>
    <tbody>
    @forelse($data['entries'] as $e)
        @foreach($e['lines'] as $k => $l)
        <tr>
            @if($k === 0)
            <td rowspan="{{ count($e['lines']) }}">{{ $e['protocol_label'] }}@if($e['status'] === 'storno')<br><span class="muted">storno di {{ $e['reverses_protocol'] }}</span>@elseif($e['status'] === 'stornata')<br><span class="muted">stornata</span>@elseif($e['integration'])<br><span class="muted">integrazione</span>@endif</td>
            <td rowspan="{{ count($e['lines']) }}">{{ $date($e['registration_date']) }}</td>
            @if($acq)<td rowspan="{{ count($e['lines']) }}">{{ $e['receipt_date'] ? $date($e['receipt_date']) : '' }}</td>@endif
            <td rowspan="{{ count($e['lines']) }}">{{ $e['payment']['label'] }}</td>
            <td rowspan="{{ count($e['lines']) }}">{{ $e['doc_type'] }} {{ $e['number'] }}<br><span class="muted">del {{ $date($e['doc_date']) }}</span></td>
            <td rowspan="{{ count($e['lines']) }}">{{ $e['counterparty']['name'] }}<br><span class="muted">{{ $e['counterparty']['id'] }}</span></td>
            @endif
            <td>{{ $l['line_no'] ?? '' }}</td>
            <td>{{ $l['description'] }}@if($l['legal_ref'])<br><span class="muted">{{ $l['legal_ref'] }}</span>@endif</td>
            <td>{{ $l['natura'] ?? str_replace('.', ',', $l['rate']).'%' }}</td>
            <td class="num">{{ $money($l['taxable']) }}</td>
            <td class="num">{{ $money($l['tax']) }}</td>
            @if($acq)<td class="num">{{ $money($l['deductible']) }}</td>@endif
        </tr>
        @endforeach
    @empty
        <tr><td colspan="{{ $acq ? 11 : 9 }}" class="muted">Nessuna registrazione nel periodo.</td></tr>
    @endforelse
    </tbody>
</table>

<table class="totals">
    <thead><tr><th>Riepilogo per aliquota / natura</th><th class="num">Imponibile</th><th class="num">Imposta</th>@if($acq)<th class="num">Detraibile</th>@endif</tr></thead>
    <tbody>
    @foreach($data['totals'] as $t)
        <tr><td>{{ $t['label'] }}</td><td class="num">{{ $money($t['taxable']) }}</td><td class="num">{{ $money($t['tax']) }}</td>@if($acq)<td class="num">{{ $money($t['deductible']) }}</td>@endif</tr>
    @endforeach
        <tr class="sum"><td>Totale</td><td class="num">{{ $money($data['sum']['taxable']) }}</td><td class="num">{{ $money($data['sum']['tax']) }}</td>@if($acq)<td class="num">{{ $money($data['sum']['deductible']) }}</td>@endif</tr>
    </tbody>
</table>
</body>
</html>
