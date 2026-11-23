<?php

namespace App\Console\Commands;

use App\Domain\Issuing\RecurringInvoiceService;
use Illuminate\Console\Command;

/** Emette le fatture ricorrenti in scadenza con «emissione automatica» (ogni giorno tramite scheduler). */
class RunRecurringInvoices extends Command
{
    protected $signature = 'semplicon:recurring-run';

    protected $description = 'Emette le fatture ricorrenti scadute con emissione automatica';

    public function handle(RecurringInvoiceService $svc): int
    {
        $r = $svc->runAuto();
        $this->info("Emesse {$r['issued']}, non riuscite {$r['failed']}.");

        return $r['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
