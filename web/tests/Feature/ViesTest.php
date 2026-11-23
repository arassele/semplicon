<?php

namespace Tests\Feature;

use App\Domain\ViesService;
use App\Models\ViesCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\IssuesInvoices;
use Tests\TestCase;

/** Verifica VIES (elenco requisiti 5.2): normalizzazione, valida/non valida/non raggiungibile, prova, cifra di controllo italiana. */
class ViesTest extends TestCase
{
    use BuildsTenants, IssuesInvoices, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
    }

    public function test_normalize(): void
    {
        $this->assertSame(['country' => 'DE', 'vat' => '123456789'], ViesService::normalize('de', 'DE 123.456.789'));
        $this->assertSame(['country' => 'EL', 'vat' => '123456789'], ViesService::normalize('GR', 'GR123456789'));
        $this->assertSame(['country' => 'FR', 'vat' => 'AB123456789'], ViesService::normalize('FR', 'ab 123456789'));
    }

    public function test_valid_result_is_stored_with_request_identifier_and_requester_sent(): void
    {
        Http::fake(['*' => Http::response(['valid' => true, 'name' => 'ACME GMBH', 'address' => "HAUPTSTR 1\n10115 BERLIN", 'requestIdentifier' => 'WAPIAAAAZ123', 'userError' => 'VALID'], 200)]);
        $url = "/aziende/{$this->company->id}/vies/verifica";
        $this->actingAs($this->owner)->postJson($url, ['country' => 'DE', 'vat' => 'DE 123456789'])->assertOk()
            ->assertJson(['status' => 'valida', 'name' => 'ACME GMBH', 'address' => 'HAUPTSTR 1, 10115 BERLIN', 'request_identifier' => 'WAPIAAAAZ123', 'vat' => '123456789']);
        Http::assertSent(fn ($r) => $r['countryCode'] === 'DE' && $r['vatNumber'] === '123456789' && $r['requesterNumber'] === '01234567897' && $r['requesterMemberStateCode'] === 'IT');
        $this->assertSame(1, ViesCheck::count());
        $this->assertDatabaseHas('audit_events', ['action' => 'vies.checked']);
        $this->actingAs($this->owner)->postJson("/aziende/{$this->company->id}/vies/ultimo", ['country' => 'DE', 'vat' => '123456789'])->assertOk()->assertJson(['status' => 'valida']);
    }

    public function test_invalid_unavailable_and_unreachable_are_distinguished(): void
    {
        $url = "/aziende/{$this->company->id}/vies/verifica";
        $n = 0;
        Http::fake(function () use (&$n) {
            return match ($n++) {
                0 => Http::response(['valid' => false, 'userError' => 'INVALID'], 200),
                1 => Http::response(['valid' => false, 'userError' => 'MS_UNAVAILABLE'], 200),
                2 => Http::response('boom', 500),
                default => throw new ConnectionException('down'),
            };
        });
        $this->actingAs($this->owner)->postJson($url, ['country' => 'FR', 'vat' => 'XX999'])->assertJson(['status' => 'non_valida']);
        $this->actingAs($this->owner)->postJson($url, ['country' => 'FR', 'vat' => 'XX999'])->assertJson(['status' => 'non_disponibile']);
        $this->actingAs($this->owner)->postJson($url, ['country' => 'FR', 'vat' => 'XX999'])->assertJson(['status' => 'non_disponibile']);
        $this->actingAs($this->owner)->postJson($url, ['country' => 'ES', 'vat' => 'B12345678'])->assertJson(['status' => 'non_disponibile']);
        // «non raggiungibile» non conta come ultimo risultato
        $this->actingAs($this->owner)->postJson("/aziende/{$this->company->id}/vies/ultimo", ['country' => 'ES', 'vat' => 'B12345678'])->assertOk()->assertJson([]);
        $this->assertSame(4, ViesCheck::count());
    }

    public function test_italian_checksum_without_network_and_non_eu_country_rejected(): void
    {
        Http::fake();
        $url = "/aziende/{$this->company->id}/vies/verifica";
        $this->actingAs($this->owner)->postJson($url, ['country' => 'IT', 'vat' => '12345678901'])->assertJson(['status' => 'non_valida']);
        Http::assertNothingSent();
        $this->actingAs($this->owner)->postJson($url, ['country' => 'US', 'vat' => '123456789'])->assertStatus(422)->assertJsonValidationErrors('vat');
    }
}
