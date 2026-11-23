<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Risultati della verifica VIES (prova con numero di richiesta). Le voci vengono solo accodate. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vies_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('country', 2);
            $table->string('vat', 20);
            $table->string('status', 16);                       // valida | non_valida | non_disponibile
            $table->string('name')->nullable();
            $table->text('address')->nullable();
            $table->string('request_identifier', 40)->nullable(); // Numero di richiesta della verifica qualificata
            $table->string('message')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at');
            $table->index(['company_id', 'country', 'vat']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vies_checks');
    }
};
