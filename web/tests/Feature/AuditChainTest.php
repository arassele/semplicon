<?php

namespace Tests\Feature;

use App\Domain\Audit;
use App\Domain\AuditChain;
use App\Domain\CompanyService;
use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Catena di hash nel registro delle attività (elenco requisiti 1.2). */
class AuditChainTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_events_are_chained_per_company_and_verify(): void
    {
        $u = $this->customer();
        $a = app(CompanyService::class)->create($u, ['name' => 'Alpha S.r.l.', 'tipologia' => 'societa', 'partita_iva' => '01234567897']);
        $b = app(CompanyService::class)->create($u, ['name' => 'Beta S.r.l.', 'tipologia' => 'societa', 'partita_iva' => '11111111115']);
        $e1 = Audit::record('test.one', $u, $a, data: ['z' => 1, 'a' => ['y' => 2, 'b' => 1.5]]);
        $e2 = Audit::record('test.two', $u, $a, reason: 'Prova');
        Audit::record('test.other', $u, $b);

        $this->assertSame($e1->hash, $e2->prev_hash);
        $this->assertTrue(AuditChain::verify($a)['ok']);
        $this->assertTrue(AuditChain::verify($b)['ok']);
        $this->assertSame($e2->hash, AuditChain::verify($a)['head']);
    }

    public function test_tampering_deleting_and_inserting_are_detected(): void
    {
        $u = $this->customer();
        $c = app(CompanyService::class)->create($u, ['name' => 'Alpha S.r.l.', 'tipologia' => 'societa', 'partita_iva' => '01234567897']);
        $x = Audit::record('t.1', $u, $c, reason: 'originale');
        $y = Audit::record('t.2', $u, $c);
        Audit::record('t.3', $u, $c);

        DB::table('audit_events')->where('id', $x->id)->update(['reason' => 'manomesso']);
        $r = AuditChain::verify($c);
        $this->assertFalse($r['ok']);
        $this->assertSame($x->id, $r['broken_id']);

        DB::table('audit_events')->where('id', $x->id)->update(['reason' => 'originale']);
        $this->assertTrue(AuditChain::verify($c)['ok']);

        DB::table('audit_events')->where('id', $y->id)->delete(); // voce rimossa dal mezzo
        $r = AuditChain::verify($c);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('catena', $r['reason']);
    }

    public function test_legacy_events_without_hash_are_counted_not_failed(): void
    {
        $u = $this->customer();
        $c = app(CompanyService::class)->create($u, ['name' => 'Alpha S.r.l.', 'tipologia' => 'societa', 'partita_iva' => '01234567897']);
        DB::table('audit_events')->insert(['company_id' => $c->id, 'action' => 'old', 'created_at' => now()]);
        Audit::record('new', $u, $c);
        $r = AuditChain::verify($c);
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['legacy']);
        $this->assertSame(0, $this->artisan('semplicon:audit-verify', ['company' => $c->id])->run());
        $this->assertSame(1, AuditEvent::where('company_id', $c->id)->whereNull('hash')->count());
    }
}
