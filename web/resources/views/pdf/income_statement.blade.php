@php
    $money = function (?string $v): string {
        if ($v === null || $v === '') return '';
        $neg = str_starts_with($v, '-');
        [$i, $d] = array_pad(explode('.', ltrim($v, '-')), 2, '00');
        return ($neg ? '−' : '').strrev(implode('.', str_split(strrev($i), 3))).','.str_pad(substr($d, 0, 2), 2, '0');
    };
    $fiscal = $d['view'] === 'fiscale';
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 18mm 14mm 16mm 14mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #222; }
    h1 { font-size: 15pt; margin: 0 0 2mm; } h2 { font-size: 11pt; margin: 6mm 0 2mm; }
    .meta { color: #555; margin-bottom: 3mm; } .box { border: 0.3mm solid #ccc; padding: 2mm 3mm; margin-bottom: 4mm; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #eee; text-align: left; font-size: 8pt; padding: 1.6mm; border-bottom: 0.4mm solid #222; }
    td { padding: 1.4mm 1.6mm; border-bottom: 0.2mm solid #ddd; }
    .num { text-align: right; white-space: nowrap; } .grp td { font-weight: bold; background: #f4f4f4; } .acc td:first-child { padding-left: 6mm; }
    .sum td { font-weight: bold; border-top: 0.4mm solid #222; } .muted { color: #555; font-size: 8pt; }
    footer { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7pt; color: #555; }
</style>
</head>
<body>
<footer>{{ $company->name }} · P. IVA {{ $company->partita_iva }} · Conto economico {{ $d['year'] }} · {{ $d['views'][$d['view']] }} · generato il {{ $generatedAt->format('d/m/Y H:i') }} con Semplicon</footer>
<h1>Conto economico {{ $d['year'] }} · {{ strtolower($d['views'][$d['view']]) }}</h1>
<div class="meta">{{ $company->name }} · P. IVA {{ $company->partita_iva }}</div>
<div class="box">{{ $d['criterion'] }}@foreach($d['notes'] as $n)<br><span class="muted">{{ $n }}</span>@endforeach</div>
@foreach(['ricavi' => 'Ricavi', 'costi' => 'Costi'] as $k => $title)
    <h2>{{ $title }}</h2>
    <table>
        <thead><tr><th>Conto</th><th class="num">Importo</th>@if($fiscal)<th class="num">Fiscale</th>@endif</tr></thead>
        <tbody>
        @forelse($d[$k] as $g)
            <tr class="grp"><td>{{ $g['label'] }}</td><td class="num">{{ $money($g['amount']) }}</td>@if($fiscal)<td class="num">{{ $money($g['fiscal']) }}</td>@endif</tr>
            @foreach($g['rows'] as $r)
                <tr class="acc"><td>{{ $r['code'] }} · {{ $r['name'] }}</td><td class="num">{{ $money($r['amount']) }}</td>@if($fiscal)<td class="num">{{ $money($r['fiscal']) }}</td>@endif</tr>
            @endforeach
        @empty
            <tr><td colspan="3" class="muted">Nessun importo.</td></tr>
        @endforelse
        <tr class="sum"><td>Totale {{ strtolower($title) }}</td><td class="num">{{ $money($d['totals'][$k]) }}</td>@if($fiscal)<td class="num">{{ $money($d['totals'][$k.'_fiscali']) }}</td>@endif</tr>
        </tbody>
    </table>
@endforeach
<h2>Risultato</h2>
<table><tbody>
    <tr class="sum"><td>Ricavi − costi</td><td class="num">{{ $money($d['totals']['risultato']) }}</td>@if($fiscal)<td class="num">{{ $money($d['totals']['risultato_fiscale']) }}</td>@endif</tr>
@if($fiscal && ($d['totals']['maggiorazioni'] ?? '0.00') !== '0.00')
    <tr><td>Variazione in diminuzione: maggiorazioni (super/iperammortamento)</td><td class="num"></td><td class="num">−{{ $money($d['totals']['maggiorazioni']) }}</td></tr>
    <tr class="sum"><td>Risultato fiscale dopo le variazioni</td><td class="num"></td><td class="num">{{ $money($d['totals']['risultato_dopo_variazioni']) }}</td></tr>
@endif
</tbody></table>
@if($fiscal && $d['da_verificare'] !== '0.00')
    <p class="muted">Da verificare, non compreso nel risultato: {{ $money($d['da_verificare']) }} € ({{ count($d['pending']) }} documenti).</p>
@endif
<p class="muted">Elaborazione dai dati registrati in Semplicon: non sostituisce il bilancio né la dichiarazione dei redditi.</p>
</body>
</html>
