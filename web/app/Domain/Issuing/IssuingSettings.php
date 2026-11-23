<?php

namespace App\Domain\Issuing;

use App\Domain\Import\FatturaPaSchema;
use App\Models\Company;

/**
 * Impostazioni per l'emissione in «Dati azienda»: ritenuta d'acconto e fino a due casse previdenziali.
 * Semplicon non propone aliquote: le percentuali le inserisce il cliente (non inventare regole fiscali).
 *
 * ritenuta: enabled, type (RT01–RT06), rate (%), base_pct (% dell'imponibile), causale (Codice CU, es. A)
 * casse[]:  type (TC01–TC22), rate (% cassa), base_pct (% dell'imponibile), vat_rate (aliquota IVA sul contributo), natura (con 0 %),
 *           ritenuta (il contributo è soggetto a ritenuta), cap (massimale: contributo massimo per fattura, vuoto = senza)
 */
final class IssuingSettings
{
    /** Denominazioni per «Professione svolta»: solo le casse che corrispondono inequivocabilmente a una professione. */
    public const PROFESSIONS = ['Avvocato' => 'TC01', 'Dott. commercialista' => 'TC02', 'Geometra' => 'TC03', 'Ingegnere / Architetto' => 'TC04', 'Notaio' => 'TC05',
        'Ragioniere / Perito commerciale' => 'TC06', 'Agente (ENASARCO)' => 'TC07', 'Consulente del lavoro' => 'TC08', 'Medico' => 'TC09', 'Farmacista' => 'TC10',
        'Medico veterinario' => 'TC11', 'Giornalista' => 'TC14', 'Perito industriale' => 'TC17', 'Biologo' => 'TC19', 'Infermiere' => 'TC20', 'Psicologo' => 'TC21',
        'Altre professioni (gestione separata INPS)' => 'TC22'];

    /** Etichette delle causali, per quanto note con certezza; gli altri codici Semplicon li mostra solo con il loro codice. */
    public const CAUSALI = ['A' => 'Prestazioni di lavoro autonomo rientranti nell’esercizio di arte o professione abituale', 'M' => 'Prestazioni di lavoro autonomo non esercitate abitualmente', 'ZO' => 'Titolo diverso dai precedenti'];

    public static function blank(): array
    {
        return ['ritenuta' => ['enabled' => false, 'type' => 'RT01', 'rate' => '20.00', 'base_pct' => '100.00', 'causale' => 'A'], 'casse' => []];
    }

    /** Impostazioni salvate, completate con i valori predefiniti. */
    public static function for(Company $company): array
    {
        $s = (array) $company->issuing_settings;
        $r = array_replace(self::blank()['ritenuta'], (array) ($s['ritenuta'] ?? []));
        $casse = array_values(array_map(fn ($c) => array_replace(['type' => 'TC22', 'rate' => '', 'base_pct' => '100.00', 'vat_rate' => '22', 'natura' => '', 'ritenuta' => false, 'cap' => ''], (array) $c), (array) ($s['casse'] ?? [])));

        return ['ritenuta' => $r, 'casse' => array_slice($casse, 0, 2)];
    }

    /** Opzioni per l'interfaccia (Dati azienda e modulo di emissione). */
    public static function options(): array
    {
        $codes = FatturaPaSchema::codes();

        return [
            'cassa_types' => collect($codes['TipoCassaType'])->map(fn ($l, $c) => ['code' => $c, 'label' => $l])->values(),
            'ritenuta_types' => collect($codes['TipoRitenutaType'])->map(fn ($l, $c) => ['code' => $c, 'label' => $l])->values(),
            'causali' => collect(FatturaPaSchema::enumValues('CausalePagamentoType'))->map(fn ($c) => ['code' => $c, 'label' => self::CAUSALI[$c] ?? null])->values(),
            'professions' => collect(self::PROFESSIONS)->map(fn ($c, $l) => ['label' => $l, 'type' => $c])->values(),
            'nature' => collect($codes['NaturaType'])->map(fn ($l, $c) => ['code' => $c, 'label' => $l])->values(),
        ];
    }

    /** Uniformare i dati inseriti da «Dati azienda» (virgola, valori vuoti). */
    public static function normalize(?array $in): ?array
    {
        if ($in === null) {
            return null;
        }
        $n = fn ($v) => str_replace(',', '.', trim((string) $v));
        $r = (array) ($in['ritenuta'] ?? []);
        $out = ['ritenuta' => ['enabled' => ! empty($r['enabled']), 'type' => (string) ($r['type'] ?? 'RT01'), 'rate' => $n($r['rate'] ?? ''), 'base_pct' => $n($r['base_pct'] ?? '100') ?: '100', 'causale' => (string) ($r['causale'] ?? 'A')], 'casse' => []];
        foreach (array_slice(array_values((array) ($in['casse'] ?? [])), 0, 2) as $c) {
            $out['casse'][] = ['type' => (string) ($c['type'] ?? ''), 'rate' => $n($c['rate'] ?? ''), 'base_pct' => $n($c['base_pct'] ?? '100') ?: '100', 'vat_rate' => $n($c['vat_rate'] ?? '22') ?: '0',
                'natura' => (string) ($c['natura'] ?? ''), 'ritenuta' => ! empty($c['ritenuta']), 'cap' => $n($c['cap'] ?? '')];
        }

        return $out;
    }
}
