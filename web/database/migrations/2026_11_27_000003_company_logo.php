<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Logo dell'azienda (Dati azienda), compare nell'anteprima della fattura e nel PDF. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->string('logo_path', 255)->nullable());
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('logo_path'));
    }
};
