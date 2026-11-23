<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Briefing 18.11.2026: ticket 04 (ripartizione di un gruppo IVA: assegnazione della riga al riepilogo),
 * ticket 06 (registrazione idempotente tramite requestId, collegamento nuova registrazione ↔ registrazione stornata).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vat_registration_lines', function (Blueprint $table) {
            $table->unsignedSmallInteger('summary_index')->nullable(); // Posizione nel riepilogo della fattura (base 0)
        });
        Schema::table('vat_registrations', function (Blueprint $table) {
            $table->foreignId('replaces_id')->nullable()->constrained('vat_registrations')->nullOnDelete(); // Nuova registrazione dopo storno
        });
        Schema::create('registration_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('request_id', 64);
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->char('payload_hash', 64);
            $table->foreignId('registration_id')->nullable()->constrained('vat_registrations')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['company_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_requests');
        Schema::table('vat_registrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaces_id');
        });
        Schema::table('vat_registration_lines', function (Blueprint $table) {
            $table->dropColumn('summary_index');
        });
    }
};
