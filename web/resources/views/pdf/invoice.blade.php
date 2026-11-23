@php
    $money = function (?string $v): string {
        if ($v === null || $v === '') return '';
        $neg = str_starts_with($v, '-');
        [$i, $d] = array_pad(explode('.', ltrim($v, '-')), 2, '00');
        return ($neg ? '−' : '').strrev(implode('.', str_split(strrev($i), 3))).','.str_pad(substr($d, 0, 2), 2, '0');
    };
    $num = fn (?string $v) => $v === null ? '' : rtrim(rtrim(str_replace('.', ',', $v), '0'), ',');
    $date = fn (?string $iso) => $iso ? implode('/', array_reverse(explode('-', substr($iso, 0, 10)))) : '';
    $cur = $b['currency'] === 'EUR' ? '€' : $b['currency'];
    $cd = $doc['cedente']; $cs = $doc['cessionario'];
    $credit = $b['type'] === 'TD04';
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>{{ $b['type_label'] ?: 'Fattura' }} n. {{ $b['number'] }}</title>
<style>
    @page { margin: 16mm 14mm 18mm 14mm; }
    * { box-sizing: border-box; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 8.6pt; color: #1c1c1c; margin: 0; }
    .wrap { max-width: 190mm; margin: 0 auto; }
    h1 { font-size: 15pt; margin: 0 0 1mm; }
    h2 { font-size: 8pt; text-transform: uppercase; letter-spacing: .08em; color: #6b6b6b; margin: 0 0 1.5mm; }
    .muted { color: #6b6b6b; font-size: 7.6pt; }
    table { width: 100%; border-collapse: collapse; }
    .parties td { width: 50%; vertical-align: top; padding: 0 3mm 4mm 0; }
    .box { border: 0.3mm solid #d6d6d6; padding: 3mm; }
    th { background: #eeeeee; text-align: left; font-size: 7.4pt; padding: 2mm 1.5mm; border-bottom: 0.4mm solid #2b2b2b; }
    td { padding: 1.6mm 1.5mm; border-bottom: 0.2mm solid #d6d6d6; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .totals { width: 58%; margin-left: auto; margin-top: 5mm; }
    .totals td { padding: 1.4mm 1.5mm; }
    .sum td { font-weight: bold; border-top: 0.4mm solid #2b2b2b; }
    .banner { background: #ececec; color: #333333; padding: 2mm 3mm; margin-bottom: 4mm; font-size: 8pt; }
    footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 6.8pt; color: #6b6b6b; }
    @media screen { body { padding: 12px; background: #fff; } }
</style>
</head>
<body>
<footer>Copia di cortesia ricavata dal file FatturaPA ({{ $doc['format'] }}), generata il {{ $generatedAt->format('d/m/Y H:i') }} con Semplicon. Il documento fiscale è il file XML.</footer>
<div class="wrap">
@if($preview)<div class="banner"><strong>Anteprima</strong> · la fattura non è ancora emessa: numero provvisorio, nessuna registrazione. L’anteprima mostra il contenuto del file XML che verrebbe creato.</div>@endif
@if(!empty($logo))<img src="{{ $logo }}" alt="" style="float:right; max-height:18mm; max-width:55mm;">@endif
<h1>{{ $b['type_label'] ?: 'Documento' }} n. {{ $b['number'] }}</h1>
<div class="muted" style="margin-bottom:4mm">del {{ $date($b['date']) }}@if($b['type']) · {{ $b['type'] }}@endif @if($b['currency'] !== 'EUR') · Divisa {{ $b['currency'] }}@endif</div>

<table class="parties"><tr>
    <td><div class="box"><h2>Cedente / prestatore</h2>
        <strong>{{ $cd['name'] }}</strong><br>
        @if($cd['address']){{ $cd['address'] }}<br>@endif @if($cd['city']){{ $cd['city'] }}@if($cd['country']) {{ $cd['country'] }}@endif<br>@endif
        @if($cd['vat'])P. IVA {{ $cd['vat'] }}<br>@endif @if($cd['cf'] && $cd['cf'] !== substr($cd['vat'], 2))C.F. {{ $cd['cf'] }}<br>@endif
        @if($cd['regime_label'])<span class="muted">Regime fiscale {{ $cd['regime_label'] }}</span><br>@endif
        @if($cd['email'] || $cd['phone'])<span class="muted">{{ $cd['email'] }} {{ $cd['phone'] }}</span>@endif
    </div></td>
    <td><div class="box"><h2>Cessionario / committente</h2>
        <strong>{{ $cs['name'] }}</strong><br>
        @if($cs['address']){{ $cs['address'] }}<br>@endif @if($cs['city']){{ $cs['city'] }}@if($cs['country']) {{ $cs['country'] }}@endif<br>@endif
        @if($cs['vat'])P. IVA {{ $cs['vat'] }}<br>@endif @if($cs['cf'])C.F. {{ $cs['cf'] }}<br>@endif
        @if($doc['dest_code'])<span class="muted">Codice destinatario {{ $doc['dest_code'] }}@if($doc['dest_pec']) · PEC {{ $doc['dest_pec'] }}@endif</span>@endif
    </div></td>
</tr></table>

@foreach($b['links'] as $l)<p class="muted">Riferimento: fattura n. {{ $l['number'] }} del {{ $date($l['date']) }}</p>@endforeach
@foreach($b['causali'] as $c)<p><strong>Causale:</strong> {{ $c }}</p>@endforeach

<table>
    <thead><tr><th>N.</th><th>Descrizione</th><th class="num">Qtà</th><th>UM</th><th class="num">Prezzo</th><th class="num">Sconto</th><th class="num">IVA</th><th class="num">Importo</th></tr></thead>
    <tbody>
    @foreach($b['lines'] as $l)
        <tr><td>{{ $l['no'] }}</td><td>{{ $l['description'] }}</td><td class="num">{{ $num($l['qty']) }}</td><td>{{ $l['unit'] }}</td><td class="num">{{ $money($l['price']) }}</td><td class="num">{{ $l['discount'] }}</td>
            <td class="num">{{ $l['natura'] ?: $num($l['rate']).'%' }}</td><td class="num">{{ $money($l['total']) }}</td></tr>
    @endforeach
    </tbody>
</table>

<table style="margin-top:5mm">
    <thead><tr><th>Riepilogo IVA</th><th class="num">Imponibile</th><th class="num">Imposta</th><th>Esigibilità / riferimento</th></tr></thead>
    <tbody>
    @foreach($b['riepilogo'] as $r)
        <tr><td>{{ $r['natura'] ? $r['natura'].' · '.$r['natura_label'] : $num($r['rate']).'%' }}</td><td class="num">{{ $money($r['taxable']) }}</td><td class="num">{{ $money($r['tax']) }}</td>
            <td class="muted">{{ $r['esig'] === 'S' ? 'Scissione dei pagamenti' : ($r['esig'] === 'D' ? 'Esigibilità differita' : ($r['esig'] === 'I' ? 'Esigibilità immediata' : '')) }} {{ $r['ref'] }}</td></tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    @foreach($b['casse'] as $c)<tr><td>Cassa previdenziale {{ $c['type'] }} ({{ $num($c['rate']) }}% su {{ $money($c['base']) }})</td><td class="num">{{ $money($c['amount']) }}</td></tr>@endforeach
    <tr><td>Imponibile</td><td class="num">{{ $money($b['taxable']) }}</td></tr>
    <tr><td>IVA</td><td class="num">{{ $money($b['tax']) }}</td></tr>
    @if($b['bollo'])<tr><td>Bollo virtuale</td><td class="num">{{ $money($b['bollo']) }}</td></tr>@endif
    <tr class="sum"><td>Totale documento</td><td class="num">{{ $money($b['total']) }} {{ $cur }}</td></tr>
    @foreach($b['ritenute'] as $r)<tr><td>Ritenuta d’acconto {{ $r['type'] }} {{ $num($r['rate']) }}% (causale {{ $r['causale'] }})</td><td class="num">− {{ $money($r['amount']) }}</td></tr>@endforeach
    @if($b['ritenute'] && $b['to_pay'] !== null)<tr class="sum"><td>Netto a pagare</td><td class="num">{{ $money($b['to_pay']) }} {{ $cur }}</td></tr>@endif
</table>

@if($b['payments'])
<h2 style="margin-top:6mm">{{ $credit ? 'Pagamento' : 'Pagamento e scadenze' }}</h2>
<table>
    <thead><tr><th>Modalità</th><th>Scadenza</th><th class="num">Importo</th><th>Coordinate</th></tr></thead>
    <tbody>@foreach($b['payments'] as $p)<tr><td>{{ $p['mode_label'] }}</td><td>{{ $date($p['due']) }}</td><td class="num">{{ $money($p['amount']) }}</td><td class="muted">{{ $p['iban'] }} {{ $p['bank'] }}</td></tr>@endforeach</tbody>
</table>
@endif
</div>
</body>
</html>
