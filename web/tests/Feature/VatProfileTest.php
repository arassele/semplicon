<?php

namespace Tests\Feature;

use App\Domain\ImmutableRecordException;
use App\Domain\VatProfileService;
use App\Enums\ProfileStatus;
use App\Enums\SemplificataMethod;
use App\Enums\VatRegime;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\User;
use App\Models\VatProfile;
use App\Support\Validation\PartitaIva;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Accettazione I01: regime IVA per anno solare. Regole vedi docs/FISCAL_RULES.md. */
class VatProfileTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->customer();
        $this->company = $this->companyFor($this->owner);
    }

    private function svc(): VatProfileService
    {
        return app(VatProfileService::class);
    }

    private function draft(int $year, array $data, ?Company $c = null): VatProfile
    {
        $c ??= $this->company;
        $d = $this->svc()->createDraft($c, $this->owner, $year);

        return $this->svc()->updateDraft($c, $d, $this->owner, $data)->fresh();
    }

    private function complete(array $override = []): array
    {
        return [...['regime' => 'semplificata', 'method' => 'cassa', 'periodicity' => 'mensile'], ...$override];
    }

    private function assertRule(callable $fn, string $field): void
    {
        try {
            $fn();
            $this->fail("violazione di regola attesa per {$field}");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    // R-START-01

    public function test_nothing_is_created_or_confirmed_automatically(): void
    {
        $this->assertSame(0, $this->company->vatProfiles()->count());
        $d = $this->draft(2026, $this->complete());
        $this->assertSame(ProfileStatus::Bozza, $d->status);
        $this->assertNull($this->company->activeVatProfile(2026));
    }

    public function test_schema_has_no_amount_or_date_range_inputs(): void
    {
        $columns = Schema::getColumnListing('vat_profiles');
        $this->assertEmpty(array_filter($columns, fn ($c) => preg_match('/amount|importo|fatturato|ricavi|total|soglia/i', $c)));
        // Il regime vale sempre per l'intero anno solare: nessuna data di inizio/fine.
        foreach (['vat_start_date', 'valid_from', 'valid_to'] as $c) {
            $this->assertNotContains($c, $columns);
        }
        $this->assertContains('year', $columns);
    }

    // R-YEAR-01

    public function test_each_year_has_its_own_regime(): void
    {
        $this->svc()->confirm($this->company, $this->draft(2026, $this->complete()), $this->owner);
        $this->svc()->confirm($this->company, $this->draft(2027, $this->complete(['regime' => 'professionisti', 'forfettario_iva' => true])), $this->owner);

        $this->assertSame(VatRegime::Semplificata, $this->company->activeVatProfile(2026)->regime);
        $this->assertSame(SemplificataMethod::Cassa, $this->company->activeVatProfile(2026)->method);
        $this->assertSame(VatRegime::Professionisti, $this->company->activeVatProfile(2027)->regime);
        $this->assertTrue($this->company->activeVatProfile(2027)->forfettario_iva);
        $this->assertNull($this->company->activeVatProfile(2028));
    }

    public function test_year_must_be_in_range(): void
    {
        foreach ([1999, 2101] as $year) {
            $this->assertRule(fn () => $this->svc()->createDraft($this->company, $this->owner, $year), 'year');
        }
        $this->actingAs($this->owner)->post("/aziende/{$this->company->id}/profilo-iva/bozza", ['year' => 'abc'])->assertSessionHasErrors('year');
        $this->assertSame(0, $this->company->vatProfiles()->count());
    }

    public function test_new_year_starts_from_previous_confirmed_year(): void
    {
        $this->svc()->confirm($this->company, $this->draft(2026, $this->complete(['method' => 'competenza', 'forfettario_iva' => true, 'pro_rata' => '72.50'])), $this->owner);

        $d = $this->svc()->createDraft($this->company, $this->owner, 2027);
        $this->assertSame(1, $d->version);
        $this->assertSame(VatRegime::Semplificata, $d->regime);
        $this->assertSame(SemplificataMethod::Competenza, $d->method);
        $this->assertTrue($d->forfettario_iva);
        $this->assertSame('72.50', $d->pro_rata);
        $this->assertSame(ProfileStatus::Bozza, $d->status);
    }

    // R-REG-01, R-REG-02, R-FORF-01

    public function test_there_are_two_main_regimes_and_two_semplificata_variants(): void
    {
        $this->assertSame(['semplificata', 'professionisti'], array_map(fn ($c) => $c->value, VatRegime::cases()));
        $this->assertSame(['competenza', 'cassa'], array_map(fn ($c) => $c->value, SemplificataMethod::cases()));
        $this->assertSame('Regime opzionale senza annotazione incassi/pagamenti (per competenza)', SemplificataMethod::Competenza->label());
        $this->assertSame('Regime naturale con annotazione incassi/pagamenti (per cassa)', SemplificataMethod::Cassa->label());
    }

    public function test_every_valid_combination_can_be_confirmed(): void
    {
        $combinations = [
            ['regime' => 'semplificata', 'method' => 'competenza'],
            ['regime' => 'semplificata', 'method' => 'cassa'],
            ['regime' => 'professionisti', 'method' => null],
        ];
        foreach ($combinations as $combo) {
            foreach ([false, true] as $forfettario) {
                $c = $this->companyFor($this->owner);
                $p = $this->svc()->confirm($c, $this->draft(2026, $this->complete([...$combo, 'forfettario_iva' => $forfettario]), $c), $this->owner);
                $this->assertSame(ProfileStatus::Confermato, $p->status, json_encode($combo));
                $this->assertSame($forfettario, $p->forfettario_iva);
            }
        }
    }

    public function test_semplificata_requires_a_variant(): void
    {
        $d = $this->draft(2026, $this->complete(['method' => null]));
        $this->assertArrayHasKey('method', $this->svc()->blockers($this->company, $d));
        $this->assertRule(fn () => $this->svc()->confirm($this->company, $d, $this->owner), 'method');
    }

    public function test_professionisti_has_no_variant(): void
    {
        $d = $this->draft(2026, $this->complete(['regime' => 'professionisti', 'method' => 'cassa']));
        $this->assertNull($d->method, 'la variante viene rimossa con la contabilità professionisti');
        $this->assertSame('Contabilità professionisti', $d->regimeLabel());
        $this->assertArrayNotHasKey('method', $this->svc()->blockers($this->company, $d));
    }

    public function test_regime_label_includes_variant(): void
    {
        $d = $this->draft(2026, $this->complete(['method' => 'competenza']));
        $this->assertSame('Contabilità semplificata · per competenza', $d->regimeLabel());
    }

    public function test_unknown_regime_or_variant_is_rejected(): void
    {
        $d = $this->svc()->createDraft($this->company, $this->owner, 2026);
        foreach (['semplificata_cassa', 'soli_fini_iva', 'professionista', 'ordinaria'] as $invalid) {
            $this->actingAs($this->owner)->put("/aziende/{$this->company->id}/profilo-iva/{$d->id}", ['regime' => $invalid])->assertSessionHasErrors('regime');
        }
        $this->actingAs($this->owner)->put("/aziende/{$this->company->id}/profilo-iva/{$d->id}", ['method' => 'mista'])->assertSessionHasErrors('method');
        $this->actingAs($this->owner)->put("/aziende/{$this->company->id}/profilo-iva/{$d->id}", ['forfettario_iva' => 'forse'])->assertSessionHasErrors('forfettario_iva');
        $this->assertNull($d->fresh()->regime);
    }

    public function test_forfettario_flag_defaults_to_no(): void
    {
        $d = $this->svc()->createDraft($this->company, $this->owner, 2026);
        $this->assertFalse($d->fresh()->forfettario_iva);
    }

    // R-PROF-01

    public function test_confirmation_requires_regime_and_periodicity(): void
    {
        $d = $this->draft(2026, []);
        $errors = $this->svc()->blockers($this->company, $d);

        foreach (['regime', 'periodicity'] as $field) {
            $this->assertArrayHasKey($field, $errors);
        }
        $this->assertRule(fn () => $this->svc()->confirm($this->company, $d, $this->owner), 'regime');
    }

    public function test_confirmation_records_who_when_and_rule_version(): void
    {
        $p = $this->svc()->confirm($this->company, $this->draft(2026, $this->complete()), $this->owner);

        $this->assertSame($this->owner->id, $p->confirmed_by);
        $this->assertSame(config('semplicon.rule_version'), $p->rule_version);
        $this->assertDatabaseHas('audit_events', ['action' => 'vat_profile.confirmed', 'subject_id' => $p->id]);
    }

    // R-OPS-01, R-SUP-02: nessuna scelta, solo avviso

    public function test_operations_and_unsupported_cases_are_information_only(): void
    {
        $columns = Schema::getColumnListing('vat_profiles');
        $this->assertNotContains('operations', $columns);
        $this->assertNotContains('special_cases', $columns);
        $this->assertSame(['Corrispettivi', 'Reverse charge (anche operazioni con l’estero, intra-UE ed extra-UE)', 'Split payment'], config('semplicon.supported_operations'));
        $this->assertContains('IVA per cassa (art. 32-bis)', config('semplicon.unsupported'));
        $this->assertContains('Regime del margine', config('semplicon.unsupported'));

        $d = $this->svc()->createDraft($this->company, $this->owner, 2026);
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/profilo-iva?anno=2026")
            ->assertInertia(fn ($p) => $p->where('supported', config('semplicon.supported_operations'))->where('unsupported', config('semplicon.unsupported')));
    }

    // R-PRORATA-01

    public function test_pro_rata_defaults_to_100(): void
    {
        $d = $this->svc()->createDraft($this->company, $this->owner, 2026);
        $this->assertSame('100.00', $d->fresh()->pro_rata);
    }

    public function test_pro_rata_below_100_is_allowed_and_kept_exactly(): void
    {
        foreach (['0', '65.5', '80', '99.99'] as $value) {
            $c = $this->companyFor($this->owner);
            $p = $this->svc()->confirm($c, $this->draft(2026, $this->complete(['pro_rata' => $value]), $c), $this->owner);
            $this->assertSame(number_format((float) $value, 2, '.', ''), $p->fresh()->pro_rata, $value);
        }
    }

    public function test_pro_rata_accepts_comma_over_http(): void
    {
        $d = $this->svc()->createDraft($this->company, $this->owner, 2026);
        $this->actingAs($this->owner)->put("/aziende/{$this->company->id}/profilo-iva/{$d->id}", ['pro_rata' => '72,25'])->assertSessionHasNoErrors();
        $this->assertSame('72.25', $d->fresh()->pro_rata);
    }

    public function test_invalid_pro_rata_is_rejected(): void
    {
        $d = $this->svc()->createDraft($this->company, $this->owner, 2026);
        foreach (['100.01', '101', '-5', '12.345', 'abc'] as $bad) {
            $this->actingAs($this->owner)->put("/aziende/{$this->company->id}/profilo-iva/{$d->id}", ['pro_rata' => $bad])->assertSessionHasErrors('pro_rata');
        }
        $this->assertSame('100.00', $d->fresh()->pro_rata);
        $this->assertTrue(VatProfileService::isValidProRata('100'));
        $this->assertFalse(VatProfileService::isValidProRata('100.5'));
    }

    // R-PROF-03..05

    public function test_confirmed_year_is_immutable(): void
    {
        $p = $this->svc()->confirm($this->company, $this->draft(2026, $this->complete()), $this->owner);

        $this->actingAs($this->owner)->put("/aziende/{$this->company->id}/profilo-iva/{$p->id}", ['periodicity' => 'trimestrale'])->assertSessionHasErrors('profile');
        $this->actingAs($this->owner)->delete("/aziende/{$this->company->id}/profilo-iva/{$p->id}")->assertSessionHasErrors('profile');
        $this->assertSame('mensile', $p->fresh()->periodicity->value);

        $this->expectException(ImmutableRecordException::class);
        $p->update(['regime' => 'professionisti']);
    }

    public function test_correction_creates_new_version_of_same_year_and_keeps_history(): void
    {
        $v1 = $this->svc()->confirm($this->company, $this->draft(2026, $this->complete()), $this->owner);

        $v2 = $this->svc()->createDraft($this->company, $this->owner, 2026);
        $this->assertSame(2, $v2->version);
        $this->assertSame(SemplificataMethod::Cassa, $v2->method, 'la bozza recepisce la versione valida');
        $this->assertArrayHasKey('change_reason', $this->svc()->blockers($this->company, $v2));

        $v2 = $this->svc()->updateDraft($this->company, $v2, $this->owner, ['method' => 'competenza', 'change_reason' => 'Opzione per il regime senza annotazione incassi/pagamenti'])->fresh();
        $this->svc()->confirm($this->company, $v2, $this->owner);

        $v1 = $v1->fresh();
        $this->assertSame(ProfileStatus::Sostituito, $v1->status);
        $this->assertSame(SemplificataMethod::Cassa, $v1->method, 'lo stato precedente resta invariato');
        $this->assertSame(SemplificataMethod::Competenza, $this->company->activeVatProfile(2026)->method);
        $this->assertSame(2, $this->company->vatProfiles()->where('year', 2026)->count());
    }

    public function test_only_one_draft_per_year(): void
    {
        $this->svc()->createDraft($this->company, $this->owner, 2026);
        $this->assertRule(fn () => $this->svc()->createDraft($this->company, $this->owner, 2026), 'year');
        // Un altro anno è indipendente da questo.
        $this->assertSame(2027, $this->svc()->createDraft($this->company, $this->owner, 2027)->year);
    }

    public function test_confirming_twice_is_rejected_and_creates_no_duplicate(): void
    {
        $d = $this->draft(2026, $this->complete());
        $url = "/aziende/{$this->company->id}/profilo-iva/{$d->id}/conferma";

        $this->actingAs($this->owner)->post($url)->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post($url)->assertSessionHasErrors('profile');

        $this->assertSame(1, $this->company->vatProfiles()->where('status', 'confermato')->count());
        $this->assertSame(1, AuditEvent::where('action', 'vat_profile.confirmed')->count());
    }

    public function test_full_flow_over_http(): void
    {
        $user = $this->customer('Nuovo');
        $this->actingAs($user)->post('/aziende', ['name' => 'Pixel di Nuovo', 'tipologia' => 'professionista', 'partita_iva' => PartitaIva::complete('0123456789')]);
        $c = $user->companies()->firstOrFail();

        $this->post("/aziende/{$c->id}/profilo-iva/bozza", ['year' => 2027])->assertRedirect("/aziende/{$c->id}/profilo-iva?anno=2027");
        $d = $c->draftVatProfile(2027);
        $this->put("/aziende/{$c->id}/profilo-iva/{$d->id}", [
            'regime' => 'professionisti', 'forfettario_iva' => true, 'periodicity' => 'trimestrale',
            'pro_rata' => '90',
        ])->assertSessionHasNoErrors();
        $this->post("/aziende/{$c->id}/profilo-iva/{$d->id}/conferma")->assertSessionHasNoErrors();

        $this->get("/aziende/{$c->id}/profilo-iva?anno=2027")->assertOk()->assertInertia(fn ($p) => $p
            ->component('Companies/VatProfile')
            ->where('year', 2027)
            ->where('active.regime', 'professionisti')
            ->where('active.regime_label', 'Contabilità professionisti')
            ->where('active.method', null)
            ->where('active.forfettario_iva', true)
            ->where('active.pro_rata', '90.00')
            ->where('draft', null)
            ->has('years', 1)
            ->has('audit', 4));
        $this->get("/aziende/{$c->id}")->assertOk()->assertInertia(fn ($p) => $p->has('years', 1)->where('years.0.year', 2027));
        $this->get("/aziende/{$c->id}/profilo-iva?anno=2027")->assertInertia(fn ($p) => $p->where('options.methods.0.value', 'competenza'));
    }
}
