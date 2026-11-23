<?php

namespace Tests\Feature;

use App\Domain\CompanyService;
use App\Domain\Import\InvoiceImporter;
use App\Domain\Import\Reprocessor;
use App\Domain\Liquidazioni\CreditService;
use App\Domain\Liquidazioni\SettlementCalculator;
use App\Domain\Liquidazioni\SettlementService;
use App\Domain\RegistrationService;
use App\Domain\Scadenziario\Ledger;
use App\Domain\Scadenziario\PaymentService;
use App\Domain\VatProfileService;
use App\Domain\VatRegisterService;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\Installment;
use App\Models\Invoice;
use App\Models\InvoiceIntegration;
use App\Models\InvoiceReprocessing;
use App\Models\InvoiceVersion;
use App\Models\User;
use App\Models\VatCredit;
use App\Models\VatRegistration;
use App\Models\XmlExport;
use App\Support\Decimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTenants;
use Tests\Support\FatturaBuilder;
use Tests\TestCase;

/** Incarico di sviluppo «Fatture e IVA» (19.11.2026): test di accettazione. */
class CoreWorkflowTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-11-20 10:00:00');
        $this->owner = $this->customer();
        $this->company = app(CompanyService::class)->create($this->owner, ['name' => 'Alpha', 'tipologia' => 'societa', 'partita_iva' => '01234567897']);
        $svc = app(VatProfileService::class);
        $d = $svc->createDraft($this->company, $this->owner, 2026);
        $svc->updateDraft($this->company, $d, $this->owner, ['regime' => 'semplificata', 'method' => 'cassa', 'periodicity' => 'mensile']);
        $svc->confirm($this->company, $d->fresh(), $this->owner);
    }

    private function import(string $xml, string $mode = 'salta', string $name = 'f.xml'): ImportItem
    {
        $batch = ImportBatch::create(['company_id' => $this->company->id, 'created_by' => $this->owner->id, 'status' => 'in_coda', 'mode' => $mode]);
        $item = ImportItem::create(['import_batch_id' => $batch->id, 'company_id' => $this->company->id, 'filename' => $name, 'status' => 'in_attesa']);
        app(InvoiceImporter::class)->import($item, $xml, $this->owner->id);

        return $item->fresh();
    }

    private function purchaseXml(string $no = 'A1', array $summaries = [['rate' => '22.00', 'taxable' => '100.00', 'tax' => '22.00']], string $date = '2026-03-10'): string
    {
        return FatturaBuilder::make()->supplier('IT11111111115', name: 'Fornitore')->customer('IT01234567897')->body($no, $date, summaries: $summaries)->xml();
    }

    private function register(Invoice $inv, string $date = '2026-03-12', int $period = 3): void
    {
        $acc = $this->company->accounts()->where('type', 'costo')->where('is_default', true)->value('id');
        app(RegistrationService::class)->register($this->company, $inv->fresh(), $this->owner, ['receipt_date' => $date, 'registration_date' => $date, 'year' => 2026, 'period' => $period,
            'lines' => $inv->vatSummaries->map(fn () => ['account_id' => $acc, 'deductible_pct' => '100'])->all(), 'acknowledged' => ['*']]);
    }

    public function test_01_archive_reimport_reprocess_and_restore(): void
    {
        $xml = $this->purchaseXml();
        $this->import($xml);
        $inv = Invoice::firstOrFail();
        $doc = $inv->document_id;
        $this->actingAs($this->owner)->delete("/aziende/{$this->company->id}/fatture/{$inv->id}")->assertRedirect();
        $this->assertSame('archiviata', $inv->fresh()->status->value);
        // L'importazione collettiva e la rielaborazione saltano il documento archiviato
        $this->assertSame('archiviata', $this->import($xml)->results[0]['status']);
        $this->assertSame('archiviata', $this->import($xml, 'rielabora')->results[0]['status']);
        $this->assertSame(1, Invoice::count());
        $this->get("/aziende/{$this->company->id}/acquisti?stato=aperte")->assertInertia(fn ($p) => $p->has('invoices.data', 0));
        // Ripristina: stesso ID con originale
        $this->post("/aziende/{$this->company->id}/fatture/{$inv->id}/ripristina-archivio")->assertRedirect("/aziende/{$this->company->id}/fatture/{$inv->id}");
        $this->assertSame(['importata', $doc], [$inv->fresh()->status->value, $inv->fresh()->document_id]);
        $this->get("/aziende/{$this->company->id}/acquisti?stato=aperte")->assertInertia(fn ($p) => $p->has('invoices.data', 1)->where('invoices.data.0.id', $inv->id));
        // Fattura registrata: nessun Elimina, solo Storna
        $this->register($inv);
        $this->delete("/aziende/{$this->company->id}/fatture/{$inv->id}")->assertSessionHasErrors('invoice');
    }

    public function test_02_byte_identical_xml_reprocessed_after_importer_fix(): void
    {
        $xml = $this->purchaseXml('A2', [['rate' => '22.00', 'taxable' => '100.00', 'tax' => '22.00'], ['rate' => '0.00', 'natura' => 'N2.2', 'taxable' => '20.00', 'tax' => '0.00']]);
        $this->import($xml);
        $inv = Invoice::firstOrFail();
        $doc = $inv->document_id;
        // Riprodurre l'errore del vecchio importatore: manca la riga N2.2.
        $inv->lines()->where('natura', 'N2.2')->delete();
        $this->assertSame(1, $inv->lines()->count());
        app(PaymentService::class)->record($this->company, $this->owner, ['kind' => 'pagamento', 'movement_date' => '2026-03-20', 'amount' => '50.00',
            'allocations' => [['installment_id' => $inv->installments()->first()->id, 'amount' => '50.00']]]);

        $res = $this->import($xml, 'rielabora')->results[0];
        $this->assertSame('modificato', $res['status']);
        $r = InvoiceReprocessing::findOrFail($res['reprocessing_id']);
        $this->assertSame([['line_no' => 2, 'change' => 'aggiunta']], array_map(fn ($l) => ['line_no' => $l['line_no'], 'change' => $l['change']], $r->diff['lines']));
        $this->assertSame([], $r->diff['summaries']);
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/rielaborazioni/{$r->id}")->assertOk()->assertInertia(fn ($p) => $p->component('Invoices/Reprocess')->has('r.diff.lines', 1));
        $this->post("/aziende/{$this->company->id}/rielaborazioni/{$r->id}/conferma")->assertRedirect();
        $this->post("/aziende/{$this->company->id}/rielaborazioni/{$r->id}/conferma")->assertRedirect(); // secondo clic
        $inv->refresh();
        $this->assertSame(1, $inv->lines()->where('natura', 'N2.2')->count());
        $this->assertSame(2, $inv->lines()->count());
        $this->assertSame($doc, $inv->document_id);
        $this->assertSame(5000, Ledger::settledCents($inv->installments()->first()));
        $this->assertSame(1, InvoiceVersion::where('invoice_id', $inv->id)->count());
        // Poi invariato
        $this->assertSame('invariato', $this->import($xml, 'rielabora')->results[0]['status']);
    }

    public function test_03_correction_1000_to_1100_with_600_paid_and_locked_period(): void
    {
        $this->import($this->purchaseXml('A3', [['rate' => '0.00', 'natura' => 'N2.2', 'taxable' => '1000.00', 'tax' => '0.00']]));
        $inv = Invoice::firstOrFail();
        app(PaymentService::class)->record($this->company, $this->owner, ['kind' => 'pagamento', 'movement_date' => '2026-03-20', 'amount' => '600.00',
            'allocations' => [['installment_id' => $inv->installments()->first()->id, 'amount' => '600.00']]]);
        $res = $this->import($this->purchaseXml('A3', [['rate' => '0.00', 'natura' => 'N2.2', 'taxable' => '1100.00', 'tax' => '0.00']]), 'rielabora', 'v2.xml')->results[0];
        $this->assertSame('modificato', $res['status']);
        app(Reprocessor::class)->apply($this->company, InvoiceReprocessing::findOrFail($res['reprocessing_id']), $this->owner);
        $inst = $inv->fresh()->installments()->first();
        $this->assertSame(['1100.00', 50000], [(string) $inst->amount, Ledger::installmentState($inst)['residual']]);
        $this->assertSame(1, Installment::where('invoice_id', $inv->id)->count());

        // Registrata e periodo liquidato: sovrascrittura diretta bloccata
        $this->register($inv);
        app(SettlementService::class)->confirm($this->company, $this->owner, 2026, 3);
        $res = $this->import($this->purchaseXml('A3', [['rate' => '0.00', 'natura' => 'N2.2', 'taxable' => '1200.00', 'tax' => '0.00']]), 'rielabora', 'v3.xml')->results[0];
        $r = InvoiceReprocessing::findOrFail($res['reprocessing_id']);
        $this->assertTrue($r->booking_relevant);
        try {
            app(Reprocessor::class)->apply($this->company, $r, $this->owner);
            $this->fail('blocco atteso');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Marzo 2026', $e->errors()['reprocessing'][0]);
        }
        $this->assertSame('1100.00', (string) $inv->fresh()->taxable_total);
        $this->assertSame('bloccata', $r->fresh()->status);
        // Percorso di rettifica: storno nel periodo corrente, poi aggiornare
        app(Reprocessor::class)->apply($this->company, $r->fresh(), $this->owner, true);
        $this->assertSame(['1200.00', 'importata'], [(string) $inv->fresh()->taxable_total, $inv->fresh()->status->value]);
    }

    public function test_04_same_batch_file_twice_no_duplicates(): void
    {
        $xml = FatturaBuilder::make()->supplier('IT11111111115', name: 'Fornitore')->customer('IT01234567897')
            ->body('B1', '2026-03-10')->payments([['amount' => '122.00', 'due' => '2026-04-10', 'mode' => 'MP05']])
            ->body('B2', '2026-03-11')->payments([['amount' => '122.00', 'due' => '2026-04-11', 'mode' => 'MP05']])->xml();
        $a = $this->import($xml);
        $b = $this->import($xml);
        $c = $this->import($xml, 'rielabora');
        $this->assertSame(2, Invoice::count());
        $this->assertSame(2, Installment::count());
        $this->assertSame(['importata', 'importata'], array_column($a->results, 'status'));
        $this->assertSame(['gia_presente', 'gia_presente'], array_column($b->results, 'status'));
        $this->assertSame(['invariato', 'invariato'], array_column($c->results, 'status'));
    }

    /** Servizio estero tramite PDF, TD17 22 % con detrazione 50 %. */
    private function germanService(string $amount = '1000'): Invoice
    {
        $this->actingAs($this->owner)->post("/aziende/{$this->company->id}/fatture/nuova", ['direction' => 'acquisto', 'doc_type' => 'TD01', 'number' => 'DE-2026-77',
            'doc_date' => '2026-11-05', 'receipt_date' => '2026-11-10', 'counterparty_name' => 'Berater GmbH', 'counterparty_vat' => 'DE123456789', 'counterparty_country' => 'DE',
            'counterparty_address' => 'Hauptstraße 1', 'counterparty_zip' => '80331', 'counterparty_city' => 'München',
            'lines' => [['description' => 'Consulenza novembre', 'rate' => '0', 'natura' => 'N2.2', 'taxable' => $amount, 'tax' => '']],
            'file' => UploadedFile::fake()->create('rechnung.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();

        return Invoice::where('number', 'DE-2026-77')->firstOrFail();
    }

    private function integrate(Invoice $inv, string $pct = '50'): void
    {
        $acc = $this->company->accounts()->where('type', 'costo')->where('is_default', true)->value('id');
        $this->actingAs($this->owner)->post("/aziende/{$this->company->id}/fatture/{$inv->id}/integra-iva", ['doc_type' => 'TD17', 'kind' => 'servizi', 'doc_date' => '2026-11-10',
            'receipt_date' => '2026-11-10', 'registration_date' => '2026-11-12', 'year' => 2026, 'period' => 11,
            'groups' => [['summary_index' => 0, 'integrate' => true, 'rate' => '22', 'account_id' => $acc, 'deductible_pct' => $pct]]])->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_09_foreign_service_pdf_integration_td17(): void
    {
        $inv = $this->germanService();
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/fatture/{$inv->id}/integra-iva")->assertOk()
            ->assertInertia(fn ($p) => $p->component('Invoices/Integrate')->where('proposal.doc_type', 'TD17')->where('proposal.possible', true));
        $this->integrate($inv);
        $reg = $inv->fresh()->activeRegistration();
        $this->assertSame(['220.00', '110.00'], [(string) $reg->tax_total, (string) $reg->deductible_total]);
        $calc = app(SettlementCalculator::class);
        $calc->forget();
        $f = $calc->compute($this->company, 2026, 11)['figures'];
        $this->assertSame([22000, 11000, 11000], [$f['integrations'], $f['deductible'], $f['own']]);
        // Registro vendite: IVA a debito 220; Registro acquisti: detraibile 110
        $sales = app(VatRegisterService::class)->build($this->company, 'vendite', 2026, 11);
        $this->assertSame('220.00', $sales['sum']['tax']);
        $buys = app(VatRegisterService::class)->build($this->company, 'acquisti', 2026, 11);
        $this->assertSame(['220.00', '110.00'], [$buys['sum']['tax'], $buys['sum']['deductible']]);
        // esattamente un debito verso il fornitore di 1000, nessun credito verso il cliente
        $this->assertSame(['1000.00'], Installment::whereHas('invoice', fn ($q) => $q->where('company_id', $this->company->id))->get()->map(fn ($i) => (string) $i->amount)->all());
        // Operazioni transfrontaliere: termine 15.12., generare l'XML e scaricarlo due volte, nessuna registrazione
        $this->company->update(['indirizzo' => 'Via Roma', 'numero_civico' => '1', 'cap' => '20121', 'comune' => 'Milano', 'provincia' => 'MI', 'nazione' => 'IT']);
        $regs = VatRegistration::count();
        $base = "/aziende/{$this->company->id}/operazioni-transfrontaliere";
        $this->actingAs($this->owner)->get($base)->assertOk()->assertInertia(fn ($p) => $p->component('CrossBorder/Index')
            ->where('rows.0.deadline', '2026-12-15')->where('rows.0.xml_doc_type', 'TD17')->where('rows.0.state', 'integrata'));
        $this->post("{$base}/{$inv->id}/xml")->assertSessionHasNoErrors()->assertSessionMissing('xml_errors');
        $export = XmlExport::sole();
        $this->assertStringContainsString('<TipoDocumento>TD17</TipoDocumento>', $export->content);
        $this->assertStringContainsString('<Imposta>220.00</Imposta>', $export->content);
        $this->post("{$base}/{$inv->id}/xml"); // stesso contenuto: nessuna nuova versione
        $this->get("{$base}/xml/{$export->id}")->assertOk()->assertHeader('Content-Type', 'application/xml');
        $this->get("{$base}/xml/{$export->id}")->assertOk();
        $this->assertSame([1, 2], [XmlExport::count(), $export->fresh()->downloads]);
        $this->assertNotNull($export->fresh()->downloaded_at);
        $this->assertSame($regs, VatRegistration::count());
        $this->assertSame(1, Installment::count());
        $this->get($base)->assertInertia(fn ($p) => $p->where('rows.0.xml.status', 'XML generato'));
        // Seconda integrazione solo dopo storno
        $acc = $this->company->accounts()->where('type', 'costo')->where('is_default', true)->value('id');
        $this->actingAs($this->owner)->post("/aziende/{$this->company->id}/fatture/{$inv->id}/integra-iva", ['doc_type' => 'TD17', 'doc_date' => '2026-11-10', 'receipt_date' => '2026-11-10',
            'registration_date' => '2026-11-12', 'year' => 2026, 'period' => 11, 'groups' => [['summary_index' => 0, 'integrate' => true, 'rate' => '22', 'account_id' => $acc, 'deductible_pct' => '50']]])
            ->assertSessionHasErrors('doc_type');
    }

    public function test_05b_own_sale_in_reverse_charge_does_not_offer_integration(): void
    {
        $this->import(FatturaBuilder::make()->supplier('IT01234567897', name: 'Alpha')->customer('IT11111111115')->body('V1', '2026-03-10', summaries: [['rate' => '0.00', 'natura' => 'N6.3', 'taxable' => '500.00', 'tax' => '0.00']])->xml());
        $inv = Invoice::firstOrFail();
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/fatture/{$inv->id}/integra-iva")->assertInertia(fn ($p) => $p->where('proposal.possible', false));
    }

    public function test_10_non_relevant_foreign_purchase_threshold(): void
    {
        $this->company->update(['indirizzo' => 'Via Roma', 'cap' => '20121', 'comune' => 'Milano', 'provincia' => 'MI']);
        $base = "/aziende/{$this->company->id}/operazioni-transfrontaliere";
        foreach (['5000' => true, '5001' => false] as $amount => $excluded) {
            Invoice::query()->delete();
            $inv = $this->germanService($amount);
            $this->actingAs($this->owner)->post("{$base}/{$inv->id}/classifica", ['kind' => 'servizi', 'relevant' => false, 'customs' => false])->assertSessionHasNoErrors();
            $row = fn ($p) => $p->where('rows.0.state', $excluded ? 'escluso' : 'non_rilevante');
            $this->get($base)->assertInertia($row);
            $this->get("/aziende/{$this->company->id}/fatture/{$inv->id}/integra-iva")->assertInertia(fn ($p) => $p->where('proposal.possible', false));
            if ($excluded) {
                $this->post("{$base}/{$inv->id}/xml")->assertSessionHasErrors('xml');
            } else {
                $this->post("{$base}/{$inv->id}/xml")->assertSessionHasNoErrors()->assertSessionMissing('xml_errors');
                $xml = XmlExport::where('invoice_id', $inv->id)->sole()->content;
                $this->assertStringContainsString('<Natura>N2.2</Natura>', $xml);
                $this->assertStringContainsString('<Imposta>0.00</Imposta>', $xml);
            }
            $this->assertSame(0, VatRegistration::count()); // nessuna IVA italiana, nessuna registrazione
        }
    }

    public function test_10b_xml_errors_point_to_fields(): void
    {
        $inv = $this->germanService();
        $this->integrate($inv);
        $this->actingAs($this->owner)->post("/aziende/{$this->company->id}/operazioni-transfrontaliere/{$inv->id}/xml")->assertSessionHas('xml_errors',
            fn ($e) => collect($e)->contains(fn ($x) => $x['field'] === 'company.indirizzo' && str_ends_with($x['link'], '/dati-azienda')));
        $this->assertSame(0, XmlExport::count());
    }

    public function test_05_magnifier_opens_invoice_and_returns_to_list_state(): void
    {
        $this->import($this->purchaseXml('Z1'));
        $inv = Invoice::firstOrFail();
        $list = "/aziende/{$this->company->id}/acquisti?stato=aperte&q=Forn&ordina=numero&verso=desc&pagina=1&anno=2026";
        $this->actingAs($this->owner)->get($list)->assertInertia(fn ($p) => $p->where('invoices.data.0.id', $inv->id));
        $this->get("/aziende/{$this->company->id}/fatture/{$inv->id}?vista=1&ritorno=".urlencode($list))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Invoices/Show')->where('viewOnly', true)->where('returnTo', $list));
        // l'indirizzo di ritorno estraneo viene scartato
        $this->get("/aziende/{$this->company->id}/fatture/{$inv->id}?vista=1&ritorno=".urlencode('https://evil.example/x'))->assertInertia(fn ($p) => $p->where('returnTo', null));
        $this->assertTrue($inv->fresh()->status->isOpen()); // la visualizzazione non modifica lo stato
    }

    public function test_06_invoice_received_in_new_year_listed_under_receipt_year(): void
    {
        $this->travelTo('2027-01-10 10:00:00');
        $this->actingAs($this->owner)->post("/aziende/{$this->company->id}/fatture/nuova", ['direction' => 'acquisto', 'doc_type' => 'TD01', 'number' => 'Y1', 'doc_date' => '2026-12-28',
            'receipt_date' => '2027-01-04', 'counterparty_name' => 'Fornitore', 'counterparty_vat' => '11111111115', 'lines' => [['rate' => '22', 'natura' => '', 'taxable' => '100', 'tax' => '']]])->assertSessionHasNoErrors();
        $url = "/aziende/{$this->company->id}/acquisti";
        $this->get("{$url}?anno=2027")->assertInertia(fn ($p) => $p->has('invoices.data', 1)->where('invoices.data.0.year', 2027));
        $this->get("{$url}?anno=2026")->assertInertia(fn ($p) => $p->has('invoices.data', 0));
        $this->get("{$url}?anno=tutti")->assertInertia(fn ($p) => $p->has('invoices.data', 1));
    }

    private function registerRows(array $summaries, array $lines): array
    {
        $xml = FatturaBuilder::make()->supplier('IT11111111115', name: 'Fornitore')->customer('IT01234567897')->body('R1', '2026-03-10', summaries: $summaries)->xml();
        // Sostituire DettaglioLinee come da indicazione
        $dl = '';
        foreach ($lines as $i => [$descr, $total, $rate, $natura]) {
            $dl .= '<DettaglioLinee><NumeroLinea>'.($i + 1)."</NumeroLinea><Descrizione>{$descr}</Descrizione><PrezzoUnitario>{$total}</PrezzoUnitario><PrezzoTotale>{$total}</PrezzoTotale><AliquotaIVA>{$rate}</AliquotaIVA>".($natura ? "<Natura>{$natura}</Natura>" : '').'</DettaglioLinee>';
        }
        $xml = preg_replace('#(<DatiBeniServizi>)(?:<DettaglioLinee>.*?</DettaglioLinee>)+#s', '$1'.$dl, $xml);
        $this->import($xml);
        $this->register(Invoice::firstOrFail());

        return app(VatRegisterService::class)->build($this->company, 'acquisti', 2026, 3);
    }

    public function test_07_five_n22_lines_give_five_register_rows(): void
    {
        $lines = array_map(fn ($i) => ["Spesa {$i}", '20.00', '0.00', 'N2.2'], range(1, 5));
        $r = $this->registerRows([['rate' => '0.00', 'natura' => 'N2.2', 'taxable' => '100.00', 'tax' => '0.00']], $lines);
        $rows = collect($r['entries'])->flatMap(fn ($e) => $e['lines'])->filter(fn ($x) => $x['kind'] !== 'arrotondamento');
        $this->assertCount(5, $rows);
        $this->assertSame(['100.00', '0.00'], [$r['sum']['taxable'], $r['sum']['tax']]);
        $this->assertSame(['N2.2'], $rows->pluck('natura')->unique()->values()->all());
    }

    public function test_08_mixed_22_and_n22_gives_two_rows(): void
    {
        $r = $this->registerRows([['rate' => '22.00', 'taxable' => '1000.00', 'tax' => '220.00'], ['rate' => '0.00', 'natura' => 'N2.2', 'taxable' => '100.00', 'tax' => '0.00']],
            [['Consulenza', '1000.00', '22.00', null], ['Bollo', '100.00', '0.00', 'N2.2']]);
        $rows = collect($r['entries'])->flatMap(fn ($e) => $e['lines'])->filter(fn ($x) => $x['kind'] !== 'arrotondamento');
        $this->assertCount(2, $rows);
        $this->assertSame(['1100.00', '220.00'], [$r['sum']['taxable'], $r['sum']['tax']]);
        $this->assertSame(132000, Decimal::toMinor($r['sum']['taxable']) + Decimal::toMinor($r['sum']['tax']));
        // CSV con le stesse righe
        $csv = app(VatRegisterService::class)->csv($this->company, 'acquisti', 2026, null);
        $this->assertSame(2, substr_count($csv, 'R1'));
    }

    public function test_11_transferred_credit_counted_once_with_all_years(): void
    {
        $this->import($this->purchaseXml('C1', [['rate' => '22.00', 'taxable' => '1000.00', 'tax' => '220.00']], '2026-02-10'));
        $this->register(Invoice::firstOrFail(), '2026-02-12', 2);
        $feb = app(SettlementService::class)->confirm($this->company, $this->owner, 2026, 2);
        $c = VatCredit::where('settlement_id', $feb->id)->firstOrFail();
        app(CreditService::class)->allocate($this->company, $this->owner, [['credit_id' => $c->id, 'riporto' => '220.00']]);
        app(SettlementService::class)->confirm($this->company, $this->owner, 2026, 3); // marzo: il credito viene recepito
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/crediti-iva?anno=tutti")->assertInertia(fn ($p) => $p->where('available', '220.00'));
    }

    public function test_05c_domestic_reverse_charge_td16_import_match_and_storno_both_sides(): void
    {
        $this->import($this->purchaseXml('RC1', [['rate' => '0.00', 'natura' => 'N6.3', 'taxable' => '500.00', 'tax' => '0.00']]));
        $inv = Invoice::firstOrFail();
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/fatture/{$inv->id}/integra-iva")->assertInertia(fn ($p) => $p->where('proposal.doc_type', 'TD16'));
        $acc = $this->company->accounts()->where('type', 'costo')->where('is_default', true)->value('id');
        $this->post("/aziende/{$this->company->id}/fatture/{$inv->id}/integra-iva", ['doc_type' => 'TD16', 'doc_date' => '2026-03-12', 'receipt_date' => '2026-03-12',
            'registration_date' => '2026-03-12', 'year' => 2026, 'period' => 3, 'groups' => [['summary_index' => 0, 'integrate' => true, 'rate' => '22', 'account_id' => $acc, 'deductible_pct' => '100']]])
            ->assertSessionHasNoErrors();
        $reg = app(VatRegisterService::class);
        $this->assertSame(['110.00', '110.00'], [$reg->build($this->company, 'vendite', 2026, 3)['sum']['tax'], $reg->build($this->company, 'acquisti', 2026, 3)['sum']['deductible']]);
        // La copia SdI del TD16 viene associata all'integrazione, nessuna seconda fattura
        $td16 = FatturaBuilder::make()->supplier('IT11111111115', name: 'Fornitore')->customer('IT01234567897')->body('1', '2026-03-12', 'TD16', summaries: [['rate' => '22.00', 'taxable' => '500.00', 'tax' => '110.00']])->xml();
        $this->assertSame('integrazione_abbinata', $this->import($td16, name: 'td16.xml')->results[0]['status']);
        $this->assertSame(1, Invoice::count());
        $this->assertNotNull(InvoiceIntegration::sole()->document_id);
        // Lo storno incide su entrambi i registri
        app(RegistrationService::class)->reverse($this->company, $inv->fresh()->activeRegistration(), $this->owner, 'Errore', '2026-03-20');
        $this->assertSame(['0.00', '0.00'], [$reg->build($this->company, 'vendite', 2026, 3)['sum']['tax'], $reg->build($this->company, 'acquisti', 2026, 3)['sum']['deductible']]);
    }

    public function test_dashboard_shows_open_tasks_missing_bookings_and_deadlines(): void
    {
        $this->import(FatturaBuilder::make()->supplier('IT11111111115', name: 'Fornitore')->customer('IT01234567897')
            ->body('D1', '2026-11-02')->payments([['due' => '2026-11-30', 'amount' => '122.00']])->xml());
        $this->germanService();
        // Marzo con registrazione, senza liquidazione → attività; i mesi vuoti non contano
        $this->import($this->purchaseXml('M1'), name: 'm1.xml');
        $this->register(Invoice::where('number', 'M1')->firstOrFail());
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->where('navCounts.liquidazioni', 1)->where('navCounts.da_registrare', 2))->assertInertia(fn ($p) => $p->component('Companies/Dashboard')
            ->where('overview.missing_total', 2)
            ->where('overview.tasks', fn ($t) => collect($t)->pluck('key')->intersect(['da_registrare', 'integrazioni', 'liquidazioni'])->count() === 3)
            ->where('overview.deadlines', fn ($d) => collect($d)->contains(fn ($x) => $x['date'] === '2026-11-30' && $x['kind'] === 'Da pagare')
                && collect($d)->contains(fn ($x) => $x['date'] === '2026-12-15' && $x['kind'] === 'Operazione transfrontaliera')));
    }
}
