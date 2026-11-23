<?php

namespace App\Console\Commands;

use App\Domain\AuditChain;
use App\Models\Company;
use Illuminate\Console\Command;

/** Verifica la catena di hash del registro delle attività (tutte le aziende o una). Restituisce ≠ 0 se una catena è interrotta. */
class VerifyAuditChain extends Command
{
    protected $signature = 'semplicon:audit-verify {company? : ID dell’azienda (senza: tutte)}';

    protected $description = 'Verifica l’integrità del registro attività (catena di hash)';

    public function handle(): int
    {
        $companies = $this->argument('company') ? Company::whereKey($this->argument('company'))->get() : Company::orderBy('id')->get();
        $bad = 0;
        foreach ($companies as $c) {
            $r = AuditChain::verify($c);
            $this->line(sprintf('#%d %s: %s (%d eventi verificati, %d precedenti alla catena)%s', $c->id, $c->name, $r['ok'] ? 'OK' : 'ERRORE', $r['checked'], $r['legacy'], $r['ok'] ? '' : " — evento {$r['broken_id']}: {$r['reason']}"));
            $bad += $r['ok'] ? 0 : 1;
        }

        return $bad ? self::FAILURE : self::SUCCESS;
    }
}
