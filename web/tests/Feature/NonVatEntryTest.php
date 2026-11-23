<?php

namespace Tests\Feature;

use App\Domain\ImmutableRecordException;
use App\Domain\VatRegisterService;
use App\Models\Company;
use App\Models\NonVatEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Movimenti fuori campo IVA: al di fuori dei Registri IVA, protocollo per anno, storno, allegato. */
class NonVatEntryTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-11-18 10:00:00');
        $this->owner = $this->customer();
        $this->company = $this->companyFor($this->owner, ['tipologia' => 'societa']);
    }

    private function acc(string $code): int
    {
        return $this->company->accounts()->where('code', $code)->value('id');
    }

    private function url(string $s = ''): string
    {
        return "/aziende/{$this->company->id}/fuori-campo{$s}";
    }

    private function payload(array $over = []): array
    {
        return [...['kind' => 'costo', 'entry_date' => '2026-05-04', 'account_id' => $this->acc('609001'), 'description' => 'Imposta di bollo', 'counterparty' => '', 'reference' => 'F24', 'amount' => '16,00'], ...$over];
    }

    public function test_entries_are_numbered_listed_and_kept_out_of_vat_registers(): void
    {
        $this->actingAs($this->owner)->post($this->url(), [...$this->payload(), 'file' => UploadedFile::fake()->create('ricevuta.pdf', 20, 'application/pdf')])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post($this->url(), $this->payload(['entry_date' => '2026-06-30', 'account_id' => $this->acc('604001'), 'description' => 'Stipendi giugno', 'amount' => '1800']))->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post($this->url(), $this->payload(['kind' => 'ricavo', 'account_id' => $this->acc('802001'), 'description' => 'Interessi attivi', 'amount' => '3,25']))->assertSessionHasNoErrors();

        $this->assertSame([1, 2, 3], NonVatEntry::orderBy('id')->pluck('protocol')->all());
        $this->assertSame('pdf', NonVatEntry::first()->document->kind);
        $this->assertDatabaseHas('audit_events', ['action' => 'non_vat.created']);
        $this->actingAs($this->owner)->get($this->url('?anno=2026'))->assertOk()->assertInertia(fn ($p) => $p->component('NonVat/Index')
            ->has('data.entries', 3)->where('data.sum.costo', '1816.00')->where('data.sum.ricavo', '3.25')->where('data.entries.0.document.name', 'ricevuta.pdf'));
        $this->actingAs($this->owner)->get($this->url('?anno=2026&tipo=ricavo'))->assertInertia(fn ($p) => $p->has('data.entries', 1));
        $this->assertSame([], app(VatRegisterService::class)->build($this->company, 'acquisti', 2026, null)['entries']);
        $this->assertTrue($this->company->accounts()->whereKey($this->acc('609001'))->first()->isUsed(), 'conto usato: non eliminabile');
    }

    public function test_validation(): void
    {
        $this->actingAs($this->owner)->post($this->url(), $this->payload(['account_id' => $this->acc('701003')]))->assertSessionHasErrors('account_id');
        $this->actingAs($this->owner)->post($this->url(), $this->payload(['amount' => '0']))->assertSessionHasErrors('amount');
        $this->actingAs($this->owner)->post($this->url(), $this->payload(['amount' => '-5']))->assertSessionHasErrors('amount');
        $this->actingAs($this->owner)->post($this->url(), $this->payload(['entry_date' => '2026-12-01']))->assertSessionHasErrors('entry_date');
        $this->actingAs($this->owner)->post($this->url(), $this->payload(['description' => '']))->assertSessionHasErrors('description');
        $other = $this->companyFor($this->customer('Altro'));
        $this->actingAs($this->owner)->post($this->url(), $this->payload(['account_id' => $other->accounts()->where('code', '609001')->value('id')]))->assertSessionHasErrors('account_id');
        $this->assertSame(0, NonVatEntry::count());
    }

    public function test_reversal_and_immutability(): void
    {
        $this->actingAs($this->owner)->post($this->url(), $this->payload())->assertSessionHasNoErrors();
        $e = NonVatEntry::firstOrFail();
        $this->actingAs($this->owner)->post($this->url("/{$e->id}/storna"), ['reason' => 'Doppio', 'date' => '2026-05-01'])->assertSessionHasErrors('date');
        $this->actingAs($this->owner)->post($this->url("/{$e->id}/storna"), ['reason' => 'Doppio', 'date' => '2026-07-01'])->assertSessionHasNoErrors();
        $this->assertSame('stornato', $e->fresh()->status);
        $s = NonVatEntry::where('status', 'storno')->firstOrFail();
        $this->assertSame([2, -1, $e->id], [$s->protocol, $s->sign, $s->reverses_id]);
        $this->actingAs($this->owner)->get($this->url('?anno=2026'))->assertInertia(fn ($p) => $p->where('data.sum.costo', '0.00'));
        $this->actingAs($this->owner)->post($this->url("/{$e->id}/storna"), ['reason' => 'Ancora', 'date' => '2026-07-02'])->assertSessionHasErrors('reason');

        $this->expectException(ImmutableRecordException::class);
        $s->update(['amount' => '1.00']);
    }

    public function test_other_customer_gets_404(): void
    {
        $this->actingAs($this->owner)->post($this->url(), $this->payload())->assertSessionHasNoErrors();
        $e = NonVatEntry::firstOrFail();
        $stranger = $this->customer('Estraneo');
        $this->actingAs($stranger)->get($this->url())->assertNotFound();
        $this->actingAs($stranger)->post($this->url(), $this->payload())->assertNotFound();
        $this->actingAs($stranger)->post($this->url("/{$e->id}/storna"), ['reason' => 'x', 'date' => '2026-07-01'])->assertNotFound();
    }
}
