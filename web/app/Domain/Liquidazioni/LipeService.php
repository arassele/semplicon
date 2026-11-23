<?php

namespace App\Domain\Liquidazioni;

use App\Domain\Scadenziario\FiscalCalendar;
use App\Enums\CompanyType;
use App\Models\Company;
use App\Models\VatRegistration;
use App\Support\Decimal;
use App\Support\VatPeriod;
use Carbon\CarbonImmutable;
use DOMDocument;

/**
 * LIPE (Comunicazione delle liquidazioni periodiche IVA, indicazione del committente, 21.11.2026): per trimestre un file IVP18 per la trasmissione telematica
 * e una vista di stampa di controllo. Base sono esclusivamente le liquidazioni confermate (versione più recente); i periodi senza alcun movimento vengono omessi.
 * La trasmissione avviene come per le fatture con un programma esterno: Semplicon genera il file, ma non lo valida rispetto allo schema ufficiale.
 */
final class LipeService
{
    public const NS = 'urn:www.agenziaentrate.gov.it:specificheTecniche:sco:ivp';

    public function __construct(private SettlementCalculator $calc) {}

    /** Scadenza della comunicazione: 31.5., 16.9., 30.11., fine febbraio dell'anno successivo (primo giorno lavorativo utile). */
    public static function deadline(int $year, int $quarter): string
    {
        $raw = match ($quarter) {
            1 => CarbonImmutable::create($year, 5, 31),
            2 => CarbonImmutable::create($year, 9, 16),
            3 => CarbonImmutable::create($year, 11, 30),
            default => CarbonImmutable::create($year + 1, 2, 1)->endOfMonth(),
        };

        return FiscalCalendar::workday($raw)->toDateString();
    }

    /** Periodi (mesi o il trimestre) che rientrano nella comunicazione del trimestre. */
    public static function periods(string $periodicity, int $quarter): array
    {
        return $periodicity === 'trimestrale' ? [$quarter] : [$quarter * 3 - 2, $quarter * 3 - 1, $quarter * 3];
    }

    /**
     * @return array{year: int, quarter: int, ok: bool, blocking: list<string>, warnings: list<string>, modules: list<array>, deadline: string, filename: string|null,
     *               company: array, periodicity: string|null}
     */
    public function build(Company $company, int $year, int $quarter): array
    {
        $out = ['year' => $year, 'quarter' => $quarter, 'ok' => false, 'blocking' => [], 'warnings' => [], 'modules' => [], 'deadline' => self::deadline($year, $quarter), 'filename' => null,
            'company' => ['name' => $company->name, 'partita_iva' => $company->partita_iva, 'codice_fiscale' => self::fiscalCode($company), 'societa' => $company->tipologia === CompanyType::Societa], 'periodicity' => null];
        $profile = $company->activeVatProfile($year);
        if (! $profile?->periodicity) {
            $out['blocking'][] = "Conferma il regime IVA {$year} con la periodicità delle liquidazioni.";

            return $out;
        }
        $per = $profile->periodicity->value;
        $out['periodicity'] = $per;
        if (! preg_match('/^\d{11}$/', (string) $company->partita_iva)) {
            $out['blocking'][] = 'Partita IVA dell’azienda mancante o non valida (Dati azienda).';
        }
        if (! $out['company']['codice_fiscale']) {
            $out['blocking'][] = 'Codice fiscale dell’azienda mancante (Dati azienda).';
        }
        $this->calc->forget();
        foreach (self::periods($per, $quarter) as $i => $p) {
            $label = VatPeriod::label($year, $p, $per);
            $s = SettlementCalculator::latest($company, $year, $p);
            if (! $s) {
                $d = $this->calc->compute($company, $year, $p);
                $empty = ($d['ok'] ?? false) && ! $d['registration_ids'] && ! $d['adjustments'] && ! $d['credits'] && ! $d['figures']['deferred_in'];
                if (! $empty) {
                    $out['blocking'][] = "Liquidazione {$label} non confermata: confermala prima di creare la LIPE.";
                }

                continue;
            }
            if ($s->review_required) {
                $out['blocking'][] = "Liquidazione {$label} da riesaminare: i dati sono cambiati dopo la conferma. Conferma la nuova versione.";

                continue;
            }
            $out['modules'][] = $this->module($s->data, $s->version, $p, $per, count($out['modules']) + 1);
        }
        if (! $out['modules'] && ! $out['blocking']) {
            $out['blocking'][] = 'Nel trimestre non ci sono liquidazioni da comunicare.';
        }
        $out['warnings'] = $this->warnings($company, $out);
        $out['ok'] = ! $out['blocking'];
        $out['filename'] = $out['company']['codice_fiscale'] ? sprintf('IT%s_LI_%02d%d01.xml', $out['company']['codice_fiscale'], $year % 100, $quarter) : null;

        return $out;
    }

    private static function fiscalCode(Company $c): ?string
    {
        $cf = strtoupper(trim((string) $c->codice_fiscale));
        if ($cf !== '') {
            return $cf;
        }

        return $c->tipologia === CompanyType::Societa && preg_match('/^\d{11}$/', (string) $c->partita_iva) ? $c->partita_iva : null;
    }

    /** Un modulo (VP2–VP14) dai numeri della liquidazione confermata, importi in centesimi. */
    private function module(array $data, int $version, int $period, string $per, int $no): array
    {
        $f = $data['figures'];
        [$attive, $passive] = $this->operations($data['registration_ids'] ?? []);
        $own = $f['own'];
        $m = [
            'no' => $no, 'period' => $period, 'unit' => $per === 'trimestrale' ? 'Trimestre' : 'Mese', 'label' => $data['period_label'], 'version' => $version,
            'vp2' => $attive, 'vp3' => $passive, 'vp4' => $f['debit'], 'vp5' => $f['deductible'],
            'vp6_dovuta' => $own >= 0 ? $own : null, 'vp6_credito' => $own < 0 ? -$own : null,
            'vp7' => $f['deferred_in'] ?: null, 'vp8' => $f['credit_previous'] ?: null, 'vp9' => $f['credit_annual'] ?: null, 'vp10' => null, 'vp11' => null,
            'vp12' => $f['interest'] ?: null, 'vp13' => $f['acconto'] ?: null, 'vp14_versare' => null, 'vp14_credito' => null, 'deferred_out' => $f['deferred_out'],
        ];
        if ($f['due'] > 0) {
            $m['vp14_versare'] = $f['due'];
        } elseif ($f['credit'] > 0) {
            $m['vp14_credito'] = $f['credit'];
        } elseif ($f['deferred_out'] === 0) {
            $m['vp14_versare'] = 0;
        }

        return $m;
    }

    /**
     * Importi totali delle operazioni senza IVA: attive = Registro vendite, passive = Registro acquisti senza i documenti di integrazione TD16–TD19
     * (la cui base imponibile è già nel documento originale). Storni e note di credito con il loro segno.
     *
     * @return array{0: int, 1: int}
     */
    private function operations(array $registrationIds): array
    {
        $attive = 0;
        $passive = 0;
        foreach (VatRegistration::whereIn('id', $registrationIds)->with(['lines', 'invoice'])->get() as $r) {
            $taxable = $r->lines->sum(fn ($l) => Decimal::toMinor((string) $l->taxable)) * $r->sign;
            if ($r->direction->value === 'vendita') {
                $attive += $taxable;
            } elseif (! in_array($r->invoice->doc_type, ['TD16', 'TD17', 'TD18', 'TD19'], true)) {
                $passive += $taxable;
            }
        }

        return [$attive, $passive];
    }

    private function warnings(Company $company, array $b): array
    {
        $w = ['Il file non è validato rispetto allo schema ufficiale: controllalo con il software di controllo dell’Agenzia delle Entrate prima dell’invio. La trasmissione avviene con il tuo canale (Entratel o intermediario).'];
        if ($b['company']['societa']) {
            $w[] = 'Dichiarante e codice carica non sono inclusi nel file: completali nel software di controllo o nell’invio.';
        }
        if ($b['modules']) {
            $w[] = 'Totali delle operazioni attive e passive (VP2, VP3): imponibile dei registri IVA del periodo; nelle passive non rientrano i documenti di integrazione TD16–TD19, la cui base è già nel documento originale. Verifica con il professionista.';
        }
        foreach ($b['modules'] as $m) {
            if ($m['vp13'] !== null) {
                $w[] = "{$m['label']}: acconto IVA indicato in VP13 con l’importo versato registrato nella liquidazione.";
            }
        }

        return $w;
    }

    /** XML IVP18 (importi con la virgola come separatore decimale, senza punto delle migliaia). */
    public function xml(Company $company, array $b): string
    {
        $amt = fn (int $c) => str_replace('.', ',', Decimal::fromMinor($c));
        $d = new DOMDocument('1.0', 'UTF-8');
        $d->formatOutput = true;
        $root = $d->createElementNS(self::NS, 'iv:Fornitura');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', 'http://www.w3.org/2000/09/xmldsig#');
        $d->appendChild($root);
        $el = function ($parent, string $name, ?string $value = null) use ($d) {
            $e = $d->createElementNS(self::NS, 'iv:'.$name);
            if ($value !== null) {
                $e->appendChild($d->createTextNode($value));
            }
            $parent->appendChild($e);

            return $e;
        };
        $h = $el($root, 'Intestazione');
        $el($h, 'CodiceFornitura', 'IVP18');
        $com = $el($root, 'Comunicazione');
        $com->setAttribute('identificativo', sprintf('%05d', 1));
        $fr = $el($com, 'Frontespizio');
        $el($fr, 'CodiceFiscale', $b['company']['codice_fiscale']);
        $el($fr, 'AnnoImposta', (string) $b['year']);
        $el($fr, 'PartitaIVA', $b['company']['partita_iva']);
        $el($fr, 'FirmaDichiarazione', '1');
        $el($fr, 'IdentificativoProdSoftware', 'SEMPLICON');
        $dc = $el($com, 'DatiContabili');
        foreach ($b['modules'] as $m) {
            $mod = $el($dc, 'Modulo');
            $el($mod, 'NumeroModulo', (string) $m['no']);
            $el($mod, $m['unit'], (string) $m['period']);
            $el($mod, 'TotaleOperazioniAttive', $amt($m['vp2']));
            $el($mod, 'TotaleOperazioniPassive', $amt($m['vp3']));
            $el($mod, 'IvaEsigibile', $amt($m['vp4']));
            $el($mod, 'IvaDetratta', $amt($m['vp5']));
            $m['vp6_credito'] !== null ? $el($mod, 'IvaCredito', $amt($m['vp6_credito'])) : $el($mod, 'IvaDovuta', $amt((int) $m['vp6_dovuta']));
            foreach (['vp7' => 'DebitoPrecedente', 'vp8' => 'CreditoPeriodoPrecedente', 'vp9' => 'CreditoAnnoPrecedente', 'vp12' => 'InteressiDovuti', 'vp13' => 'Acconto'] as $k => $tag) {
                if ($m[$k] !== null) {
                    $el($mod, $tag, $amt($m[$k]));
                }
            }
            if ($m['vp14_credito'] !== null) {
                $el($mod, 'ImportoACredito', $amt($m['vp14_credito']));
            } elseif ($m['vp14_versare'] !== null) {
                $el($mod, 'ImportoDaVersare', $amt($m['vp14_versare']));
            }
        }

        return $d->saveXML();
    }
}
