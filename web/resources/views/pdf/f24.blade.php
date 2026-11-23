@php
    $money = function (?string $v): string {
        if ($v === null || $v === '' || $v === '0.00') return '';
        [$i, $d] = array_pad(explode('.', ltrim($v, '-')), 2, '00');
        return strrev(implode('.', str_split(strrev($i), 3))).','.str_pad(substr($d, 0, 2), 2, '0');
    };
    $date = fn (?string $iso) => $iso ? implode('/', array_reverse(explode('-', $iso))) : '';
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 16mm 14mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #102f2a; }
    h1 { font-size: 15pt; margin: 0 0 2mm; }
    .stamp { border: 0.5mm solid #b26a00; color: #b26a00; padding: 2mm 3mm; font-weight: bold; margin: 3mm 0; }
    .box { border: 0.3mm solid #003d35; padding: 2.5mm; margin-bottom: 4mm; }
    .label { font-size: 7pt; color: #52665f; text-transform: uppercase; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #edf9f1; text-align: left; font-size: 7.5pt; padding: 1.8mm; border: 0.2mm solid #9fb2aa; }
    td { padding: 1.8mm; border: 0.2mm solid #9fb2aa; }
    .num { text-align: right; white-space: nowrap; }
    .sum td { font-weight: bold; }
</style>
</head>
<body>
<h1>Delega F24 · bozza n. {{ $f->id }}</h1>
<div class="stamp">BOZZA predisposta da Semplicon – non trasmessa e senza addebito. Stato: {{ \App\Domain\Liquidazioni\F24Service::STATUS[$f->status] }}@if($f->presented_at) · presentata il {{ $date($f->presented_at->toDateString()) }}@endif @if($f->protocol) · protocollo {{ $f->protocol }}@endif</div>
<div class="box">
    <div class="label">Contribuente</div>
    <div>Codice fiscale: <strong>{{ $company->codice_fiscale ?: $company->partita_iva }}</strong> · Partita IVA {{ $company->partita_iva }}</div>
    <div>{{ $company->name }}</div>
</div>
<div class="box">
    <div class="label">Scadenza</div>
    <div><strong>{{ $date($f->due_date->toDateString()) }}</strong>@if($f->settlement) · liquidazione {{ $f->settlement->data['period_label'] }} (versione {{ $f->settlement->version }})@endif</div>
</div>
<div class="label">Sezione Erario</div>
<table>
    <thead><tr><th>Codice tributo</th><th>Rateazione / mese rif.</th><th>Anno di riferimento</th><th class="num">Importi a debito versati</th><th class="num">Importi a credito compensati</th><th>Descrizione</th></tr></thead>
    <tbody>
        @foreach($f->lines as $l)
            <tr><td><strong>{{ $l['code'] }}</strong></td><td>{{ $l['rateazione'] }}</td><td>{{ $l['year'] }}</td><td class="num">{{ $money($l['debit']) }}</td><td class="num">{{ $money($l['credit']) }}</td><td>{{ $l['label'] }}</td></tr>
        @endforeach
        <tr class="sum"><td colspan="3">Totale</td><td class="num">{{ $money((string) $f->total_debit) }}</td><td class="num">{{ $money((string) $f->total_credit) }}</td><td>Saldo (A − B): {{ $money((string) $f->balance) ?: '0,00' }}</td></tr>
    </tbody>
</table>
<p style="margin-top: 5mm">Saldo finale: <strong>{{ $money((string) $f->balance) ?: '0,00' }} €</strong>@if((string) $f->balance === '0.00') · delega a saldo zero: va comunque presentata entro la scadenza tramite i servizi telematici dell’Agenzia delle Entrate.@endif</p>
<p style="color: #52665f; font-size: 7.5pt">Generata il {{ $generatedAt->format('d/m/Y H:i') }} · regole {{ $f->rule_version }}. Semplicon prepara il documento: la presentazione e l’eventuale addebito avvengono fuori da Semplicon.</p>
</body>
</html>
