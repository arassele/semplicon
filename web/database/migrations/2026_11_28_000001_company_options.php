<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Funzioni e opzioni per azienda: moduli attivi/disattivi, fonte della data di pagamento nel registro IVA, controllo del codice fiscale all'importazione. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->json('options')->nullable());
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('options'));
    }
};
