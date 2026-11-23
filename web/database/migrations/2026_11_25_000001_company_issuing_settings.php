<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ritenuta d'acconto e cassa previdenziale si impostano in «Dati azienda» e vengono proposte all'emissione. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->json('issuing_settings')->nullable());
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('issuing_settings'));
    }
};
