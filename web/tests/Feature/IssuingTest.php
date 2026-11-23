<?php

namespace Tests\Feature;

use App\Domain\CompanyService;
use App\Domain\Import\InvoiceImporter;
use App\Domain\Issuing\InvoiceIssuer;
use App\Domain\Issuing\IssuingSettings;
use App\Domain\VatProfileService;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\Installment;
use App\Models\Invoice;
use App\Models\User;
use App\Models\VatRegistration;
use App\Models\XmlExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Emissione fattura elettronica (20.11.2026): sequenza di numerazione, FatturaPA valida, registrazione, rate, rollback. */
class IssuingTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private User $owner;

    private Company $company;

    private int $account;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-11-20 10:00:00');
        $this->owner = $this->customer();
        $this->company = app(CompanyService::class)->create($this->owner, ['name' => 'Alpha S.r.l.', 'tipologia' => 'societa', 'partita_iva' => '01234567897']);
        $this->company->update(['indirizzo' => 'Via Roma', 'numero_civico' => '10', 'cap' => '20121', 'comune' => 'Milano', 'provincia' => 'MI']);
        $svc = app(VatProfileService::class);
        $d = $svc->createDraft($this->company, $this->owner, 2026);
        $svc->updateDraft($this->company, $d, $this->owner, ['regime' => 'semplificata', 'method' => 'cassa', 'periodicity' => 'mensile']);
        $svc->confirm($this->company, $d->fresh(), $this->owner);
        $this->account = (int) $this->company->accounts()->where('type', 'ricavo')->where('iva_rilevante', true)->where('active', true)->orderByDesc('is_default')->value('id');
    }

    private function data(array $over = []): array
    {
        $base = [
            'doc_type' => 'TD01', 'doc_date' => '2026-11-18', 'regime' => 'RF01', 'account_id' => $this->account, 'payment_mode' => 'MP05',
            'customer' => ['kind' => 'azienda', 'name' => 'Cliente Uno S.r.l.', 'vat' => '11111111115', 'country' => 'IT', 'address' => 'Via Verdi 1', 'zip' => '40121', 'city' => 'Bologna', 'province' => 'BO', 'sdi_code' => 'ABC1234'],
            'lines' => [['description' => 'Consulenza', 'quantity' => '10', 'unit' => 'ore', 'price' => '100,00', 'rate' => '22', 'natura' => '']],
            'payments' => [['date' => '2026-12-18', 'amount' => '1220,00']],
        ];
        $d = array_replace_recursive($base, $over);
        foreach (['lines', 'payments'] as $k) {
            if (array_key_exists($k, $over)) {
                $d[$k] = $over[$k]; // sostituire le liste, non unirle
            }
        }

        return $d;
    }

    private function issue(array $over = []): Invoice
    {
        return app(InvoiceIssuer::class)->issue($this->company, $this->owner, $this->data($over));
    }

    public function test_issue_creates_numbered_valid_registered_invoice_with_installments(): void
    {
        $inv = $this->issue();
        $this->assertSame(['1/2026', 'emessa', 'registrata', '1000.00', '220.00'], [$inv->number, $inv->source, $inv->status->value, (string) $inv->taxable_total, (string) $inv->tax_total]);
        $this->assertSame('2/2026', $this->issue(['doc_date' => '2026-11-19'])->number);
        $x = XmlExport::where('invoice_id', $inv->id)->sole();
        $this->assertStringContainsString('<TipoDocumento>TD01</TipoDocumento>', $x->content);
        $this->assertStringContainsString('<CodiceDestinatario>ABC1234</CodiceDestinatario>', $x->content);
        $this->assertStringContainsString('<IBAN>', $x->content.'<IBAN>'); // IBAN solo se l'azienda ha un conto
        $this->assertMatchesRegularExpression('/^IT01234567897_[0-9A-Z]{5}\.xml$/', $x->filename);
        $reg = VatRegistration::where('invoice_id', $inv->id)->sole();
        $this->assertSame([11, '1000.00', '220.00'], [$reg->period, (string) $reg->taxable_total, (string) $reg->tax_total]);
        $this->assertSame([['2026-12-18', '1220.00']], Installment::where('invoice_id', $inv->id)->get()->map(fn ($i) => [$i->due_date->toDateString(), (string) $i->amount])->all());
        // valido rispetto allo schema ufficiale
        $tmp = tempnam(sys_get_temp_dir(), 'fe');
        file_put_contents($tmp, $x->content);
        $dom = new \DOMDocument;
        $dom->load($tmp);
        $this->assertTrue($dom->schemaValidate(config('semplicon.fatturapa_schema')));
    }

    public function test_failure_rolls_back_without_consuming_a_number(): void
    {
        try {
            $this->issue(['account_id' => 999999]);
            $this->fail('conto non valido');
        } catch (ValidationException) {
        }
        $this->assertSame(0, Invoice::count());
        $this->assertSame('1/2026', $this->issue()->number); // il numero 1 è ancora libero
    }

    public function test_blocks_when_company_data_missing_and_date_before_last_invoice(): void
    {
        $this->issue(['doc_date' => '2026-11-19']);
        $this->expectException(ValidationException::class);
        try {
            $this->issue(['doc_date' => '2026-11-10']);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('doc_date', $e->errors());
            $this->company->update(['cap' => '']);
            $this->issue(['doc_date' => '2026-11-20']);
        }
    }

    public function test_exempt_line_with_bollo_and_natura_group(): void
    {
        $inv = $this->issue(['lines' => [['description' => 'Consulenza', 'quantity' => '1', 'price' => '1000,00', 'rate' => '22'], ['description' => 'Esportazione servizio', 'quantity' => '1', 'unit' => 'pz', 'price' => '200,00', 'rate' => '0', 'natura' => 'N4']],
            'bollo' => true, 'ref_normativo' => 'Esente art. 10 DPR 633/72', 'payments' => [['date' => '2026-12-31', 'amount' => '1420,00']]]);
        $xml = XmlExport::where('invoice_id', $inv->id)->sole()->content;
        $this->assertStringContainsString('<BolloVirtuale>SI</BolloVirtuale>', $xml);
        $this->assertStringContainsString('<Natura>N4</Natura>', $xml);
        $this->assertStringContainsString('<RiferimentoNormativo>Esente art. 10 DPR 633/72</RiferimentoNormativo>', $xml);
        $this->assertSame(['1200.00', '220.00'], [(string) $inv->taxable_total, (string) $inv->tax_total]);
    }

    public function test_public_administration_split_payment_uses_fpa12_and_net_payable(): void
    {
        $inv = $this->issue(['customer' => ['name' => 'Comune di Prova', 'vat' => '', 'cf' => '80012345670', 'sdi_code' => 'UFABCD', 'is_pa' => true], 'split' => true,
            'payments' => [['date' => '2026-12-18', 'amount' => '1000,00']]]);
        $xml = XmlExport::where('invoice_id', $inv->id)->sole()->content;
        $this->assertStringContainsString('<FormatoTrasmissione>FPA12</FormatoTrasmissione>', $xml);
        $this->assertStringContainsString('<EsigibilitaIVA>S</EsigibilitaIVA>', $xml);
        $this->assertSame('FPA12', $inv->format);
    }

    public function test_foreign_customer_gets_xxxxxxx_and_credit_note_has_no_payment(): void
    {
        $inv = $this->issue(['customer' => ['name' => 'Kunde GmbH', 'vat' => 'DE123456789', 'country' => 'DE', 'address' => 'Hauptstr. 1', 'zip' => '', 'city' => 'München', 'province' => '', 'sdi_code' => ''],
            'lines' => [['description' => 'Servizio', 'quantity' => '1', 'price' => '500,00', 'rate' => '0', 'natura' => 'N2.1']], 'payments' => [['date' => '2026-12-18', 'amount' => '500,00']]]);
        $xml = XmlExport::where('invoice_id', $inv->id)->sole()->content;
        $this->assertStringContainsString('<CodiceDestinatario>XXXXXXX</CodiceDestinatario>', $xml);
        $this->assertStringContainsString('<CAP>00000</CAP>', $xml);
        $nc = $this->issue(['doc_type' => 'TD04', 'doc_date' => '2026-11-19', 'ref_number' => '1/2026', 'ref_date' => '2026-11-18', 'payments' => []]);
        $this->assertStringContainsString('<DatiFattureCollegate>', XmlExport::where('invoice_id', $nc->id)->sole()->content);
    }

    public function test_reimporting_the_issued_xml_is_recognized_as_already_present(): void
    {
        $inv = $this->issue();
        $xml = XmlExport::where('invoice_id', $inv->id)->sole()->content;
        $batch = ImportBatch::create(['company_id' => $this->company->id, 'created_by' => $this->owner->id, 'status' => 'in_coda', 'mode' => 'salta']);
        $item = ImportItem::create(['import_batch_id' => $batch->id, 'company_id' => $this->company->id, 'filename' => 'x.xml', 'status' => 'in_attesa']);
        app(InvoiceImporter::class)->import($item, $xml, $this->owner->id);
        $this->assertSame('gia_presente', $item->fresh()->results[0]['status']);
        $this->assertSame(1, Invoice::count());
    }

    private function settings(array $s): void
    {
        $this->company->update(['issuing_settings' => $s]);
    }

    public function test_cassa_and_ritenuta_from_company_settings(): void
    {
        $this->settings(['ritenuta' => ['enabled' => true, 'type' => 'RT01', 'rate' => '20.00', 'base_pct' => '100.00', 'causale' => 'A'],
            'casse' => [['type' => 'TC22', 'rate' => '4.00', 'base_pct' => '100.00', 'vat_rate' => '22', 'natura' => '', 'ritenuta' => true, 'cap' => '']]]);
        $inv = $this->issue(['lines' => [['description' => 'Parcella', 'quantity' => '1', 'price' => '1000,00', 'rate' => '22']], 'apply_ritenuta' => true, 'apply_casse' => [true],
            'payments' => [['date' => '2026-12-18', 'amount' => '1060,80']]]);
        // Cassa 4 % = 40, IVA 22 % su 1.040 = 228,80, totale 1.268,80, ritenuta 20 % su 1.040 = 208 → da pagare 1.060,80
        $this->assertSame(['1040.00', '228.80', '1268.80', '208.00'], [(string) $inv->taxable_total, (string) $inv->tax_total, (string) $inv->total_amount, (string) $inv->ritenuta]);
        $xml = XmlExport::where('invoice_id', $inv->id)->sole()->content;
        $this->assertStringContainsString('<TipoCassa>TC22</TipoCassa>', $xml);
        $this->assertStringContainsString('<ImportoContributoCassa>40.00</ImportoContributoCassa>', $xml);
        $this->assertStringContainsString('<Ritenuta>SI</Ritenuta>', $xml);
        $this->assertStringContainsString('<ImportoRitenuta>208.00</ImportoRitenuta>', $xml);
        $this->assertStringContainsString('<CausalePagamento>A</CausalePagamento>', $xml);
        $this->assertStringContainsString('<ImportoTotaleDocumento>1268.80</ImportoTotaleDocumento>', $xml);
        $this->assertSame('1060.80', (string) Installment::where('invoice_id', $inv->id)->sole()->amount);
        $tmp = tempnam(sys_get_temp_dir(), 'fe');
        file_put_contents($tmp, $xml);
        $dom = new \DOMDocument;
        $dom->load($tmp);
        $this->assertTrue($dom->schemaValidate(config('semplicon.fatturapa_schema')));
    }

    public function test_settings_are_only_applied_when_requested_and_wrong_payment_sum_is_rejected(): void
    {
        $this->settings(['ritenuta' => ['enabled' => true, 'type' => 'RT01', 'rate' => '20.00', 'base_pct' => '100.00', 'causale' => 'A'], 'casse' => []]);
        $plain = $this->issue(); // senza apply_ritenuta: nessuna ritenuta
        $this->assertSame('0.00', (string) $plain->ritenuta);
        $this->expectException(ValidationException::class);
        $this->issue(['apply_ritenuta' => true, 'doc_date' => '2026-11-19']); // rata 1.220 invece di 976 (1.220 − 244)
    }

    public function test_two_casse_with_cap_and_two_blocks_in_xml(): void
    {
        $this->settings(['ritenuta' => ['enabled' => false] + IssuingSettings::blank()['ritenuta'], 'casse' => [
            ['type' => 'TC22', 'rate' => '4.00', 'base_pct' => '100.00', 'vat_rate' => '22', 'natura' => '', 'ritenuta' => false, 'cap' => '25.00'],
            ['type' => 'TC01', 'rate' => '2.00', 'base_pct' => '50.00', 'vat_rate' => '0', 'natura' => 'N4', 'ritenuta' => false, 'cap' => ''],
        ]]);
        $inv = $this->issue(['lines' => [['description' => 'Parcella', 'quantity' => '1', 'price' => '1000,00', 'rate' => '22']], 'apply_casse' => [true, true],
            'payments' => [['date' => '2026-12-18', 'amount' => '1260,50']]]);
        // Cassa 1: 4 % = 40, Massimale 25 → 25; Cassa 2: 2 % su 50 % = 10 (esente N4). Imponibile 22 %: 1.025, IVA 225,50; N4: 10
        $xml = XmlExport::where('invoice_id', $inv->id)->sole()->content;
        $this->assertSame(2, substr_count($xml, '<DatiCassaPrevidenziale>'));
        $this->assertStringContainsString('<ImportoContributoCassa>25.00</ImportoContributoCassa>', $xml);
        $this->assertStringContainsString('<ImportoContributoCassa>10.00</ImportoContributoCassa>', $xml);
        $this->assertSame(['1035.00', '225.50'], [(string) $inv->taxable_total, (string) $inv->tax_total]);
    }

    public function test_line_discount_and_exact_totals(): void
    {
        $inv = $this->issue(['lines' => [['description' => 'Articolo', 'quantity' => '3', 'unit' => 'pz', 'price' => '33,33', 'rate' => '22', 'discount' => '10']],
            'payments' => [['date' => '2026-12-18', 'amount' => '109,79']]]);
        // 3 × 33,33 = 99,99; −10 % = 89,991 → 89,99; IVA 22 % = 19,80 (19,7978); totale 109,79
        $this->assertSame(['89.99', '19.80'], [(string) $inv->taxable_total, (string) $inv->tax_total]);
    }

    public function test_acconto_td02_then_final_invoice_deducts_it(): void
    {
        $acc = $this->issue(['doc_type' => 'TD02', 'lines' => [['description' => 'Acconto lavori', 'quantity' => '1', 'price' => '400,00', 'rate' => '22']], 'payments' => [['date' => '2026-11-30', 'amount' => '488,00']]]);
        $this->assertSame('TD02', $acc->doc_type);
        $final = $this->issue(['doc_date' => '2026-11-19', 'acconti' => [$acc->id], 'lines' => [['description' => 'Lavori completi', 'quantity' => '1', 'price' => '1000,00', 'rate' => '22']],
            'payments' => [['date' => '2026-12-30', 'amount' => '732,00']]]);
        // 1.000 − 400 = 600 + IVA 132 = 732
        $this->assertSame(['600.00', '132.00', '732.00'], [(string) $final->taxable_total, (string) $final->tax_total, (string) $final->total_amount]);
        $xml = XmlExport::where('invoice_id', $final->id)->sole()->content;
        $this->assertStringContainsString('<PrezzoUnitario>-400.00</PrezzoUnitario>', $xml);
        $this->assertContains($acc->id, $final->issue_data['deducts']);
        // lo stesso acconto non può essere detratto una seconda volta
        $this->expectException(ValidationException::class);
        $this->issue(['doc_date' => '2026-11-20', 'acconti' => [$acc->id], 'payments' => [['date' => '2026-12-30', 'amount' => '1220,00']]]);
    }

    public function test_quote_endpoint_and_company_settings_page_roundtrip(): void
    {
        $this->settings(IssuingSettings::blank());
        $base = "/aziende/{$this->company->id}";
        $resp = $this->actingAs($this->owner)->put("{$base}/dati-azienda", array_merge($this->company->only(['name', 'partita_iva', 'indirizzo', 'numero_civico', 'cap', 'comune', 'provincia']), ['tipologia' => 'societa',
            'contacts' => [], 'activities' => [], 'bank_accounts' => [],
            'issuing' => ['ritenuta' => ['enabled' => true, 'type' => 'RT01', 'rate' => '20,00', 'base_pct' => '100', 'causale' => 'A'],
                'casse' => [['type' => 'TC22', 'rate' => '4', 'base_pct' => '100', 'vat_rate' => '22', 'natura' => '', 'ritenuta' => true, 'cap' => '']]]]));
        $resp->assertSessionHasNoErrors();
        $fresh = IssuingSettings::for($this->company->fresh());
        $this->assertSame(['20.00', true, 'TC22', '4'], [$fresh['ritenuta']['rate'], $fresh['ritenuta']['enabled'], $fresh['casse'][0]['type'], $fresh['casse'][0]['rate']]);
        $q = $this->post("{$base}/fatture/emetti/anteprima", ['doc_type' => 'TD01', 'lines' => [['description' => 'x', 'quantity' => '1', 'price' => '1000,00', 'rate' => '22']], 'apply_ritenuta' => true, 'apply_casse' => [true]])->assertOk()->json();
        $this->assertSame(['1040.00', '1268.80', '208.00', '1060.80'], [$q['taxable'], $q['total'], $q['ritenuta']['amount'], $q['payable']]);
    }

    public function test_professional_issuer_and_private_customer_produce_schema_valid_xml(): void
    {
        // Libero professionista (persona) come emittente e privato come cliente: Nome/Cognome appartengono ad Anagrafica
        $this->company->update(['tipologia' => 'professionista', 'nome' => 'Giulia', 'cognome' => 'Pixel']);
        $inv = $this->issue(['customer' => ['kind' => 'persona', 'first_name' => 'Mario', 'last_name' => 'Rossi', 'name' => '', 'vat' => '', 'cf' => 'RSSMRA80A01H501U', 'sdi_code' => '']]);
        $x = XmlExport::where('invoice_id', $inv->id)->value('content');
        $this->assertMatchesRegularExpression('#<Anagrafica>\s*<Nome>Giulia</Nome>\s*<Cognome>Pixel</Cognome>\s*</Anagrafica>#', $x);
        $this->assertMatchesRegularExpression('#<Anagrafica>\s*<Nome>Mario</Nome>\s*<Cognome>Rossi</Cognome>\s*</Anagrafica>#', $x);
    }
}
