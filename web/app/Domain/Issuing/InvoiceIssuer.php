<?php

namespace App\Domain\Issuing;

use App\Domain\Audit;
use App\Domain\Import\FatturaParser;
use App\Domain\Import\FatturaPaSchema;
use App\Domain\ManualInvoiceService;
use App\Domain\RegistrationService;
use App\Domain\RuleViolation;
use App\Domain\Scadenziario\ScheduleService;
use App\Models\Company;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\User;
use App\Models\XmlExport;
use App\Support\Decimal;
use App\Support\InvoiceIdentity;
use App\Support\Validation\CodiceFiscale;
use App\Support\Validation\Iban;
use App\Support\Validation\PartitaIva;
use Deved\FatturaElettronica\FatturaElettronica\FatturaElettronicaBody\DatiBeniServizi\DatiRiepilogo;
use Deved\FatturaElettronica\FatturaElettronica\FatturaElettronicaBody\DatiBeniServizi\DettaglioLinee;
use Deved\FatturaElettronica\FatturaElettronica\FatturaElettronicaBody\DatiBeniServizi\ScontoMaggiorazione as RigaSconto;
use Deved\FatturaElettronica\FatturaElettronica\FatturaElettronicaBody\DatiGenerali;
use Deved\FatturaElettronica\FatturaElettronica\FatturaElettronicaBody\DatiGenerali\DatiBollo;
use Deved\FatturaElettronica\FatturaElettronica\FatturaElettronicaBody\DatiGenerali\DatiDocumentoCorrelato;
use Deved\FatturaElettronica\FatturaElettronica\FatturaElettronicaBody\DatiGenerali\DatiRitenuta;
use Deved\FatturaElettronica\FatturaElettronica\FatturaElettronicaBody\DatiPagamento;
use Deved\FatturaElettronica\FatturaElettronica\FatturaElettronicaHeader\Common\DatiAnagrafici;
use Deved\FatturaElettronica\FatturaElettronica\FatturaElettronicaHeader\Common\Sede;
use Deved\FatturaElettronica\FatturaElettronicaFactory;
use DOMDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Emissione fattura elettronica (indicazione del committente, 20.11.2026): genera dai dati inseriti una FatturaPA (FPR12/FPA12) con la
 * libreria deved/fattura-elettronica, la verifica rispetto allo schema ufficiale, assegna il numero progressivo, crea la fattura,
 * la registra nel Registro vendite e crea le rate per lo Scadenziario. Tutto in una transazione: se un passo fallisce,
 * non viene assegnato alcun numero (sequenza senza lacune). La trasmissione al SdI non fa parte di Semplicon: il file XML viene
 * scaricato e inviato tramite il proprio portale, un intermediario o PEC.
 */
final class InvoiceIssuer
{
    public const DOC_TYPES = ['TD01' => 'Fattura', 'TD02' => 'Acconto/anticipo su fattura', 'TD03' => 'Acconto/anticipo su parcella', 'TD04' => 'Nota di credito', 'TD05' => 'Nota di debito', 'TD06' => 'Parcella'];

    public const PAYMENT_MODES = ['MP05' => 'Bonifico', 'MP08' => 'Carta di pagamento', 'MP01' => 'Contanti', 'MP02' => 'Assegno', 'MP12' => 'Ri.Ba.', 'MP19' => 'SEPA Direct Debit',
        'MP20' => 'SEPA Direct Debit CORE', 'MP21' => 'SEPA Direct Debit B2B', 'MP18' => 'Bollettino postale', 'MP22' => 'Trattenuta su somme già riscosse'];

    /** Modalità di pagamento per cui l'IBAN dell'azienda va inserito nel file. */
    private const BANK_MODES = ['MP05', 'MP19', 'MP20', 'MP21'];

    public const REGIMES = ['RF01' => 'Ordinario', 'RF02' => 'Contribuenti minimi', 'RF04' => 'Agricoltura e attività connesse e pesca', 'RF17' => 'IVA per cassa (art. 32-bis)',
        'RF18' => 'Altro', 'RF19' => 'Regime forfettario'];

    /** Bollo virtuale: 2,00 € su importi senza IVA superiori a 77,47 €. */
    public const BOLLO_AMOUNT = 200;

    public const BOLLO_THRESHOLD = 7747;

    /** Codici natura che possono far scattare il bollo (non imponibile, esente, escluse). */
    private const BOLLO_NATURE = ['N1', 'N2.1', 'N2.2', 'N3.5', 'N3.6', 'N4'];

    /** @return list<array{field: string, label: string}> dati aziendali mancanti o non validi per il cedente */
    public function companyIssues(Company $company): array
    {
        $out = [];
        if (! $company->partita_iva || ! PartitaIva::isValid((string) $company->partita_iva)) {
            $out[] = ['field' => 'partita_iva', 'label' => 'Partita IVA'];
        }
        if (! trim((string) $company->indirizzo)) {
            $out[] = ['field' => 'indirizzo', 'label' => 'Indirizzo della sede'];
        }
        if (! preg_match('/^\d{5}$/', (string) $company->cap)) {
            $out[] = ['field' => 'cap', 'label' => 'CAP (5 cifre)'];
        }
        if (! trim((string) $company->comune)) {
            $out[] = ['field' => 'comune', 'label' => 'Comune'];
        }
        if (! preg_match('/^[A-Za-z]{2}$/', (string) $company->provincia)) {
            $out[] = ['field' => 'provincia', 'label' => 'Provincia (sigla)'];
        }

        return $out;
    }

    /**
     * Anteprima prima dell'emissione: crea in memoria lo stesso file FatturaPA dell'emissione (numero provvisorio), senza numerare, registrare o salvare.
     * Gli errori di compilazione escono come nell'emissione.
     */
    public function previewXml(Company $company, array $d): string
    {
        if ($issues = $this->companyIssues($company)) {
            RuleViolation::fail(['company' => 'Completa prima i dati dell’azienda (Dati azienda): '.implode(', ', array_column($issues, 'label')).'.']);
        }
        $p = $this->prepare($company, $d);

        return $this->buildXml($company, $p, $this->peekNumber($company, (int) substr($p['doc_date'], 0, 4)), 'ANTEP')[0];
    }

    /** Si può preparare con un clic una nota di credito per questa fattura emessa? */
    public function canCredit(Invoice $invoice): bool
    {
        return $invoice->source === 'emessa' && $invoice->doc_type !== 'TD04' && $invoice->status->value === 'registrata' && is_array($invoice->issue_data);
    }

    /** Note di credito già emesse che rimandano a questa fattura (numero + data nel riferimento). @return list<Invoice> */
    public function creditNotesFor(Invoice $invoice): array
    {
        return Invoice::where('company_id', $invoice->company_id)->where('source', 'emessa')->where('doc_type', 'TD04')->get()
            ->filter(fn (Invoice $n) => ($n->issue_data['ref_number'] ?? null) === $invoice->number && ($n->issue_data['ref_date'] ?? null) === $invoice->doc_date->toDateString())->values()->all();
    }

    /**
     * Precompilazione per la nota di credito: stesse righe, cliente e opzioni della fattura originale (storno per l'importo pieno),
     * con riferimento a numero e data. L'utente controlla il modulo ed emette; note di credito parziali: adattare le righe.
     *
     * @return array{form: array<string, mixed>, warnings: list<string>, original: array<string, string>}
     */
    public function creditNoteDraft(Invoice $invoice): array
    {
        if (! $this->canCredit($invoice)) {
            RuleViolation::fail(['invoice' => 'Per questa fattura non si può preparare una nota di credito.']);
        }
        $d = $invoice->issue_data;
        $warnings = [];
        $lines = array_map(fn ($l) => ['description' => (string) ($l['description'] ?? ''), 'quantity' => (string) ($l['quantity'] ?? '1'), 'unit' => (string) ($l['unit'] ?? 'pz'), 'price' => (string) ($l['price'] ?? ''),
            'discount' => (string) ($l['discount'] ?? ''), 'rate' => (string) ($l['rate'] ?? '22'), 'natura' => (string) ($l['natura'] ?? '')], (array) ($d['lines'] ?? []));
        if (! empty($d['deducts'])) {
            $warnings[] = 'La fattura originale deduceva acconti: la nota di credito riprende le righe lorde. Controlla gli importi prima di emettere.';
        }
        $date = $invoice->doc_date->toDateString();

        return [
            'form' => [
                'doc_type' => 'TD04', 'regime' => $d['regime'] ?? 'RF01', 'account_id' => $d['account_id'] ?? null, 'payment_mode' => $d['payment_mode'] ?? 'MP05', 'bollo' => (bool) ($d['bollo'] ?? false),
                'split' => (bool) ($d['split'] ?? false), 'ref_number' => (string) $invoice->number, 'ref_date' => $date, 'ref_normativo' => (string) ($d['ref_normativo'] ?? ''),
                'causale' => mb_substr('Storno fattura n. '.$invoice->number.' del '.$invoice->doc_date->format('d/m/Y'), 0, 200),
                'customer' => (array) ($d['customer'] ?? []), 'lines' => $lines,
                'apply_ritenuta' => (bool) ($d['apply_ritenuta'] ?? false), 'apply_casse' => array_map('boolval', (array) ($d['apply_casse'] ?? [])),
            ],
            'warnings' => $warnings,
            'original' => ['number' => (string) $invoice->number, 'date' => $date, 'id' => (string) $invoice->id],
        ];
    }

    public function peekNumber(Company $company, int $year): string
    {
        $last = (int) DB::table('issuing_counters')->where('company_id', $company->id)->where('scope', (string) $year)->value('last_number');

        return ($last + 1).'/'.$year;
    }

    /**
     * @param  array<string, mixed>  $d  doc_type, doc_date, regime, account_id, causale, bollo, split, payment_mode, ref_number, ref_date, ref_normativo,
     *                                   customer{kind,name,first_name,last_name,vat,cf,country,address,zip,city,province,sdi_code,pec,is_pa},
     *                                   lines[{description,quantity,unit,price,rate,natura}], payments[{date,amount}]
     */
    public function issue(Company $company, User $actor, array $d): Invoice
    {
        if ($issues = $this->companyIssues($company)) {
            RuleViolation::fail(['company' => 'Completa prima i dati dell’azienda (Dati azienda): '.implode(', ', array_column($issues, 'label')).'.']);
        }
        $p = $this->prepare($company, $d);

        return DB::transaction(function () use ($company, $actor, $d, $p) {
            Company::whereKey($company->id)->lockForUpdate()->first();
            $year = (int) substr($p['doc_date'], 0, 4);
            $lastDate = Invoice::where('company_id', $company->id)->where('source', 'emessa')->whereBetween('doc_date', ["{$year}-01-01", "{$year}-12-31"])->max('doc_date');
            if ($lastDate && $p['doc_date'] < substr((string) $lastDate, 0, 10)) {
                RuleViolation::fail(['doc_date' => 'La data non può precedere l’ultima fattura emessa ('.date('d/m/Y', strtotime((string) $lastDate)).'): i numeri devono seguire l’ordine delle date.']);
            }
            $n = $this->next($company, (string) $year);
            $number = "{$n}/{$year}";
            $progressivo = str_pad(strtoupper(base_convert((string) $this->next($company, 'file'), 10, 36)), 5, '0', STR_PAD_LEFT);

            // 1. Creare la fattura come una fattura manuale (identità, riepiloghi, conti), poi passare all'emissione.
            $c = $p['customer'];
            // Imposta per gruppo (non per riga) e contributo cassa sono già nei gruppi.
            $invoice = app(ManualInvoiceService::class)->create($company, $actor, [
                'direction' => 'vendita', 'doc_type' => $p['doc_type'], 'number' => $number, 'doc_date' => $p['doc_date'], 'description' => $p['causale'],
                'counterparty_name' => $c['display_name'], 'counterparty_vat' => $c['vat'], 'counterparty_cf' => $c['cf'], 'counterparty_country' => $c['country'],
                'counterparty_address' => $c['address'], 'counterparty_zip' => $c['zip'], 'counterparty_city' => $c['city'], 'counterparty_province' => $c['province'],
                'lines' => array_map(fn ($g) => ['rate' => $g['rate'], 'natura' => $g['natura'], 'taxable' => Decimal::fromMinor($g['taxable']), 'tax' => Decimal::fromMinor($g['tax']),
                    'description' => $g['natura'] ? "Operazione {$g['natura']}" : "Imponibile {$g['rate']}%"], $p['groups']),
            ]);
            $invoice->lines()->delete();
            foreach ($p['lines'] as $i => $l) {
                $invoice->lines()->create(['line_no' => $i + 1, 'description' => mb_substr($l['description'], 0, 1000), 'quantity' => $l['quantity'], 'unit' => $l['unit'],
                    'unit_price' => $l['price'], 'total' => Decimal::fromMinor($l['total']), 'rate' => $l['rate'], 'natura' => $l['natura']]);
            }
            $invoice->update(['ritenuta' => Decimal::fromMinor($p['ritenuta']['amount'] ?? 0)]);
            foreach ($invoice->vatSummaries as $s) {
                $esig = $p['split'] && Decimal::toMinor((string) $s->rate) > 0 ? 'S' : 'I';
                $s->update(['esigibilita' => $esig, 'normative_reference' => $s->natura ? ($p['ref_normativo'] ?: null) : null]);
            }
            if ($p['doc_type'] !== 'TD04') {
                $invoice->installments()->delete();
                app(ScheduleService::class)->createFor($invoice->fresh(), array_map(fn ($r) => ['due_date' => $r['date'], 'amount' => Decimal::fromMinor($r['amount']), 'mode' => $p['payment_mode'],
                    'condition' => count($p['payments']) > 1 ? 'TP01' : 'TP02'], $p['payments']));
            }

            // 2. Generare l'XML con la libreria e verificarlo rispetto allo schema ufficiale.
            [$xml, $filename, $format] = $this->buildXml($company, $p, $number, $progressivo);
            $body = $this->verifyAgainstInvoice($xml, $invoice->fresh('vatSummaries'));
            $document = $this->storeXml($company, $actor, $xml, $filename);
            $invoice->update([
                'source' => 'emessa', 'format' => $format, 'document_id' => $document->id, 'xb_sdi' => true, 'issued_at' => now(), 'issued_by' => $actor->id,
                'business_hash' => InvoiceIdentity::businessHash([...$body['body'], 'supplier' => $body['supplier'], 'customer' => $body['customer']]),
                'content_hash' => $body['body']['content_hash'],
                'issue_data' => [...array_diff_key($d, ['customer' => 1]), 'customer' => $p['customer_input'], 'progressivo' => $progressivo, 'filename' => $filename, 'deducts' => $p['deducts'],
                    'ritenuta' => $p['ritenuta'], 'casse' => $p['casse']],
            ]);

            // 3. Registro vendite: periodo dalla data del documento, un conto di ricavo per tutti i gruppi.
            $sp = app(RegistrationService::class)->suggestedPeriod($company, $p['doc_date']);
            if (! $sp) {
                RuleViolation::fail(['doc_date' => "Nessun regime IVA confermato per il {$year}: confermalo in Regime fiscale e IVA prima di emettere."]);
            }
            app(RegistrationService::class)->register($company, $invoice->fresh(), $actor, [
                'registration_date' => $p['doc_date'], 'year' => $sp['year'], 'period' => $sp['period'],
                'lines' => array_fill(0, $invoice->vatSummaries()->count(), ['account_id' => $p['account_id'], 'deductible_pct' => '']), 'acknowledged' => [],
            ]);

            XmlExport::create(['company_id' => $company->id, 'invoice_id' => $invoice->id, 'doc_type' => $p['doc_type'], 'version' => 1, 'filename' => $filename, 'content' => $xml,
                'sha256' => hash('sha256', $xml), 'generated_at' => now(), 'created_by' => $actor->id]);
            Audit::record('invoice.issued', $actor, $company, $invoice, data: ['number' => $number, 'doc_type' => $p['doc_type'], 'customer' => $c['display_name'], 'total' => Decimal::fromMinor($p['total']), 'file' => $filename]);

            return $invoice->fresh();
        });
    }

    /** Verifica i dati inseriti e calcola tutti gli importi; in caso di errori non viene creato nulla. */
    private function prepare(Company $company, array $d): array
    {
        [$p, $e] = $this->build($company, $d);
        if ($e) {
            RuleViolation::fail($e);
        }

        return $p;
    }

    /**
     * Anteprima per il modulo: stesso calcolo dell'emissione, ma tollerante verso dati incompleti.
     *
     * @return array{lines: list<string>, groups: list<array>, casse: list<array>, ritenuta: ?array, taxable: string, tax: string, total: string, payable: string, exempt: string, errors: array<string, string>}
     */
    public function quote(Company $company, array $d): array
    {
        [$p, $e] = $this->build($company, $d);
        $f = fn (int $c) => Decimal::fromMinor($c);

        return [
            'lines' => array_map(fn ($l) => $l === null ? null : $f($l['total']), $p['all_lines'] ?? []),
            'groups' => array_map(fn ($g) => ['rate' => $g['rate'], 'natura' => $g['natura'], 'taxable' => $f($g['taxable']), 'tax' => $f($g['tax'])], $p['groups'] ?? []),
            'casse' => array_map(fn ($c) => [...$c, 'contribution' => $f($c['contribution']), 'base' => $f($c['base'])], $p['casse'] ?? []),
            'ritenuta' => ($p['ritenuta'] ?? null) ? ['amount' => $f($p['ritenuta']['amount']), 'base' => $f($p['ritenuta']['base']), 'rate' => $p['ritenuta']['rate']] : null,
            'taxable' => $f($p['taxable'] ?? 0), 'tax' => $f($p['tax'] ?? 0), 'total' => $f($p['total'] ?? 0), 'payable' => $f($p['payable'] ?? 0), 'exempt' => $f($p['exempt'] ?? 0),
            'errors' => array_map(fn ($m) => (string) $m, $e),
        ];
    }

    /** Totale netto di una riga in centesimi: quantità (centesimi) × prezzo (centesimi) × (1 − sconto), arrotondato una sola volta; senza overflow a 64 bit. */
    public static function lineNet(int $qty100, int $priceCents, int $discount100): int
    {
        $gross = $qty100 * $priceCents;            // unità 1/10000 €
        $k = 10000 - $discount100;                 // quota residua in centesimi di punto percentuale
        $hi = intdiv($gross, 1000000);
        $lo = $gross % 1000000;

        return $hi * $k + intdiv($lo * $k + 500000, 1000000);
    }

    /**
     * Scompone i dati inseriti in righe verificate e calcola gruppi, cassa, ritenuta e importo da pagare.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>} [dati, errori]
     */
    private function build(Company $company, array $d): array
    {
        $e = [];
        $docType = (string) ($d['doc_type'] ?? '');
        if (! isset(self::DOC_TYPES[$docType])) {
            $e['doc_type'] = 'Scegli il tipo di documento.';
        }
        $date = (string) ($d['doc_date'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
            $e['doc_date'] = 'Indica la data della fattura.';
        } elseif ($date > now()->toDateString()) {
            $e['doc_date'] = 'La data della fattura non può essere nel futuro.';
        }
        $regime = (string) ($d['regime'] ?? 'RF01');
        if (! isset(self::REGIMES[$regime])) {
            $e['regime'] = 'Regime fiscale non valido.';
        }
        $mode = (string) ($d['payment_mode'] ?? 'MP05');
        if (! isset(self::PAYMENT_MODES[$mode])) {
            $e['payment_mode'] = 'Modalità di pagamento non valida.';
        }
        $causale = trim((string) ($d['causale'] ?? ''));
        if (mb_strlen($causale) > 200) {
            $e['causale'] = 'La causale può avere al massimo 200 caratteri.';
        }

        [$cust, $ce] = $this->customer((array) ($d['customer'] ?? []));
        $e += $ce;
        $isPa = $cust['is_pa'];
        $split = ! empty($d['split']);
        if ($split && ! $isPa) {
            $e['split'] = 'La scissione dei pagamenti vale solo per la Pubblica Amministrazione.';
        }

        $account = $company->accounts()->whereKey((int) ($d['account_id'] ?? 0))->first();
        if (! $account || ! $account->active || ! $account->iva_rilevante || $account->type !== 'ricavo') {
            $e['account_id'] = 'Scegli un conto di ricavo attivo e rilevante ai fini IVA.';
        }

        // Righe: sconto per riga, somma intera (vedi lineNet)
        $lines = [];
        $all = [];
        $rawLines = array_values((array) ($d['lines'] ?? []));
        if (! $rawLines) {
            $e['lines'] = 'Inserisci almeno una riga.';
        }
        foreach ($rawLines as $i => $l) {
            $desc = trim((string) ($l['description'] ?? ''));
            $qtyRaw = str_replace(',', '.', trim((string) ($l['quantity'] ?? '1'))) ?: '1';
            $priceRaw = str_replace(',', '.', trim((string) ($l['price'] ?? '')));
            $rate = str_replace(',', '.', trim((string) ($l['rate'] ?? '22'))) ?: '0';
            $discRaw = str_replace(',', '.', trim((string) ($l['discount'] ?? ''))) ?: '0';
            $natura = ($l['natura'] ?? '') ?: null;
            $bad = false;
            $err = function (string $key, string $msg) use (&$e, $i, &$bad) {
                $e["lines.{$i}.{$key}"] = $msg;
                $bad = true;
            };
            if ($desc === '') {
                $err('description', 'Indica la descrizione.');
            }
            if (! preg_match('/^\d{1,6}(\.\d{1,2})?$/', $qtyRaw) || (float) $qtyRaw <= 0) {
                $err('quantity', 'Quantità non valida (massimo 2 decimali).');
            }
            if (! preg_match('/^\d{1,8}(\.\d{1,2})?$/', $priceRaw)) {
                $err('price', 'Prezzo non valido (es. 150,00, massimo 2 decimali).');
            }
            if (! preg_match('/^\d{1,2}(\.\d{1,2})?$/', $rate)) {
                $err('rate', 'Aliquota non valida.');
            }
            if (! preg_match('/^(100(\.0{1,2})?|\d{1,2}(\.\d{1,2})?)$/', $discRaw)) {
                $err('discount', 'Sconto non valido (0–100 %).');
            }
            if ($natura !== null && FatturaPaSchema::label('NaturaType', $natura) === null) {
                $err('natura', 'Natura non valida.');
            } elseif ($natura !== null && (float) $rate !== 0.0) {
                $err('rate', 'Con la natura l’aliquota è 0.');
            } elseif ($natura === null && (float) $rate === 0.0 && ! $bad) {
                $err('natura', 'Senza imposta indica la natura (es. N2.2, N4).');
            }
            if ($bad) {
                $all[$i] = null;

                continue;
            }
            $unit = mb_substr(trim((string) ($l['unit'] ?? '')) ?: 'pz', 0, 10);
            $net = self::lineNet(Decimal::toMinor($qtyRaw), Decimal::toMinor($priceRaw), Decimal::toMinor($discRaw));
            $row = ['kind' => 'row', 'description' => $desc, 'quantity' => Decimal::normalize($qtyRaw), 'unit' => $unit, 'price' => Decimal::normalize($priceRaw), 'discount' => Decimal::normalize($discRaw),
                'rate' => Decimal::normalize($rate), 'natura' => $natura, 'total' => $net];
            $lines[] = $row;
            $all[$i] = $row;
        }

        // Gli acconti (TD02/TD03) della stessa azienda e dello stesso cliente vengono detratti come righe negative.
        $deducts = [];
        foreach (array_values(array_unique(array_map('intval', (array) ($d['acconti'] ?? [])))) as $k => $aid) {
            $a = $this->acconto($company, $aid, $cust);
            if (! $a) {
                $e["acconti.{$k}"] = 'Acconto non disponibile: deve essere una tua fattura TD02/TD03 dello stesso cliente, non ancora dedotta.';

                continue;
            }
            $deducts[] = $aid;
            foreach ($a->lines()->orderBy('line_no')->get() as $al) {
                $amount = Decimal::toMinor((string) $al->total);
                $lines[] = ['kind' => 'acconto', 'description' => mb_substr("Acconto fattura {$a->number} del ".$a->doc_date->format('d/m/Y').': '.$al->description, 0, 1000), 'quantity' => '1.00', 'unit' => 'pz',
                    'price' => Decimal::fromMinor(-$amount), 'discount' => '0.00', 'rate' => Decimal::normalize((string) $al->rate), 'natura' => $al->natura, 'total' => -$amount];
            }
        }

        $settings = IssuingSettings::for($company);
        $calc = $this->amounts($lines, $settings, ! empty($d['apply_ritenuta']), (array) ($d['apply_casse'] ?? []), $split && $isPa);
        foreach ($calc['groups'] as $g) {
            if ($g['taxable'] < 0) {
                $e['lines'] = 'Le deduzioni degli acconti superano l’imponibile di un’aliquota: controlla le righe.';
            }
        }
        if ($lines && $calc['taxable'] <= 0 && ! isset($e['lines'])) {
            $e['lines'] = 'L’importo della fattura deve essere maggiore di zero.';
        }

        // Bollo
        $bollo = ! empty($d['bollo']);
        $exempt = array_sum(array_map(fn ($l) => in_array($l['natura'], self::BOLLO_NATURE, true) ? $l['total'] : 0, $lines));
        if ($bollo && $exempt <= self::BOLLO_THRESHOLD) {
            $e['bollo'] = 'Il bollo si applica a importi senza IVA superiori a 77,47 € (nature N1, N2, N3.5, N3.6, N4).';
        }

        // Pagamento
        $payments = [];
        $noPay = in_array($docType, ['TD04'], true);
        if (! $noPay) {
            $sum = 0;
            foreach (array_values((array) ($d['payments'] ?? [])) as $i => $r) {
                $pd = (string) ($r['date'] ?? '');
                $pa = str_replace(',', '.', trim((string) ($r['amount'] ?? '')));
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $pd) || ($date !== '' && $pd < $date)) {
                    $e["payments.{$i}.date"] = 'La scadenza non può precedere la data della fattura.';
                }
                if (! preg_match('/^\d{1,9}(\.\d{1,2})?$/', $pa) || Decimal::toMinor($pa) <= 0) {
                    $e["payments.{$i}.amount"] = 'Importo non valido.';

                    continue;
                }
                $payments[] = ['date' => $pd, 'amount' => Decimal::toMinor($pa)];
                $sum += Decimal::toMinor($pa);
            }
            if (! $payments) {
                $e['payments'] = 'Indica almeno una scadenza di pagamento.';
            } elseif ($sum !== $calc['payable'] && ! preg_grep('/^(lines|acconti)/', array_keys($e))) {
                $e['payments'] = 'Le rate devono sommare '.str_replace('.', ',', Decimal::fromMinor($calc['payable'])).' € (importo da pagare); sono '.str_replace('.', ',', Decimal::fromMinor($sum)).' €.';
            }
        }

        return [[
            'doc_type' => $docType, 'doc_date' => $date, 'regime' => $regime, 'account_id' => $account?->id, 'causale' => $causale ?: null, 'bollo' => $bollo, 'split' => $split && $isPa,
            'payment_mode' => $mode, 'payments' => $payments, 'lines' => $lines, 'all_lines' => $all, 'deducts' => $deducts, 'exempt' => $exempt,
            'groups' => $calc['groups'], 'casse' => $calc['casse'], 'ritenuta' => $calc['ritenuta'], 'taxable' => $calc['taxable'], 'tax' => $calc['tax'], 'total' => $calc['total'], 'payable' => $calc['payable'],
            'ref_number' => trim((string) ($d['ref_number'] ?? '')), 'ref_date' => trim((string) ($d['ref_date'] ?? '')), 'ref_normativo' => mb_substr(trim((string) ($d['ref_normativo'] ?? '')), 0, 100),
            'customer' => $cust['out'], 'customer_input' => $cust['input'],
        ], $e];
    }

    /**
     * Gruppi per aliquota/natura, contributi cassa, ritenuta e importo da pagare in centesimi.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{groups: list<array>, casse: list<array>, ritenuta: ?array, taxable: int, tax: int, total: int, payable: int}
     */
    private function amounts(array $lines, array $settings, bool $applyRitenuta, array $applyCasse, bool $split): array
    {
        $pct = fn (int $cents, string $rate) => RegistrationService::percentOf($cents, Decimal::toMinor($rate));
        $net = array_sum(array_column($lines, 'total'));   // onorario netto dopo sconto e detrazione degli acconti
        $groups = [];
        $add = function (string $rate, ?string $natura, int $taxable) use (&$groups) {
            $k = $natura ?? 'R'.$rate;
            $groups[$k] ??= ['rate' => $rate, 'natura' => $natura, 'taxable' => 0, 'tax' => 0];
            $groups[$k]['taxable'] += $taxable;
        };
        foreach ($lines as $l) {
            $add($l['rate'], $l['natura'], $l['total']);
        }
        $casse = [];
        foreach ($settings['casse'] as $i => $c) {
            if (empty($applyCasse[$i]) || $net <= 0 || (float) $c['rate'] <= 0) {
                continue;
            }
            $base = $pct($net, $c['base_pct']);
            $contribution = $pct($base, $c['rate']);
            if ($c['cap'] !== '' && $contribution > Decimal::toMinor($c['cap'])) {
                $contribution = Decimal::toMinor($c['cap']);
            }
            if ($contribution <= 0) {
                continue;
            }
            $natura = (float) $c['vat_rate'] === 0.0 ? ($c['natura'] ?: null) : null;
            $casse[] = ['type' => $c['type'], 'rate' => Decimal::normalize($c['rate']), 'base' => $base, 'contribution' => $contribution, 'vat_rate' => Decimal::normalize($c['vat_rate']), 'natura' => $natura, 'ritenuta' => (bool) $c['ritenuta']];
            $add(Decimal::normalize($c['vat_rate']), $natura, $contribution);
        }
        $taxable = $tax = 0;
        foreach ($groups as $k => $g) {
            $groups[$k]['tax'] = $pct($g['taxable'], $g['rate']);   // Imposta per gruppo, non per riga (il SdI verifica imponibile × aliquota)
            $taxable += $g['taxable'];
            $tax += $groups[$k]['tax'];
        }
        $splitTax = $split ? array_sum(array_map(fn ($g) => (float) $g['rate'] > 0 ? $g['tax'] : 0, $groups)) : 0;
        $ritenuta = null;
        $r = $settings['ritenuta'];
        if ($applyRitenuta && $r['enabled'] && (float) $r['rate'] > 0) {
            $rbase = $net + array_sum(array_map(fn ($c) => $c['ritenuta'] ? $c['contribution'] : 0, $casse));
            $imponibile = $pct(max(0, $rbase), $r['base_pct']);
            $amount = $pct($imponibile, $r['rate']);
            if ($amount > 0) {
                $ritenuta = ['type' => $r['type'], 'rate' => Decimal::normalize($r['rate']), 'causale' => $r['causale'], 'base' => $imponibile, 'amount' => $amount];
            }
        }

        return ['groups' => array_values($groups), 'casse' => $casse, 'ritenuta' => $ritenuta, 'taxable' => $taxable, 'tax' => $tax, 'total' => $taxable + $tax,
            'payable' => $taxable + $tax - ($ritenuta['amount'] ?? 0) - $splitTax];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} Dati del cliente (normalizzati) ed errori */
    private function customer(array $in): array
    {
        $e = [];
        $country = strtoupper(trim((string) ($in['country'] ?? 'IT'))) ?: 'IT';
        $person = ($in['kind'] ?? 'azienda') === 'persona';
        $name = trim((string) ($in['name'] ?? ''));
        $first = trim((string) ($in['first_name'] ?? ''));
        $last = trim((string) ($in['last_name'] ?? ''));
        $display = $person ? trim("{$first} {$last}") : $name;
        if (! preg_match('/^[A-Z]{2}$/', $country)) {
            $e['customer.country'] = 'Indica il paese con due lettere (es. IT, DE).';
        }
        if ($person ? ($first === '' || $last === '') : $name === '') {
            $e[$person ? 'customer.first_name' : 'customer.name'] = $person ? 'Indica nome e cognome.' : 'Indica la denominazione del cliente.';
        }
        $vatRaw = strtoupper(preg_replace('/\s+/', '', (string) ($in['vat'] ?? '')));
        $cf = strtoupper(preg_replace('/\s+/', '', (string) ($in['cf'] ?? '')));
        $vat = null;
        if ($vatRaw !== '') {
            $code = str_starts_with($vatRaw, $country) ? substr($vatRaw, 2) : $vatRaw;
            if ($country === 'IT' && ! PartitaIva::isValid($code)) {
                $e['customer.vat'] = 'La partita IVA del cliente non è valida (cifra di controllo errata).';
            } elseif ($country !== 'IT' && ! preg_match('/^[A-Z0-9]{2,28}$/', $code)) {
                $e['customer.vat'] = 'Identificativo fiscale estero non valido.';
            }
            $vat = $country.$code;
        }
        if ($cf !== '' && $country === 'IT' && ! CodiceFiscale::isValid($cf) && ! preg_match('/^\d{11}$/', $cf)) {
            $e['customer.cf'] = 'Il codice fiscale non è valido.';
        }
        if ($country === 'IT' && $vat === null && $cf === '') {
            $e['customer.vat'] = 'Indica la partita IVA o il codice fiscale del cliente.';
        }
        if ($country !== 'IT' && $vat === null) {
            $e['customer.vat'] = 'Per un cliente estero indica l’identificativo fiscale (partita IVA estera).';
        }
        $address = trim((string) ($in['address'] ?? ''));
        $zip = trim((string) ($in['zip'] ?? ''));
        $city = trim((string) ($in['city'] ?? ''));
        $province = strtoupper(trim((string) ($in['province'] ?? '')));
        if ($address === '') {
            $e['customer.address'] = 'Indica l’indirizzo del cliente.';
        }
        if ($city === '') {
            $e['customer.city'] = 'Indica il comune o la città del cliente.';
        }
        if ($country === 'IT') {
            if (! preg_match('/^\d{5}$/', $zip)) {
                $e['customer.zip'] = 'Il CAP ha 5 cifre.';
            }
            if (! preg_match('/^[A-Z]{2}$/', $province)) {
                $e['customer.province'] = 'Indica la sigla della provincia (es. MI).';
            }
        } else {
            $zip = '00000';
            $province = '';
        }
        $isPa = ! empty($in['is_pa']);
        $sdi = strtoupper(trim((string) ($in['sdi_code'] ?? '')));
        $pec = trim((string) ($in['pec'] ?? ''));
        if ($isPa && ! preg_match('/^[A-Z0-9]{6}$/', $sdi)) {
            $e['customer.sdi_code'] = 'Per la Pubblica Amministrazione indica il codice univoco ufficio (6 caratteri).';
        } elseif (! $isPa && $country === 'IT' && $sdi !== '' && ! preg_match('/^[A-Z0-9]{7}$/', $sdi)) {
            $e['customer.sdi_code'] = 'Il codice destinatario ha 7 caratteri.';
        }
        if ($pec !== '' && ! filter_var($pec, FILTER_VALIDATE_EMAIL)) {
            $e['customer.pec'] = 'Indirizzo PEC non valido.';
        }
        if ($isPa && $country !== 'IT') {
            $e['customer.is_pa'] = 'La Pubblica Amministrazione italiana ha sede in Italia.';
        }

        return [[
            'is_pa' => $isPa, 'vat' => $vat, 'cf' => $cf ?: null,
            'out' => ['display_name' => $display, 'name' => $name, 'first_name' => $first, 'last_name' => $last, 'person' => $person, 'vat' => $vat, 'cf' => $cf ?: null, 'country' => $country,
                'address' => $address, 'zip' => $zip, 'city' => $city, 'province' => $province, 'sdi_code' => $sdi ?: null, 'pec' => $pec ?: null, 'is_pa' => $isPa],
            'input' => ['kind' => $person ? 'persona' : 'azienda', 'name' => $name, 'first_name' => $first, 'last_name' => $last, 'vat' => $vat, 'cf' => $cf ?: null, 'country' => $country,
                'address' => $address, 'zip' => $in['zip'] ?? $zip, 'city' => $city, 'province' => $province, 'sdi_code' => $sdi ?: null, 'pec' => $pec ?: null, 'is_pa' => $isPa],
        ], $e];
    }

    /** Fattura di acconto emessa (TD02/TD03) dello stesso cliente, non ancora detratta. */
    public function acconto(Company $company, int $id, array $cust): ?Invoice
    {
        $a = Invoice::where('company_id', $company->id)->where('source', 'emessa')->whereIn('doc_type', ['TD02', 'TD03'])->whereKey($id)->first();
        if (! $a || ! (($cust['vat'] && $a->customer_vat === $cust['vat']) || ($cust['cf'] && $a->customer_cf === $cust['cf']))) {
            return null;
        }

        return in_array($id, $this->deductedIds($company), true) ? null : $a;
    }

    /** @return list<int> */
    public function deductedIds(Company $company): array
    {
        return Invoice::where('company_id', $company->id)->where('source', 'emessa')->whereNotNull('issue_data')->get(['issue_data'])
            ->flatMap(fn ($i) => (array) ($i->issue_data['deducts'] ?? []))->map(fn ($v) => (int) $v)->all();
    }

    /** @return array{0: string, 1: string, 2: string} XML, nome del file, formato (FPR12|FPA12) */
    private function buildXml(Company $company, array $p, string $number, string $progressivo): array
    {
        $c = $p['customer'];
        $cfCedente = $company->codice_fiscale ?: $company->partita_iva;
        $cedente = new DatiAnagrafici($cfCedente, $company->name, 'IT', (string) $company->partita_iva, $p['regime']);
        if ($company->tipologia->value !== 'societa' && trim((string) $company->nome) !== '' && trim((string) $company->cognome) !== '') {
            $cedente = new PersonAnagrafica($cfCedente, trim((string) $company->nome), trim((string) $company->cognome), 'IT', (string) $company->partita_iva, $p['regime']);
        }
        $sedeCedente = new Sede('IT', trim($company->indirizzo.' '.$company->numero_civico), (string) $company->cap, (string) $company->comune, strtoupper((string) $company->provincia));
        $contact = fn (array $types) => (string) ($company->contacts()->whereIn('type', $types)->first()?->value ?? '');
        $factory = new FatturaElettronicaFactory($cedente, $sedeCedente, $contact(['telefono', 'cellulare']), $contact(['email']));

        $vatCode = $c['vat'] ? substr($c['vat'], 2) : '';
        $vatPaese = $c['vat'] ? $c['country'] : '';
        $cust = $c['person'] ? new PersonAnagrafica($c['cf'] ?? '', $c['first_name'], $c['last_name'], $vatPaese, $vatCode) : new DatiAnagrafici($c['cf'] ?? '', $c['name'], $vatPaese, $vatCode);
        $factory->setCessionarioCommittente($cust, new Sede($c['country'], $c['address'], $c['zip'], $c['city'], $c['province']), $c['sdi_code'], $c['pec'], $c['is_pa']);

        $datiGenerali = new DatiGenerali($p['doc_type'], $p['doc_date'], $number, $p['total'] / 100);
        if ($p['causale']) {
            $datiGenerali->setCausale($p['causale']);
        }
        if ($p['ritenuta']) {
            $datiGenerali->setDatiRitenuta(new DatiRitenuta($p['ritenuta']['type'], $p['ritenuta']['amount'] / 100, (float) $p['ritenuta']['rate'], $p['ritenuta']['causale']));
        }
        if ($p['bollo']) {
            $datiGenerali->setDatiBollo(new DatiBollo('SI', self::BOLLO_AMOUNT / 100));
        }
        if ($p['casse']) {
            $datiGenerali->setDatiCassaPrevidenziale(new CassaBlock($p['casse']));
        }
        if ($p['doc_type'] === 'TD04' && $p['ref_number'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['ref_date'])) {
            $datiGenerali->setDatiDocumentoCorrelato(new DatiDocumentoCorrelato(DatiDocumentoCorrelato::FattureCollegate, $p['ref_number'], $p['ref_date']));
        }

        $pay = null;
        if ($p['payments']) {
            $bank = in_array($p['payment_mode'], self::BANK_MODES, true) ? $company->bankAccounts()->orderByDesc('is_default')->first() : null;
            $iban = $bank && Iban::isValid((string) $bank->iban) ? Iban::normalize((string) $bank->iban) : null;
            $cond = count($p['payments']) > 1 ? 'TP01' : 'TP02';
            foreach ($p['payments'] as $k => $r) {
                $block = new DatiPagamento($p['payment_mode'], $r['date'], $r['amount'] / 100, $iban, $iban ? ($bank->bank_name ?: null) : null, $cond);
                if ($k === 0) {
                    $pay = $block;
                } else {
                    $pay->addBlock($block);
                }
            }
        }

        // Linee: prezzo e somma da Semplicon; lo sconto percentuale viene solo indicato.
        $linee = [];
        foreach ($p['lines'] as $l) {
            $linea = (new IssuedLinea($l['description'], (float) $l['price'], null, (float) $l['quantity'], $l['unit'], (float) $l['rate'], 'FORN', 2, null, $l['natura']))
                ->withNet(Decimal::fromMinor($l['total']));
            if ((float) $l['discount'] > 0) {
                $linea->setScontoMaggiorazione(new RigaSconto(RigaSconto::SCONTO, (float) $l['discount'], null));
            }
            $linee[] = $linea;
        }

        // Riepilogo per aliquota/natura dai gruppi (Imposta dai centesimi, compreso il contributo cassa).
        $riepilogo = null;
        foreach ($p['groups'] as $g) {
            $r = new DatiRiepilogo($g['taxable'] / 100, (float) $g['rate'], $p['split'] && (float) $g['rate'] > 0 ? 'S' : 'I', $g['tax'] / 100);
            if ($g['natura']) {
                $r->Natura = $g['natura']; // la libreria scrive Natura nel punto giusto solo con questa chiave
                if ($p['ref_normativo'] !== '') {
                    $r->riferimentoNormativo = $p['ref_normativo'];
                }
            }
            if ($riepilogo === null) {
                $riepilogo = $r;
            } else {
                $riepilogo->addDatiRiepilogo($r);
            }
        }

        $fattura = $factory->create($datiGenerali, $pay, new DettaglioLinee($linee), $progressivo, $riepilogo);
        try {
            $fattura->verifica();
        } catch (\Throwable $ex) {
            RuleViolation::fail(['xml' => 'La fattura non rispetta lo schema ufficiale FatturaPA: '.mb_substr($ex->getMessage(), 0, 400)]);
        }

        return [$fattura->toXml(), $fattura->getFileName(), $c['is_pa'] ? 'FPA12' : 'FPR12'];
    }

    /** Il file generato deve coincidere con gli importi registrati; restituisce: contenuto letto per il rilevamento dei duplicati. */
    private function verifyAgainstInvoice(string $xml, Invoice $invoice): array
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $parsed = app(FatturaParser::class)->parse($dom);
        $body = $parsed['bodies'][0];
        if (Decimal::toMinor($body['taxable_total']) !== Decimal::toMinor((string) $invoice->taxable_total) || Decimal::toMinor($body['tax_total']) !== Decimal::toMinor((string) $invoice->tax_total)
            || Decimal::toMinor((string) ($body['ritenuta'] ?? '0')) !== Decimal::toMinor((string) ($invoice->ritenuta ?? '0'))) {
            RuleViolation::fail(['xml' => 'Gli importi del file XML non coincidono con la fattura registrata: nessuna fattura emessa.']);
        }

        return ['body' => $body, 'supplier' => $parsed['supplier'], 'customer' => $parsed['customer']];
    }

    private function storeXml(Company $company, User $actor, string $xml, string $filename): Document
    {
        $sha = hash('sha256', $xml);
        if ($existing = $company->documents()->where('sha256', $sha)->first()) {
            return $existing;
        }
        $path = "attachments/{$company->id}/".substr($sha, 0, 2)."/{$sha}.xml";
        Storage::disk('local')->put($path, $xml);

        return Document::create(['company_id' => $company->id, 'original_name' => $filename, 'sha256' => $sha, 'size' => strlen($xml), 'kind' => 'xml', 'storage_path' => $path, 'uploaded_by' => $actor->id]);
    }

    private function next(Company $company, string $scope): int
    {
        $row = DB::table('issuing_counters')->where('company_id', $company->id)->where('scope', $scope)->lockForUpdate()->first();
        if (! $row) {
            DB::table('issuing_counters')->insert(['company_id' => $company->id, 'scope' => $scope, 'last_number' => 1, 'created_at' => now(), 'updated_at' => now()]);

            return 1;
        }
        DB::table('issuing_counters')->where('id', $row->id)->update(['last_number' => $row->last_number + 1, 'updated_at' => now()]);

        return $row->last_number + 1;
    }
}
