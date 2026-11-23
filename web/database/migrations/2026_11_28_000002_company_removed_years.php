<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Anni contabili eliminati: l'anno corrente non viene più proposto automaticamente fino a una nuova creazione. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->json('removed_years')->nullable());
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('removed_years'));
    }
};
