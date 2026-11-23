<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** I parametri fiscali valgono per anno: righe di riferimento (company_id vuoto) più righe proprie modificabili per azienda e anno. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_parameters', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->index(['company_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_parameters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
