<?php

namespace App\Domain;

use App\Domain\Import\FatturaPaSchema;
use App\Models\Allocation;
use App\Models\Company;
use App\Models\VatRegistration;
use App\Support\Decimal;
use App\Support\VatPeriod;

/**
 * Registri IVA (I04) dalle registrazioni. Regole: docs/FISCAL_RULES.md, R-REGS-*.
 *
 * - Ogni registrazione è una riga nel registro della propria direzione, anche «stornata» e «storno» (segno).
 * - I documenti di integrazione (TD16–TD19), registrati come acquisto, vengono inoltre riportati nel Registro vendite
 *   con l'imposta come IVA a debito.
 * - Importi in centesimi come interi.
 */
final class VatRegisterService
{
    public const INTEGRATION_TYPES = ['TD16', 'TD17', 'TD18', 'TD19'];

    /**
     * Registro con una riga per riga di fattura (incarico di sviluppo 19.11.2026, sezione 4).
     * Righe da DettaglioLinee ovvero dalle righe inserite manualmente; per gruppo IVA imposta e IVA detraibile
     * della registrazione vengono ripartite proporzionalmente in centesimi, le differenze rispetto al gruppo (sconti/maggiorazioni, arrotondamenti) come
     * riga di riconciliazione a sé. Senza righe (documenti vecchi): gruppi con indicazione «dettaglio non disponibile».
     *
     * @return array{entries: list<array>, totals: list<array>, sum: array{taxable: string, tax: string, deductible: string}}
     */
    public function build(Company $company, string $register, ?int $year, ?int $period = null): array
    {
        $query = VatRegistration::query()
            ->where('company_id', $company->id)
            ->when($year, fn ($q) => $q->where('year', $year))
            ->when($year && $period, fn ($q) => $q->where('period', $period))
            ->with(['invoice.vatSummaries', 'invoice.lines', 'lines.account', 'document']);

        if ($register === 'acquisti') {
            $query->where('direction', 'acquisto');
        } else {
            // Vendite più integrazioni (TD16–TD19 come documento proprio o IVA integrata nella registrazione).
            $query->where(fn ($q) => $q->where('direction', 'vendita')
                ->orWhere(fn ($w) => $w->where('direction', 'acquisto')->where(fn ($x) => $x->whereHas('invoice', fn ($i) => $i->whereIn('doc_type', self::INTEGRATION_TYPES))
                    ->orWhereHas('lines', fn ($l) => $l->where('integrated', true)))));
        }

        $rows = $query->get()->sortBy([['year', 'asc'], ['direction', 'desc'], ['protocol', 'asc']])->values();
        // Data incasso/pagamento (solo rappresentazione fiscale; lo Scadenziario resta invariato): fonte per anno di registrazione
        $paid = [];
        foreach (Allocation::where('company_id', $company->id)->where('status', 'valida')->whereHas('installment', fn ($q) => $q->whereIn('invoice_id', $rows->pluck('invoice_id')))->with('installment:id,invoice_id')->orderBy('allocation_date')->get(['id', 'installment_id', 'allocation_date']) as $a) {
            $paid[$a->installment->invoice_id][] = $a->allocation_date->toDateString();
        }
        $sources = [];
        $entries = [];
        $totals = [];
        $sum = ['taxable' => 0, 'tax' => 0, 'deductible' => 0];

        foreach ($rows as $r) {
            $integration = $register === 'vendite' && $r->direction->value === 'acquisto';
            $inv = $r->invoice;
            $positions = $this->positions($r, $integration);
            foreach ($positions as $l) {
                $key = $l['natura'] ? 'N:'.$l['natura'] : 'R:'.$l['rate'];
                $totals[$key] ??= ['rate' => (string) $l['rate'], 'natura' => $l['natura'], 'taxable' => 0, 'tax' => 0, 'deductible' => 0];
                $totals[$key]['taxable'] += $l['taxable'];
                $totals[$key]['tax'] += $l['tax'];
                $totals[$key]['deductible'] += $l['deductible'];
                $sum['taxable'] += $l['taxable'];
                $sum['tax'] += $l['tax'];
                $sum['deductible'] += $l['deductible'];
            }
            $counterparty = $r->direction->value === 'vendita'
                ? ['name' => $inv->customer_name, 'id' => $inv->customer_vat ?? $inv->customer_cf]
                : ['name' => $inv->supplier_name, 'id' => $inv->supplier_vat ?? $inv->supplier_cf];

            $src = $sources[$r->year] ??= CompanyOptions::paymentDateSource($company, $r->year);
            $dates = $src === 'registrazione' ? [$r->registration_date->toDateString()] : array_values(array_unique($paid[$r->invoice_id] ?? []));
            $entries[] = [
                'payment' => ['source' => $src, 'dates' => $dates, 'label' => $dates ? implode(', ', array_map(fn ($d) => implode('/', array_reverse(explode('-', $d))), array_slice($dates, 0, 3))).(count($dates) > 3 ? ' …' : '') : '—'],
                'id' => $r->id,
                'invoice_id' => $inv->id,
                'document' => $r->document ? ['id' => $r->document->id, 'name' => $r->document->original_name] : null,
                'protocol' => $r->protocol,
                'protocol_label' => ($integration ? 'A' : '').$r->protocol.'/'.$r->year,
                'year' => $r->year,
                'integration' => $integration,
                'status' => $r->status,
                'reverses_protocol' => $r->reverses_id ? VatRegistration::whereKey($r->reverses_id)->value('protocol') : null,
                'registration_date' => $r->registration_date->toDateString(),
                'receipt_date' => $r->receipt_date?->toDateString(),
                'period_label' => VatPeriod::label($r->year, $r->period, $r->periodicity),
                'doc_type' => $inv->doc_type,
                'doc_type_label' => FatturaPaSchema::label('TipoDocumentoType', $inv->doc_type) ?? $inv->doc_type,
                'number' => $inv->number,
                'doc_date' => $inv->doc_date->toDateString(),
                'counterparty' => $counterparty,
                'detail_missing' => collect($positions)->contains('kind', 'riepilogo'),
                'taxable' => Decimal::fromMinor(array_sum(array_column($positions, 'taxable'))),
                'tax' => Decimal::fromMinor(array_sum(array_column($positions, 'tax'))),
                'deductible' => Decimal::fromMinor(array_sum(array_column($positions, 'deductible'))),
                'lines' => array_map(fn ($l) => [...$l, 'taxable' => Decimal::fromMinor($l['taxable']), 'tax' => Decimal::fromMinor($l['tax']), 'deductible' => Decimal::fromMinor($l['deductible'])], $positions),
            ];
        }

        ksort($totals);
        $totals = array_values(array_map(fn ($t) => [
            ...$t,
            'label' => $t['natura'] ? $t['natura'].' · '.(FatturaPaSchema::label('NaturaType', $t['natura']) ?? '') : str_replace('.', ',', $t['rate']).'%',
            'taxable' => Decimal::fromMinor($t['taxable']), 'tax' => Decimal::fromMinor($t['tax']), 'deductible' => Decimal::fromMinor($t['deductible']),
        ], $totals));

        return [
            'payment_title' => $register === 'acquisti' ? 'Data pagamento' : 'Data incasso',
            'entries' => $entries,
            'totals' => $totals,
            'sum' => ['taxable' => Decimal::fromMinor($sum['taxable']), 'tax' => Decimal::fromMinor($sum['tax']), 'deductible' => Decimal::fromMinor($sum['deductible'])],
        ];
    }

    /** Righe di registro di una registrazione in centesimi: per riga, riconciliazione per gruppo IVA. */
    private function positions(VatRegistration $r, bool $integrationView): array
    {
        $inv = $r->invoice;
        $acq = $r->direction->value === 'acquisto' && ! $integrationView;
        $sums = $inv->vatSummaries->values();
        $integrDoc = in_array($inv->doc_type, self::INTEGRATION_TYPES, true);
        // Gruppi IVA della registrazione (per riga di riepilogo; ripartizione su più conti riassunta).
        $groups = [];
        foreach ($r->lines->values() as $pos => $l) {
            $k = $l->summary_index ?? $pos;
            if ($integrationView && ! $integrDoc && ! $l->integrated) {
                continue; // nel Registro vendite solo i gruppi integrati
            }
            $g = &$groups[$k];
            $g ??= ['taxable' => 0, 'tax' => 0, 'deductible' => 0, 'rate' => (string) $l->rate, 'natura' => $l->natura, 'accounts' => [], 'pct' => (string) $l->deductible_pct, 'integrated' => (bool) $l->integrated];
            $g['taxable'] += $r->sign * Decimal::toMinor((string) $l->taxable);
            $g['tax'] += $r->sign * Decimal::toMinor((string) $l->tax);
            $g['deductible'] += $acq ? $r->sign * Decimal::toMinor((string) $l->deductible_tax) : 0;
            if ($l->account) {
                $g['accounts'][$l->account->code] = $l->account->code.' '.$l->account->name;
            }
            unset($g);
        }
        $legal = fn (int $k) => $sums[$k]->normative_reference ?? null;
        $acctLabel = fn (array $g) => count($g['accounts']) > 1 ? 'Più conti: '.implode(', ', array_keys($g['accounts'])) : (array_values($g['accounts'])[0] ?? '—');
        $base = fn (array $g, int $k) => ['rate' => $g['rate'], 'natura' => $g['natura'], 'natura_label' => $g['natura'] ? FatturaPaSchema::label('NaturaType', $g['natura']) : null,
            'account' => $acctLabel($g), 'deductible_pct' => $g['pct'], 'legal_ref' => $legal($k), 'integrated' => $g['integrated']];

        $lines = $inv->lines->sortBy('line_no')->values();
        if ($lines->isEmpty()) {
            $out = [];
            foreach ($groups as $k => $g) {
                $out[] = [...$base($g, $k), 'line_no' => null, 'description' => 'Riepilogo IVA (dettaglio delle righe non disponibile)', 'kind' => 'riepilogo',
                    'taxable' => $g['taxable'], 'tax' => $g['tax'], 'deductible' => $g['deductible']];
            }

            return $out;
        }
        // Righe del gruppo per aliquota/natura della fattura (gruppo originario, anche con IVA integrata).
        $keyOf = fn ($rate, $natura) => $natura ? 'N:'.$natura : 'R:'.Decimal::normalize((string) $rate);
        $byKey = [];
        foreach ($sums as $k => $s) {
            $byKey[$keyOf($s->rate, $s->natura)] ??= $k;
        }
        $assigned = [];
        foreach ($lines as $l) {
            $k = $byKey[$keyOf($l->rate, $l->natura)] ?? array_key_first($groups);
            $assigned[$k][] = $l;
        }
        $out = [];
        foreach ($groups as $k => $g) {
            $ls = $assigned[$k] ?? [];
            $amounts = array_map(fn ($l) => $r->sign * self::cents((string) $l->total), $ls);
            $taxes = self::spread($g['tax'], $amounts);
            $deds = self::spread($g['deductible'], $amounts);
            foreach ($ls as $i => $l) {
                $out[] = [...$base($g, $k), 'line_no' => $l->line_no, 'description' => $l->description, 'kind' => 'riga',
                    'taxable' => $amounts[$i], 'tax' => $taxes[$i], 'deductible' => $deds[$i]];
            }
            $diff = $g['taxable'] - array_sum($amounts);
            if (! $ls || $diff !== 0) {
                $out[] = [...$base($g, $k), 'line_no' => null, 'description' => $ls ? 'Differenza con il riepilogo IVA (sconti, maggiorazioni o arrotondamenti a livello di documento)' : 'Riepilogo IVA senza righe corrispondenti',
                    'kind' => $ls ? 'arrotondamento' : 'riepilogo', 'taxable' => $diff, 'tax' => $ls ? 0 : $g['tax'], 'deductible' => $ls ? 0 : $g['deductible']];
            }
        }
        usort($out, fn ($a, $b) => [$a['line_no'] === null ? PHP_INT_MAX : $a['line_no']] <=> [$b['line_no'] === null ? PHP_INT_MAX : $b['line_no']]);

        return $out;
    }

    /** Importo in centesimi, mezzo centesimo in valore assoluto (PrezzoTotale può avere più decimali). */
    private static function cents(string $v): int
    {
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', trim($v), $m)) {
            return 0;
        }
        $frac = str_pad($m[3] ?? '', 3, '0');
        $c = (int) $m[2] * 100 + (int) substr($frac, 0, 2) + ((int) $frac[2] >= 5 ? 1 : 0);

        return $m[1] === '-' ? -$c : $c;
    }

    /** Ripartizione di un importo proporzionale alle righe (resto maggiore), somma esatta. */
    private static function spread(int $total, array $weights): array
    {
        $n = count($weights);
        if ($n === 0) {
            return [];
        }
        $sumW = array_sum(array_map('abs', $weights));
        if ($sumW === 0) {
            return array_map(fn ($i) => $i === 0 ? $total : 0, range(0, $n - 1));
        }
        $out = [];
        $rest = [];
        foreach ($weights as $i => $w) {
            $exact = abs($total) * abs($w);
            $out[$i] = intdiv($exact, $sumW);
            $rest[$i] = $exact % $sumW;
        }
        $left = abs($total) - array_sum($out);
        arsort($rest);
        foreach (array_keys($rest) as $i) {
            if ($left-- <= 0) {
                break;
            }
            $out[$i]++;
        }
        ksort($out);

        return array_map(fn ($v) => $total < 0 ? -$v : $v, $out);
    }

    /** CSV per Excel (Italia): punto e virgola, virgola decimale, UTF-8 con BOM, una riga per riga di registrazione. */
    public function csv(Company $company, string $register, ?int $year, ?int $period): string
    {
        $data = $this->build($company, $register, $year, $period);
        $acq = $register === 'acquisti';
        $head = ['Registro', 'Protocollo', 'Stato', 'Data registrazione', 'Periodo IVA', 'Data documento', 'Numero', 'Tipo documento', 'Controparte', 'P.IVA / C.F.'];
        if ($acq) {
            $head[] = 'Data ricezione';
        }
        $head[] = $data['payment_title'];
        array_push($head, 'Riga', 'Descrizione', 'Conto', 'Aliquota', 'Natura', 'Riferimento normativo', 'Imponibile', 'Imposta');
        if ($acq) {
            array_push($head, '% detraibile', 'IVA detraibile');
        }
        $head[] = 'Allegato';
        $out = [self::row($head)];
        $status = ['registrata' => 'Registrata', 'stornata' => 'Stornata', 'storno' => 'Storno'];
        foreach ($data['entries'] as $e) {
            foreach ($e['lines'] as $l) {
                $row = [$acq ? 'Acquisti' : 'Vendite', $e['protocol_label'], $e['integration'] ? 'Integrazione' : $status[$e['status']], self::d($e['registration_date']), $e['period_label'],
                    self::d($e['doc_date']), $e['number'], $e['doc_type'].' '.$e['doc_type_label'], $e['counterparty']['name'], (string) $e['counterparty']['id']];
                if ($acq) {
                    $row[] = self::d($e['receipt_date']);
                }
                $row[] = $e['payment']['label'];
                array_push($row, (string) $l['line_no'], $l['description'], $l['account'], self::n($l['rate']), (string) $l['natura'], (string) $l['legal_ref'], self::n($l['taxable']), self::n($l['tax']));
                if ($acq) {
                    array_push($row, self::n($l['deductible_pct']), self::n($l['deductible']));
                }
                $row[] = (string) ($e['document']['name'] ?? '');
                $out[] = self::row($row);
            }
        }

        return "\xEF\xBB\xBF".implode("\r\n", $out)."\r\n";
    }

    private static function row(array $cells): string
    {
        return implode(';', array_map(function ($c) {
            $c = (string) $c;
            // Protezione contro la formula injection nei fogli di calcolo.
            if ($c !== '' && in_array($c[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! preg_match('/^-\d+(,\d+)?$/', $c)) {
                $c = "'".$c;
            }

            return preg_match('/[;"\r\n]/', $c) ? '"'.str_replace('"', '""', $c).'"' : $c;
        }, $cells));
    }

    private static function n(?string $v): string
    {
        return $v === null ? '' : str_replace('.', ',', $v);
    }

    private static function d(?string $iso): string
    {
        return $iso ? implode('/', array_reverse(explode('-', $iso))) : '';
    }
}
