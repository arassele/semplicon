<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ticket 09: regole di imputazione facoltative per azienda (fornitore/cliente, ambito, condizione testuale → conto). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('party_key', 30);                    // Codice fiscale/partita IVA della controparte (P. IVA con paese o codice fiscale)
            $table->string('party_name', 200);
            $table->string('scope', 8);                         // acquisti | vendite
            $table->string('contains', 100);                    // Condizione testuale, confronto senza distinzione tra maiuscole e minuscole
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['company_id', 'party_key', 'scope', 'contains', 'account_id']);
            $table->index(['company_id', 'party_key', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_rules');
    }
};
