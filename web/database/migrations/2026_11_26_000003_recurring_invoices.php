<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fatture ricorrenti: modello (contenuto del modulo) + ciclo. Si emette con il normale percorso di emissione (numero progressivo, XML, registro). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('frequency', 12);                      // mensile | trimestrale | semestrale | annuale
            $table->date('next_date');
            $table->unsignedTinyInteger('anchor_day');            // Giorno del mese della prima scadenza (31 gen → 28 feb → 31 mar)
            $table->date('end_date')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('auto_issue')->default(false);        // true: semplicon:recurring-run emette automaticamente
            $table->unsignedSmallInteger('payment_days')->default(30);
            $table->json('payload');                              // Contenuto del modulo senza data e rate
            $table->unsignedInteger('runs')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('last_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'active', 'next_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_invoices');
    }
};
