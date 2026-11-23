<?php

namespace Tests\Feature;

use App\Domain\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\IssuesInvoices;
use Tests\TestCase;

/** Ricerca rapida (elenco requisiti 9.4): risultati, separazione dei mandanti, lunghezza minima, caratteri LIKE. */
class QuickSearchTest extends TestCase
{
    use BuildsTenants, IssuesInvoices, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
    }

    private function find(string $q, $user = null): array
    {
        return $this->actingAs($user ?? $this->owner)->getJson("/aziende/{$this->company->id}/cerca?q=".rawurlencode($q))->assertOk()->json('results');
    }

    public function test_finds_invoices_counterparties_catalog_and_is_case_insensitive(): void
    {
        $inv = $this->issueInvoice();
        app(CatalogService::class)->saveArticle($this->company, $this->owner, null, ['code' => 'CONS', 'description' => 'Consulenza oraria', 'price' => '100', 'rate' => '22']);
        $kinds = fn (array $r) => array_values(array_unique(array_column($r, 'kind')));

        $r = $this->find('CLIENTE uno');
        $this->assertContains('fattura', $kinds($r));
        $this->assertContains('controparte', $kinds($r));
        $this->assertSame("/aziende/{$this->company->id}/fatture/{$inv->id}", collect($r)->firstWhere('kind', 'fattura')['url']);
        $this->assertStringContainsString('Cliente Uno', collect($r)->firstWhere('kind', 'controparte')['title']);
        $this->assertContains('fattura', $kinds($this->find('11111111115')));   // per partita IVA
        $this->assertContains('fattura', $kinds($this->find('1/2026')));        // per numero
        $this->assertContains('articolo', $kinds($this->find('consul')));
    }

    public function test_short_query_wildcards_and_tenant_isolation(): void
    {
        $this->issueInvoice();
        $this->assertSame([], $this->find('a'));
        $this->assertSame([], $this->find('%%'));    // i segnaposto vengono mascherati, non letti come modello
        $this->assertSame([], $this->find('___'));
        $other = $this->customer();
        $this->actingAs($other)->getJson("/aziende/{$this->company->id}/cerca?q=cliente")->assertNotFound();
        $this->actingAs($this->owner)->getJson("/aziende/{$this->company->id}/cerca?q=".str_repeat('x', 200))->assertOk();
    }
}
