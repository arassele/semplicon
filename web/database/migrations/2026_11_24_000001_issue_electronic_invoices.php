<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Emissione fattura elettronica (indicazione del committente, 20.11.2026): sequenza di numerazione, contatore dei nomi file e dati di emissione. */
return new class extends Migration
{
    public function up(): void
    {
        // Contatore per azienda: scope = anno (numero progressivo della fattura) o «file» (progressivo invio per il nome del file).
        Schema::create('issuing_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 10);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'scope']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->json('issue_data')->nullable();           // Dati inseriti all'emissione (cliente, righe, pagamento, regime) per tracciabilità e suggerimenti
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('issued_by');
            $table->dropColumn(['issue_data', 'issued_at']);
        });
        Schema::dropIfExists('issuing_counters');
    }
};
