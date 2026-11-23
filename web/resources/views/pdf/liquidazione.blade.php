@php
    $money = function (?string $v): string {
        if ($v === null || $v === '') return '';
        $neg = str_starts_with($v, '-');
        [$i, $d] = array_pad(explode('.', ltrim($v, '-')), 2, '00');
        return ($neg ? '−' : '').strrev(implode('.', str_split(strrev($i), 3))).','.str_pad(substr($d, 0, 2), 2, '0');
    };
    $date = fn (?string $iso) => $iso ? implode('/', array_reverse(explode('-', $iso))) : '';
    $f = $d['figures'];
    $result = ['debito' => 'A debito', 'credito' => 'A credito', 'zero' => 'A zero'][$d['result']];
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 18mm 14mm 16mm 14mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #102f2a; }
    h1 { font-size: 15pt; margin: 0 0 2mm; }
    h2 { font-size: 10.5pt; margin: 6mm 0 2mm; }
    .meta { color: #52665f; margin-bottom: 4mm; }
    .stamp { display: inline-block; padding: 1mm 3mm; border: 0.4mm solid #b26a00; color: #b26a00; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #edf9f1; text-align: left; font-size: 8pt; padding: 1.8mm; border-bottom: 0.4mm solid #003d35; }
    td { padding: 1.6mm 1.8mm; border-bottom: 0.2mm solid #dce3dd; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .sub td { color: #52665f; font-size: 8pt; padding-left: 6mm; }
    .sum td { font-weight: bold; border-top: 0.4mm solid #003d35; }
    .muted { color: #52665f; font-size: 8pt; }
    footer { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7pt; color: #52665f; }
</style>
</head>
<body>
<footer>{{ $company->name }} · P. IVA {{ $company->partita_iva }} · Liquidazione IVA {{ $d['period_label'] }} · regole {{ $d['rule_version'] }} · generato il {{ $generatedAt->format('d/m/Y H:i') }} con Semplicon</footer>
<h1>Liquidazione IVA · {{ $d['period_label'] }}</h1>
<div class="meta">{{ $company->name }} · P. IVA {{ $company->partita_iva }} · {{ $d['profile_label'] }} · pro rata {{ str_replace('.', ',', $d['pro_rata']) }}%</div>
@if($s)
    <p>Versione {{ $s->version }} confermata il {{ $s->confirmed_at->format('d/m/Y H:i') }} da {{ $s->confirmer?->name }}@if($s->review_required) · <span class="stamp">Liquidazione da riesaminare</span>@endif</p>
@else
    <p><span class="stamp">BOZZA – non confermata</span></p>
@endif

<table>
    <thead><tr><th>Voce</th><th class="num">Importo</th></tr></thead>
    <tbody>
        <tr><td>IVA a debito</td><td class="num">{{ $money($f['debit']) }}</td></tr>
        <tr class="sub"><td>vendite</td><td class="num">{{ $money($f['sales']) }}</td></tr>
        <tr class="sub"><td>integrazioni (reverse charge TD16–TD19)</td><td class="num">{{ $money($f['integrations']) }}</td></tr>
        <tr class="sub"><td>rettifiche manuali</td><td class="num">{{ $money($f['adj_debit']) }}</td></tr>
        <tr><td>IVA detraibile</td><td class="num">{{ $money($f['deductible']) }}</td></tr>
        <tr class="sub"><td>acquisti (dopo indetraibilità specifica)</td><td class="num">{{ $money($f['deductible_gross']) }}</td></tr>
        <tr class="sub"><td>riduzione pro rata</td><td class="num">{{ $f['pro_rata_reduction'] === '0.00' ? '0,00' : '−'.$money($f['pro_rata_reduction']) }}</td></tr>
        <tr class="sub"><td>rettifiche manuali</td><td class="num">{{ $money($f['adj_deductible']) }}</td></tr>
        <tr><td>Saldo del periodo</td><td class="num">{{ $money($f['own']) }}</td></tr>
        <tr><td>Debiti rinviati da periodi precedenti</td><td class="num">{{ $money($f['deferred_in']) }}</td></tr>
        <tr><td>Credito dei periodi precedenti utilizzato</td><td class="num">{{ $money($f['credit_previous']) }}</td></tr>
        <tr><td>Credito annuale utilizzato</td><td class="num">{{ $money($f['credit_annual']) }}</td></tr>
        <tr><td>Acconto IVA</td><td class="num">{{ $money($f['acconto']) }}</td></tr>
        <tr class="sum"><td>Imposta</td><td class="num">{{ $money($f['tax']) }}</td></tr>
        <tr><td>Interessi trimestrali (1%)</td><td class="num">{{ $money($f['interest']) }}</td></tr>
        <tr class="sum"><td>Risultato: {{ $result }}</td><td class="num">{{ $d['result'] === 'credito' ? $money($f['credit']) : $money($f['due']) }}</td></tr>
        @if($f['deferred_out'] !== '0.00')<tr><td>Debito rinviato al periodo successivo</td><td class="num">{{ $money($f['deferred_out']) }}</td></tr>@endif
        @if($f['split'] !== '0.00')<tr class="sub"><td>IVA in split payment (versata dal cessionario, esclusa)</td><td class="num">{{ $money($f['split']) }}</td></tr>@endif
    </tbody>
</table>
@if($f['due'] !== '0.00')
<p>Versamento con F24: codice tributo {{ $d['code']['code'] }}@if($d['code']['rateazione']) (rateazione {{ $d['code']['rateazione'] }})@endif, anno {{ $d['year'] }}, scadenza {{ $date($d['due_date']) }}.</p>
@endif
@foreach($d['notes'] as $n)<p class="muted">{{ $n }}</p>@endforeach

@foreach(['vendite' => 'IVA a debito · vendite', 'integrazioni' => 'IVA a debito · integrazioni', 'acquisti' => 'IVA detraibile · acquisti', 'rettifiche' => 'Note di credito e storni (già compresi)'] as $k => $title)
    @if(count($d['detail'][$k]))
    <h2>{{ $title }}</h2>
    <table>
        <thead><tr><th>Prot.</th><th>Documento</th><th>Controparte</th><th class="num">Importo</th></tr></thead>
        <tbody>
        @foreach($d['detail'][$k] as $r)
            <tr><td>{{ $r['protocol'] }}</td><td>{{ $r['doc_type'] }} {{ $r['number'] }} del {{ $date($r['doc_date']) }}@if($r['status'] === 'storno') · storno @endif</td><td>{{ $r['counterparty'] }}</td><td class="num">{{ $money($r['amount']) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    @endif
@endforeach
@if(count($d['adjustments']))
<h2>Rettifiche manuali e acconto</h2>
<table><thead><tr><th>Tipo</th><th>Causale</th><th>Riferimento</th><th>Autore</th><th class="num">Importo</th></tr></thead><tbody>
@foreach($d['adjustments'] as $a)<tr><td>{{ $a['kind'] }}</td><td>{{ $a['causale'] }}</td><td>{{ $a['reference'] }}</td><td>{{ $a['author'] }} · {{ $date($a['date']) }}</td><td class="num">{{ $money($a['amount']) }}</td></tr>@endforeach
</tbody></table>
@endif
@if(count($d['credits']))
<h2>Crediti utilizzati</h2>
<table><thead><tr><th>Origine</th><th>Tipo</th><th class="num">Importo</th></tr></thead><tbody>
@foreach($d['credits'] as $c)<tr><td>{{ $c['origin'] }}</td><td>{{ $c['type'] === 'annuale' ? 'credito annuale' : 'periodo precedente' }}</td><td class="num">{{ $money($c['amount']) }}</td></tr>@endforeach
</tbody></table>
@endif
</body>
</html>
