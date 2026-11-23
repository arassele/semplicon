<?php

namespace Tests\Feature;

use App\Domain\CatalogService;
use App\Models\CatalogArticle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\IssuesInvoices;
use Tests\TestCase;

/** Catalogo articoli, blocchi di testo, unità (elenco requisiti 2.5–2.7). */
class CatalogTest extends TestCase
{
    use BuildsTenants, IssuesInvoices, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
    }

    public function test_article_crud_validation_and_audit(): void
    {
        $b = "/aziende/{$this->company->id}/tabelle/catalogo";
        $this->actingAs($this->owner)->post("$b/articoli", ['code' => 'CONS', 'description' => 'Consulenza oraria', 'unit' => 'ore', 'price' => '100,50', 'rate' => '22']);
        $a = CatalogArticle::sole();
        $this->assertSame(['CONS', '100.50', '22.00'], [$a->code, (string) $a->price, (string) $a->rate]);
        // doppio codice, natura mancante con aliquota 0, prezzo con 3 decimali
        $this->actingAs($this->owner)->post("$b/articoli", ['code' => 'CONS', 'description' => 'x'])->assertSessionHasErrors('code');
        $this->actingAs($this->owner)->post("$b/articoli", ['description' => 'Esente', 'rate' => '0'])->assertSessionHasErrors('natura');
        $this->actingAs($this->owner)->post("$b/articoli", ['description' => 'x', 'price' => '1,234'])->assertSessionHasErrors('price');
        $this->actingAs($this->owner)->post("$b/articoli", ['description' => 'Servizio esente', 'rate' => '22', 'natura' => 'N4', 'price' => '10'])->assertSessionDoesntHaveErrors();
        $n = CatalogArticle::where('natura', 'N4')->sole();
        $this->assertSame('0.00', (string) $n->rate);
        $this->actingAs($this->owner)->put("$b/articoli/{$a->id}", ['code' => 'CONS', 'description' => 'Consulenza', 'price' => '120'])->assertSessionDoesntHaveErrors();
        $this->assertSame('120.00', (string) $a->fresh()->price);
        $this->assertDatabaseHas('audit_events', ['action' => 'catalog.article.created']);
        $this->actingAs($this->owner)->delete("$b/articoli/{$a->id}");
        $this->assertDatabaseMissing('catalog_articles', ['id' => $a->id]);
        $this->actingAs($this->owner)->get($b)->assertOk();
    }

    public function test_other_company_cannot_touch_article_and_snippets_units_work(): void
    {
        $b = "/aziende/{$this->company->id}/tabelle/catalogo";
        $this->actingAs($this->owner)->post("$b/testi", ['title' => 'Forfettario', 'body' => 'Operazione senza IVA ex art. 1 c. 54-89 L. 190/2014', 'kind' => 'causale'])->assertSessionDoesntHaveErrors();
        $this->actingAs($this->owner)->post("$b/testi", ['title' => 'x', 'body' => 'y', 'kind' => 'altro'])->assertSessionHasErrors('kind');
        $this->actingAs($this->owner)->post("$b/unita", ['code' => 'sett', 'label' => 'settimane'])->assertSessionDoesntHaveErrors();
        $this->actingAs($this->owner)->post("$b/unita", ['code' => 'ORE'])->assertSessionHasErrors('code'); // già presente come standard (senza distinzione tra maiuscole e minuscole)
        $this->assertContains('sett', app(CatalogService::class)->units($this->company));

        $other = $this->customer();
        $this->actingAs($other)->get($b)->assertNotFound();
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/fatture/emetti")->assertOk()->assertInertia(fn ($p) => $p->has('catalog.units')->has('catalog.snippets', 1));
    }
}
