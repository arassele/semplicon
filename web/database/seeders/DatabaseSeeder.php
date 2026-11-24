<?php

namespace Database\Seeders;

use App\Domain\CompanyProfileService;
use App\Domain\CompanyService;
use App\Domain\Import\InvoiceImporter;
use App\Domain\VatProfileService;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\User;
use App\Support\Validation\PartitaIva;
use Illuminate\Database\Seeder;
use Tests\Support\FatturaBuilder;

/**
 * Dati demo sintetici (persone, indirizzi inventati, partite IVA con cifra di controllo valida, IBAN di esempio).
 * Solo per sviluppo locale e dimostrazione. Password ovunque "semplicon-demo".
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('Demo-Seeder in produzione non consentito.');

            return;
        }

        User::create(['name' => 'Admin Demo', 'email' => 'admin@semplicon.test', 'password' => 'semplicon-demo', 'is_admin' => true]);
        $giulia = User::create(['name' => 'Jana Pixel', 'email' => 'jana@semplicon.test', 'password' => 'semplicon-demo']);
        $marco = User::create(['name' => 'Marco Rossi', 'email' => 'marco@semplicon.test', 'password' => 'semplicon-demo']);

        $companies = app(CompanyService::class);
        $details = app(CompanyProfileService::class);
        $profiles = app(VatProfileService::class);
        $draft = function ($company, $user, int $year, array $data) use ($profiles) {
            $d = $profiles->createDraft($company, $user, $year);
            $profiles->updateDraft($company, $d, $user, $data);

            return $d->fresh();
        };

        // Professionista, forfettario con IVA: 2026 confermato, 2027 come bozza con pro rata 80 %.
        $pixel = $companies->create($giulia, ['name' => 'Jana Pixel', 'tipologia' => 'professionista', 'partita_iva' => PartitaIva::complete('0123456789')]);
        $details->update($pixel, $giulia, [
            'tipologia' => 'professionista', 'name' => '', 'partita_iva' => $pixel->partita_iva, 'codice_fiscale' => 'PXLJNA90D52A952Y', 'codice_sdi' => 'M5UXCR1',
            'titolo' => 'Dott.ssa', 'cognome' => 'Pixel', 'nome' => 'Jana', 'sesso' => 'F', 'data_nascita' => '1990-04-12',
            'comune_nascita' => 'Bolzano', 'provincia_nascita' => 'BZ',
            'indirizzo' => 'Via dei Portici', 'numero_civico' => '10', 'cap' => '39100', 'comune' => 'Bolzano', 'provincia' => 'BZ',
            'contacts' => [
                ['type' => 'email', 'value' => 'jana@pixel.example', 'is_default' => true],
                ['type' => 'pec', 'value' => 'jana.pixel@pec.example', 'is_default' => false],
                ['type' => 'telefono', 'value' => '0471 000000', 'is_default' => false],
            ],
            'activities' => [['code' => '74.12.01', 'description' => 'Grafica di pagine web', 'is_primary' => true]],
            'bank_accounts' => [['iban' => 'IT60X0542811101000000123456', 'bank_name' => 'Banca di esempio', 'description' => 'Conto principale', 'is_default' => true]],
        ]);
        $profiles->confirm($pixel, $draft($pixel, $giulia, 2026, ['regime' => 'professionisti', 'forfettario_iva' => true, 'periodicity' => 'trimestrale']), $giulia);
        $draft($pixel, $giulia, 2027, ['forfettario_iva' => false, 'pro_rata' => '80.00']);

        // Alcune fatture importate (sintetiche) per provare Verifica e registrazione.
        $batch = ImportBatch::create(['company_id' => $pixel->id, 'created_by' => $giulia->id, 'status' => 'completato']);
        $B = FatturaBuilder::class;
        $samples = [
            'vendita.xml' => $B::make()->supplier('IT'.$pixel->partita_iva, name: 'Jana Pixel')->customer('IT09876543217', name: 'Officine Rinaldi S.n.c.')->body('2026/014', '2026-03-15', total: '1464.00', summaries: [['rate' => '22.00', 'taxable' => '1200.00', 'tax' => '264.00']], description: 'Progettazione grafica sito web')->xml(),
            'hosting.xml' => $B::make()->supplier('IT11111111115', name: 'Hosting Alpha S.r.l.')->customer('IT'.$pixel->partita_iva, name: 'Jana Pixel')->body('A-77', '2026-02-02', summaries: [['rate' => '22.00', 'taxable' => '40.00', 'tax' => '8.80'], ['rate' => '0.00', 'taxable' => '2.00', 'tax' => '0.00', 'natura' => 'N1']], description: 'Hosting annuale')->xml(),
            'auto.xml' => $B::make()->supplier('IT33333333334', name: 'Officina Auto Bolzano')->customer('IT'.$pixel->partita_iva, name: 'Jana Pixel')->body('F-311', '2026-04-08', summaries: [['rate' => '22.00', 'taxable' => '250.00', 'tax' => '55.00']], description: 'Manutenzione automezzo')->xml(),
            'nota.xml' => $B::make()->supplier('IT11111111115', name: 'Hosting Alpha S.r.l.')->customer('IT'.$pixel->partita_iva, name: 'Jana Pixel')->body('NC-12', '2026-02-20', 'TD04', summaries: [['rate' => '22.00', 'taxable' => '10.00', 'tax' => '2.20']], description: 'Storno parziale hosting')->xml(),
        ];
        foreach ($samples as $name => $xml) {
            $item = ImportItem::create(['import_batch_id' => $batch->id, 'company_id' => $pixel->id, 'filename' => $name, 'status' => 'in_attesa']);
            app(InvoiceImporter::class)->import($item, $xml, $giulia->id);
        }
        $batch->update(['files' => 4, 'invoices' => 4]);

        // Società in contabilità semplificata: la variante manca ancora.
        $rinaldi = $companies->create($marco, ['name' => 'Officine Rinaldi S.n.c.', 'tipologia' => 'societa', 'partita_iva' => PartitaIva::complete('0987654321')]);
        $draft($rinaldi, $marco, 2026, ['regime' => 'semplificata', 'periodicity' => 'mensile']);

        // Professionista con pro rata 65,5 %.
        $arch = $companies->create($marco, ['name' => 'Marco Rossi', 'tipologia' => 'professionista', 'partita_iva' => PartitaIva::complete('0555555555')]);
        $draft($arch, $marco, 2026, ['regime' => 'professionisti', 'periodicity' => 'trimestrale', 'pro_rata' => '65.50']);
    }
}
