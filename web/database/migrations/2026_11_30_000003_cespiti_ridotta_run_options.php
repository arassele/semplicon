<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decisione n. 81: la quota ridotta si imposta nel calcolo (esecuzione, sovrascrivibile per categoria), non più nel cespite.
 * Opzioni dell'esecuzione in asset_depreciation_runs.options; i cespiti con metodo «ridotto» diventano «ordinario» (gli anni confermati restano invariati).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_depreciation_runs', function (Blueprint $t) {
            $t->json('options')->nullable();
        });
        DB::table('assets')->where('method', 'ridotto')->update(['method' => 'ordinario', 'reduced_pct' => null]);
    }

    public function down(): void
    {
        Schema::table('asset_depreciation_runs', function (Blueprint $t) {
            $t->dropColumn('options');
        });
    }
};
