@php
    $money = function (?int $c): string {
        if ($c === null) return '';
        $neg = $c < 0; $c = abs($c);
        return ($neg ? '−' : '').number_format(intdiv($c, 100), 0, ',', '.').','.str_pad((string) ($c % 100), 2, '0', STR_PAD_LEFT);
    };
    $date = fn (?string $iso) => $iso ? implode('/', array_reverse(explode('-', $iso))) : '';
    $rows = [
        ['VP2', 'Totale operazioni attive (imponibile)', 'vp2'], ['VP3', 'Totale operazioni passive (imponibile)', 'vp3'], ['VP4', 'IVA esigibile', 'vp4'], ['VP5', 'IVA detratta', 'vp5'],
        ['VP6', 'IVA dovuta', 'vp6_dovuta'], ['VP6', 'IVA a credito', 'vp6_credito'], ['VP7', 'Debito periodo precedente (non superiore a 100 €)', 'vp7'], ['VP8', 'Credito periodo precedente', 'vp8'],
        ['VP9', 'Credito anno precedente', 'vp9'], ['VP10', 'Versamenti auto UE', 'vp10'], ['VP11', 'Crediti d’imposta', 'vp11'], ['VP12', 'Interessi dovuti (trimestrali)', 'vp12'],
        ['VP13', 'Acconto', 'vp13'], ['VP14', 'IVA da versare', 'vp14_versare'], ['VP14', 'IVA a credito (da riportare)', 'vp14_credito'],
    ];
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 16mm 12mm 14mm 12mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #102f2a; }
    h1 { font-size: 14pt; margin: 0 0 2mm; } h2 { font-size: 10pt; margin: 5mm 0 2mm; }
    .meta { color: #52665f; margin-bottom: 3mm; }
    .stamp { display: inline-block; padding: 1mm 3mm; border: 0.4mm solid #b26a00; color: #b26a00; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #edf9f1; text-align: left; font-size: 8pt; padding: 1.6mm; border-bottom: 0.4mm solid #003d35; }
    td { padding: 1.4mm 1.6mm; border-bottom: 0.2mm solid #dce3dd; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; } .code { color: #52665f; width: 12mm; }
    .muted { color: #52665f; font-size: 8pt; } ul { margin: 1mm 0 0 5mm; padding: 0; }
    footer { position: fixed; bottom: -9mm; left: 0; right: 0; font-size: 7pt; color: #52665f; }
</style>
</head>
<body>
<footer>{{ $company->name }} · P. IVA {{ $company->partita_iva }} · LIPE {{ $b['quarter'] }}° trimestre {{ $b['year'] }} · stampa di controllo, non è la comunicazione · generata il {{ $generatedAt->format('d/m/Y H:i') }} con Semplicon</footer>
<h1>LIPE · Comunicazione delle liquidazioni periodiche IVA</h1>
<div class="meta">{{ $company->name }} · P. IVA {{ $company->partita_iva }} · C.F. {{ $b['company']['codice_fiscale'] ?? '—' }} · {{ $b['quarter'] }}° trimestre {{ $b['year'] }} · scadenza invio {{ $date($b['deadline']) }}@if($b['filename']) · file {{ $b['filename'] }}@endif</div>
@if($b['blocking'])
    <p><span class="stamp">NON PRONTA – il file XML non si può ancora creare</span></p>
    <ul>@foreach($b['blocking'] as $x)<li>{{ $x }}</li>@endforeach</ul>
@else
    <p>Dati ricavati dalle liquidazioni IVA confermate (ultima versione). Confrontali con il modulo VP prima dell’invio.</p>
@endif
@if($b['modules'])
    <h2>Riepilogo dei moduli (importi in euro)</h2>
    <table>
        <thead><tr><th class="code">Rigo</th><th>Voce</th>@foreach($b['modules'] as $m)<th class="num">{{ $m['label'] }}<br><span class="muted">modulo {{ $m['no'] }} · versione {{ $m['version'] }}</span></th>@endforeach</tr></thead>
        <tbody>
        @foreach($rows as [$vp, $label, $k])
            @if(collect($b['modules'])->contains(fn ($m) => $m[$k] !== null) || in_array($k, ['vp2', 'vp3', 'vp4', 'vp5']))
                <tr><td class="code">{{ $vp }}</td><td>{{ $label }}</td>@foreach($b['modules'] as $m)<td class="num">{{ $money($m[$k]) }}</td>@endforeach</tr>
            @endif
        @endforeach
        </tbody>
    </table>
    @if(collect($b['modules'])->contains(fn ($m) => $m['deferred_out'] > 0))
        <p class="muted">Importi non superiori a 100 € non versati nel periodo: il debito è rinviato al periodo successivo (VP7).</p>
    @endif
@endif
@if($b['warnings'])
    <h2>Da verificare</h2>
    <ul>@foreach($b['warnings'] as $x)<li>{{ $x }}</li>@endforeach</ul>
@endif
</body>
</html>
