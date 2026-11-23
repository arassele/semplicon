<?php

namespace Tests\Feature;

use App\Domain\AccountRuleService;
use App\Domain\CompanyService;
use App\Domain\Import\InvoiceImporter;
use App\Domain\RegistrationService;
use App\Domain\VatProfileService;
use App\Domain\VatRegisterService;
use App\Models\AccountRule;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\Invoice;
use App\Models\RegistrationRequest;
use App\Models\User;
use App\Models\VatRegistration;
use App\Models\VatRegistrationLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\Support\FatturaBuilder;
use Tests\TestCase;

/** Briefing dello sviluppatore 18.11.2026, ticket 01–07. */
class BriefingTest extends TestCase
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
        $this->company = app(CompanyService::class)->create($this->owner, ['name' => 'Alpha', 'tipologia' => 'societa', 'partita_iva' => '01234567897']);
        $svc = app(VatProfileService::class);
        $d = $svc->createDraft($this->company, $this->owner, 2026);
        $svc->updateDraft($this->company, $d, $this->owner, ['regime' => 'semplificata', 'method' => 'cassa', 'periodicity' => 'trimestrale', 'forfettario_iva' => true]);
        $svc->confirm($this->company, $d->fresh(), $this->owner);
    }

    private function purchase(string $n = 'A1', array $sums = [['rate' => '22.00', 'taxable' => '600.00', 'tax' => '132.00']], string $type = 'TD01'): Invoice
    {
        $batch = ImportBatch::create(['company_id' => $this->company->id, 'created_by' => $this->owner->id, 'status' => 'in_coda']);
        $item = ImportItem::create(['import_batch_id' => $batch->id, 'company_id' => $this->company->id, 'filename' => 'f.xml', 'status' => 'in_attesa']);
        app(InvoiceImporter::class)->import($item, FatturaBuilder::make()->supplier('IT11111111115', name: 'Hosting Alpha')->customer('IT01234567897')->body($n, '2026-02-02', $type, summaries: $sums)->xml(), $this->owner->id);

        return $this->company->invoices()->latest('id')->firstOrFail();
    }

    private function acc(string $code): int
    {
        return $this->company->accounts()->where('code', $code)->value('id');
    }

    private function base(array $over = []): array
    {
        return [...['receipt_date' => '2026-02-02', 'registration_date' => '2026-02-10', 'year' => 2026, 'period' => 1, 'acknowledged' => []], ...$over];
    }

    private function split(string $t1 = '100.00', string $v1 = '22.00', string $t2 = '500.00', string $v2 = '110.00', string $pct2 = '100'): array
    {
        return [['summary' => 0, 'account_id' => $this->acc('602023'), 'taxable' => $t1, 'tax' => $v1, 'deductible_pct' => '100'],
            ['summary' => 0, 'account_id' => $this->acc('601003'), 'taxable' => $t2, 'tax' => $v2, 'deductible_pct' => $pct2]];
    }

    private function url(string $s): string
    {
        return "/aziende/{$this->company->id}{$s}";
    }

    public function test_01_regime_badge_follows_displayed_year(): void
    {
        $svc = app(VatProfileService::class);
        $svc->createDraft($this->company, $this->owner, 2027);
        $get = fn ($y) => $this->actingAs($this->owner)->get($this->url("/profilo-iva?anno={$y}"));
        $get(2026)->assertInertia(fn ($p) => $p->where('currentCompany.regime.label', '2026 · Forfettario con IVA')->where('currentCompany.regime.state', 'confermato'));
        $get(2027)->assertInertia(fn ($p) => $p->where('currentCompany.regime.label', '2027 · Regime IVA in bozza')->where('currentCompany.regime.forfettario_iva', false));
        $get(2028)->assertInertia(fn ($p) => $p->where('currentCompany.regime.label', '2028 · Regime IVA da impostare'));
        // Bozza di correzione accanto al profilo confermato: quello confermato resta determinante.
        $svc->createDraft($this->company, $this->owner, 2026);
        $get(2026)->assertInertia(fn ($p) => $p->where('currentCompany.regime.label', '2026 · Forfettario con IVA'));
    }

    public function test_03_empty_or_out_of_range_percentage_is_rejected(): void
    {
        $inv = $this->purchase();
        foreach (['' => 'Indica la percentuale detraibile (0–100).', '101' => 'Percentuale detraibile tra 0 e 100.'] as $pct => $msg) {
            $this->actingAs($this->owner)->postJson($this->url("/fatture/{$inv->id}/registra"), $this->base(['lines' => [['account_id' => $this->acc('602023'), 'deductible_pct' => (string) $pct]]]))
                ->assertStatus(422)->assertJsonPath('errors', fn ($e) => $e['lines.0.deductible_pct'][0] === $msg);
        }
        $this->assertSame(0, VatRegistration::count());
    }

    public function test_04_group_split_on_two_accounts_and_exact_residual(): void
    {
        $inv = $this->purchase();
        $this->actingAs($this->owner)->postJson($this->url("/fatture/{$inv->id}/registra"), $this->base(['lines' => $this->split('100.00', '22.00', '499.99', '110.00')]))
            ->assertStatus(422)->assertJsonPath('errors', fn ($e) => str_contains($e['groups.0'][0], 'imponibile 0,01'));
        $this->assertSame([0, 0], [VatRegistration::count(), VatRegistrationLine::count()], 'nessuna riga parziale');

        $this->actingAs($this->owner)->postJson($this->url("/fatture/{$inv->id}/registra"), $this->base(['lines' => $this->split()]))->assertOk();
        $reg = VatRegistration::with('lines.account')->firstOrFail();
        $this->assertSame([['602023', '100.00', '22.00', 0], ['601003', '500.00', '110.00', 0]], $reg->lines->map(fn ($l) => [$l->account->code, $l->taxable, $l->tax, $l->summary_index])->all());
        $this->assertSame('132.00', $reg->tax_total);
        $totals = app(VatRegisterService::class)->build($this->company, 'acquisti', 2026, 1)['totals'];
        $this->assertSame('132.00', $totals[0]['tax']);
    }

    public function test_04_split_credit_note_reduces_with_correct_sign(): void
    {
        $nc = $this->purchase('NC1', [['rate' => '22.00', 'taxable' => '600.00', 'tax' => '132.00']], 'TD04');
        $this->actingAs($this->owner)->postJson($this->url("/fatture/{$nc->id}/registra"), $this->base(['lines' => $this->split()]))->assertOk();
        $data = app(VatRegisterService::class)->build($this->company, 'acquisti', 2026, 1);
        $this->assertSame(['-600.00', '-132.00'], [$data['sum']['taxable'], $data['sum']['tax']]);
    }

    public function test_05_preview_uses_the_same_prepared_lines_as_the_booking(): void
    {
        $inv = $this->purchase('A2', [['rate' => '22.00', 'taxable' => '150.00', 'tax' => '33.00']]);
        $lines = [['summary' => 0, 'account_id' => $this->acc('602023'), 'taxable' => '100.00', 'tax' => '22.00', 'deductible_pct' => '100'],
            ['summary' => 0, 'account_id' => $this->acc('601020'), 'taxable' => '50.00', 'tax' => '11.00', 'deductible_pct' => '40']];
        $pv = $this->actingAs($this->owner)->postJson($this->url("/fatture/{$inv->id}/anteprima"), $this->base(['lines' => $lines]))->assertOk()->json();
        $this->assertTrue($pv['ok']);
        $this->assertSame(['150.00', '33.00', '26.40', '6.60'], [$pv['totals']['taxable'], $pv['totals']['tax'], $pv['totals']['deductible'], $pv['totals']['non_deductible']]);
        $this->assertSame(['4.40', '6.60'], [$pv['lines'][1]['deductible'], $pv['lines'][1]['non_deductible']]);
        $this->assertSame(0, VatRegistration::count(), 'l’anteprima non registra');

        $this->actingAs($this->owner)->postJson($this->url("/fatture/{$inv->id}/registra"), $this->base(['lines' => $lines]))->assertOk();
        $this->assertSame('26.40', VatRegistration::firstOrFail()->deductible_total, 'anteprima e registro coincidono al centesimo');

        $nc = $this->purchase('NC12', [['rate' => '22.00', 'taxable' => '-10.00', 'tax' => '-2.20']], 'TD04');
        $pv = $this->actingAs($this->owner)->postJson($this->url("/fatture/{$nc->id}/anteprima"), $this->base(['lines' => [['account_id' => $this->acc('602023'), 'deductible_pct' => '100']],
            'acknowledged' => app(RegistrationService::class)->checks($this->company, $nc)['acknowledge']]))->json();
        $this->assertSame(['-10.00', '-2.20', '-2.20'], [$pv['totals']['taxable'], $pv['totals']['tax'], $pv['totals']['deductible']]);
    }

    public function test_06_same_request_id_is_idempotent_and_conflicts_are_reported(): void
    {
        $inv = $this->purchase();
        $body = $this->base(['lines' => [['account_id' => $this->acc('602023'), 'deductible_pct' => '100']], 'request_id' => 'req-1']);
        $first = $this->actingAs($this->owner)->postJson($this->url("/fatture/{$inv->id}/registra"), $body)->assertOk()->json();
        // Perdita della risposta dopo il commit: stesso tentativo di nuovo → stessa registrazione.
        $again = $this->actingAs($this->owner)->postJson($this->url("/fatture/{$inv->id}/registra"), $body)->assertOk()->json();
        $this->assertTrue($again['replayed']);
        $this->assertSame([$first['registration']['id'], $first['registration']['protocol']], [$again['registration']['id'], $again['registration']['protocol']]);
        $this->assertSame(1, VatRegistration::count());
        // Stesso ID, contenuto diverso → conflitto, nessuna seconda registrazione.
        $this->actingAs($this->owner)->postJson($this->url("/fatture/{$inv->id}/registra"), [...$body, 'notes' => 'diverso'])->assertStatus(409);
        // Seconda sessione con ID diverso → viene indicata la registrazione esistente.
        $other = $this->actingAs($this->owner)->postJson($this->url("/fatture/{$inv->id}/registra"), [...$body, 'request_id' => 'req-2'])->assertStatus(409)->json();
        $this->assertSame($first['registration']['id'], $other['existing']['id']);
        $this->assertSame(1, VatRegistration::count());
        $this->assertSame(1, RegistrationRequest::count());
    }

    public function test_06_error_before_commit_leaves_nothing_and_allows_retry_with_same_id(): void
    {
        $inv = $this->purchase();
        $bad = $this->base(['lines' => [['account_id' => 0, 'deductible_pct' => '100']], 'request_id' => 'req-x']);
        $this->actingAs($this->owner)->postJson($this->url("/fatture/{$inv->id}/registra"), $bad)->assertStatus(422);
        $this->assertSame([0, 0, 0], [VatRegistration::count(), VatRegistrationLine::count(), RegistrationRequest::count()]);
        $this->actingAs($this->owner)->postJson($this->url("/fatture/{$inv->id}/registra"), [...$bad, 'lines' => [['account_id' => $this->acc('602023'), 'deductible_pct' => '100']]])->assertOk();
    }

    public function test_06_reregistration_after_reversal_is_linked(): void
    {
        $inv = $this->purchase();
        $line = [['account_id' => $this->acc('602023'), 'deductible_pct' => '100']];
        $this->actingAs($this->owner)->postJson($this->url("/fatture/{$inv->id}/registra"), $this->base(['lines' => $line, 'request_id' => 'a']))->assertOk();
        $orig = VatRegistration::firstOrFail();
        $storno = app(RegistrationService::class)->reverse($this->company, $orig, $this->owner, 'Errore', '2026-03-01');
        $res = $this->actingAs($this->owner)->postJson($this->url("/fatture/{$inv->id}/registra"), $this->base(['lines' => $line, 'request_id' => 'b']))->assertOk()->json();
        $new = VatRegistration::findOrFail($res['registration']['id']);
        $this->assertSame([$orig->id, $orig->id], [$new->replaces_id, $storno->reverses_id]);
        $this->assertSame([1, 2, 3], VatRegistration::orderBy('id')->pluck('protocol')->all());
    }

    public function test_07_json_confirmation_and_flash_for_next_page(): void
    {
        $a = $this->purchase('A-77');
        $b = $this->purchase('NC-12');
        $res = $this->actingAs($this->owner)->postJson($this->url("/fatture/{$a->id}/registra"), $this->base(['lines' => [['account_id' => $this->acc('602023'), 'deductible_pct' => '100']], 'next' => true]))->assertOk()
            ->assertSessionHas('registered', fn ($r) => $r['invoice'] === 'A-77' && $r['protocol'] === '1/2026' && $r['register'] === 'Registro acquisti' && $r['period'] === '1° trimestre 2026')->json();
        $this->assertStringContainsString("/fatture/{$b->id}?verifica=1", $res['next_url']);
        $this->assertStringContainsString('reg='.$res['registration']['id'], $res['registration']['url']);
        $this->actingAs($this->owner)->get(explode('#', $res['registration']['url'])[0].'&ritorno='.urlencode("/aziende/{$this->company->id}/fatture/{$b->id}?verifica=1"))
            ->assertInertia(fn ($p) => $p->where('highlight', $res['registration']['id'])->where('returnUrl', "/aziende/{$this->company->id}/fatture/{$b->id}?verifica=1"));
        $this->actingAs($this->owner)->get($this->url('/registri/acquisti?ritorno=https://evil.example'))->assertInertia(fn ($p) => $p->where('returnUrl', null));
    }

    /* ---- Ticket 09: regole di imputazione ---- */

    private function withLines(Invoice $inv, array $lines): Invoice
    {
        $inv->lines()->delete();
        $inv->lines()->createMany(array_map(fn ($l, $k) => ['line_no' => $k + 1, 'description' => $l[0], 'unit_price' => $l[1], 'total' => $l[1], 'rate' => '22.00', 'natura' => null], $lines, array_keys($lines)));

        return $inv->fresh();
    }

    private function suggestions(Invoice $inv): array
    {
        return app(AccountRuleService::class)->suggestions($this->company, $inv->fresh());
    }

    public function test_09_confirmed_rule_suggests_account_for_matching_lines_only(): void
    {
        $first = $this->withLines($this->purchase('H1', [['rate' => '22.00', 'taxable' => '100.00', 'tax' => '22.00']]), [['Hosting annuale', '100.00']]);
        $this->actingAs($this->owner)->postJson($this->url("/fatture/{$first->id}/registra"), $this->base([
            'lines' => [['account_id' => $this->acc('602023'), 'deductible_pct' => '50']], 'rules' => [['contains' => 'Hosting', 'account_id' => $this->acc('602023')]],
        ]))->assertOk();
        $rule = AccountRule::firstOrFail();
        $this->assertSame(['IT11111111115', 'acquisti', 'hosting', $this->acc('602023')], [$rule->party_key, $rule->scope, $rule->contains, $rule->account_id]);
        $this->assertArrayNotHasKey('deductible_pct', $rule->getAttributes(), 'la regola salva solo il conto');

        $next = $this->withLines($this->purchase('H2', [['rate' => '22.00', 'taxable' => '30.00', 'tax' => '6.60']]), [['Hosting mensile', '30.00']]);
        $this->assertSame(['type' => 'single', 'account_id' => $this->acc('602023')], array_intersect_key($this->suggestions($next)[0], ['type' => 1, 'account_id' => 1]));
        $this->actingAs($this->owner)->get($this->url("/fatture/{$next->id}"))->assertInertia(fn ($p) => $p->where('registration.rule_suggestions.0.account_id', $this->acc('602023'))
            ->where('registration.rule_party', 'Hosting Alpha')->where('registration.accounts', fn ($a) => collect($a)->firstWhere('code', '602023')['iva_detraibile_pct'] === null));

        $laptop = $this->withLines($this->purchase('H3', [['rate' => '22.00', 'taxable' => '500.00', 'tax' => '110.00']]), [['Laptop 14 pollici', '500.00']]);
        $this->assertSame([], $this->suggestions($laptop), 'altra posizione dello stesso fornitore');

        // Altra azienda: la regola non vale.
        $other = app(CompanyService::class)->create($this->owner, ['name' => 'Beta', 'tipologia' => 'societa', 'partita_iva' => '22222222222']);
        $this->assertSame([], app(AccountRuleService::class)->suggestions($other, $next->fresh()));
    }

    public function test_09_multiple_rules_let_the_user_choose_and_mixed_group_is_split(): void
    {
        foreach ([['hosting', '602023'], ['annuale', '602026'], ['laptop', '601016']] as [$t, $code]) {
            AccountRule::create(['company_id' => $this->company->id, 'party_key' => 'IT11111111115', 'party_name' => 'Hosting Alpha', 'scope' => 'acquisti', 'contains' => $t, 'account_id' => $this->acc($code), 'created_by' => $this->owner->id]);
        }
        $amb = $this->withLines($this->purchase('M1', [['rate' => '22.00', 'taxable' => '100.00', 'tax' => '22.00']]), [['Hosting annuale', '100.00']]);
        $s = $this->suggestions($amb)[0];
        $this->assertSame('choice', $s['type']);
        $this->assertEqualsCanonicalizing([$this->acc('602023'), $this->acc('602026')], $s['accounts']);

        $mixed = $this->withLines($this->purchase('M2'), [['Hosting server', '100.00'], ['Laptop', '500.00']]);
        $s = $this->suggestions($mixed)[0];
        $this->assertSame('split', $s['type']);
        $this->assertSame([[$this->acc('602023'), '100.00', '22.00'], [$this->acc('601016'), '500.00', '110.00']], array_map(fn ($p) => [$p['account_id'], $p['taxable'], $p['tax']], $s['parts']));
    }

    public function test_09_rules_page_and_tenancy(): void
    {
        $rule = AccountRule::create(['company_id' => $this->company->id, 'party_key' => 'IT11111111115', 'party_name' => 'Hosting Alpha', 'scope' => 'acquisti', 'contains' => 'hosting', 'account_id' => $this->acc('602023'), 'created_by' => $this->owner->id]);
        $this->actingAs($this->owner)->get($this->url('/tabelle/regole'))->assertInertia(fn ($p) => $p->component('Tables/Rules')->where('rules.0.contains', 'hosting'));
        $stranger = $this->customer('Estraneo');
        $this->actingAs($stranger)->get($this->url('/tabelle/regole'))->assertNotFound();
        $this->actingAs($stranger)->delete($this->url("/tabelle/regole/{$rule->id}"))->assertNotFound();
        $this->actingAs($this->owner)->delete($this->url("/tabelle/regole/{$rule->id}"))->assertSessionHasNoErrors();
        $this->assertSame(0, AccountRule::count());
    }
}
