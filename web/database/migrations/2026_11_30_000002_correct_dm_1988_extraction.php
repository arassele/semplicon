<?php

use App\Domain\Cespiti\DepreciationTableImporter;
use App\Models\DepreciationTable;
use Illuminate\Database\Migrations\Migration;

/**
 * Decisione n. 80: correzione dell'estrazione del D.M. 31.12.1988 (righe accorpate nella versione PDF).
 * Integra la sezione «Attività non precedentemente specificate» (Gruppo 23, Specie 1 e 2), separa nel Gruppo XVIII le
 * Specie IV–V da VI–IX e ripulisce il Gruppo XXII Specie II. Nessun cambio normativo, quindi nessun nuovo codice di tabella.
 */
return new class extends Migration
{
    public function up(): void
    {
        $file = database_path('data/dm_1988_coefficienti.csv');
        $t = DepreciationTable::where('code', DepreciationTableImporter::DM1988)->first();
        if (! $t || $t->source_sha256 === hash_file('sha256', $file)) {
            return;
        }
        DepreciationTableImporter::resync($t, $file, 'Corretta il 21/11/2026 l’estrazione dal PDF: aggiunte le attività non precedentemente specificate (senza numero nel decreto, qui gruppo 23).');
    }

    public function down(): void
    {
        // La correzione non viene annullata.
    }
};
